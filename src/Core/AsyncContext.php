<?php

namespace Vampires\Sentinels\Core;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Handle for a queued pipeline. Call result() or wait() to obtain its final Context.
 * Inherited public properties describe the original submission and do not block.
 */
readonly class AsyncContext extends Context
{
    /**
     * Private constructor to force use of createWithBatch factory.
     */
    private function __construct(
        mixed $payload,
        array $metadata,
        string $correlationId,
        array $tags,
        ?string $traceId,
        bool $cancelled,
        array $errors,
        float $startTime,
        public ?Batch $batch = null
    ) {
        parent::__construct(
            $payload,
            $metadata,
            $correlationId,
            $tags,
            $traceId,
            $cancelled,
            $errors,
            $startTime
        );
    }

    /**
     * Create an AsyncContext with a batch for background processing.
     */
    public static function createWithBatch(
        mixed $originalPayload,
        array $originalMetadata,
        string $correlationId,
        array $tags,
        ?string $traceId,
        bool $cancelled,
        array $errors,
        float $startTime,
        ?Batch $batch
    ): self {
        return new self(
            payload: $originalPayload,
            metadata: $originalMetadata,
            correlationId: $correlationId,
            tags: $tags,
            traceId: $traceId,
            cancelled: $cancelled,
            errors: $errors,
            startTime: $startTime,
            batch: $batch
        );
    }

    /**
     * Resolve the queued result. Inherited public properties cannot be intercepted.
     */
    public function result(int $timeout = 300): Context
    {
        return $this->wait($timeout);
    }

    protected function getResolvedContext(): Context
    {
        return $this->wait();
    }

    protected function waitForCompletion(int $timeout = 300): void
    {
        if (!$this->batch) {
            return;
        }

        $deadline = microtime(true) + max(0, $timeout);
        do {
            if (Cache::get("sentinels:batch:{$this->batch->id}:final") instanceof Context) {
                return;
            }

            $currentBatch = Bus::findBatch($this->batch->id);
            if (!$currentBatch) {
                throw new \RuntimeException('Async pipeline batch is no longer available');
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(100000);
        } while (true);

        throw new \RuntimeException("Async pipeline result unavailable after {$timeout} seconds");
    }

    /**
     * Check if the async execution is complete.
     */
    public function isReady(): bool
    {
        if (!$this->batch) {
            return true; // No batch means we're already sync
        }

        // A finished batch is ready only after its callback has stored the final result.
        return Cache::get("sentinels:batch:{$this->batch->id}:final") instanceof Context;
    }

    /**
     * Get execution progress (0-100).
     */
    public function getProgress(): int
    {
        if (!$this->batch) {
            return 100;
        }

        // Refresh batch status
        $currentBatch = Bus::findBatch($this->batch->id);

        return $currentBatch ? $currentBatch->progress() : 0;
    }

    /**
     * Explicitly wait for async execution to complete.
     *
     * @param int $timeout Maximum time to wait in seconds
     * @return Context The resolved context
     */
    public function wait(int $timeout = 300): Context
    {
        if (!$this->batch) {
            return $this;
        }

        $this->waitForCompletion($timeout);
        $resolved = Cache::get("sentinels:batch:{$this->batch->id}:final");
        if (!$resolved instanceof Context) {
            throw new \RuntimeException('Async pipeline failed to produce results');
        }

        return $resolved;
    }

    /**
     * Get the batch ID for monitoring purposes.
     */
    public function getBatchId(): ?string
    {
        return $this->batch?->id;
    }

    /**
     * Get batch statistics for monitoring.
     */
    public function getBatchStats(): ?array
    {
        if (!$this->batch) {
            return null;
        }

        $currentBatch = Bus::findBatch($this->batch->id);
        if (!$currentBatch) {
            return null;
        }

        return [
            'id' => $currentBatch->id,
            'name' => $currentBatch->name,
            'total_jobs' => $currentBatch->totalJobs,
            'pending_jobs' => $currentBatch->pendingJobs,
            'processed_jobs' => $currentBatch->processedJobs(),
            'failed_jobs' => $currentBatch->failedJobs,
            'progress' => $currentBatch->progress(),
            'finished' => $currentBatch->finished(),
            'cancelled' => $currentBatch->cancelled(),
            'created_at' => $currentBatch->createdAt,
            'finished_at' => $currentBatch->finishedAt,
        ];
    }

    /**
     * Create a new context with different payload.
     */
    public function with(mixed $payload): Context
    {
        return $this->batch ? $this->wait()->with($payload) : parent::with($payload);
    }

    /**
     * Check if this is an async context.
     */
    public function isAsync(): bool
    {
        return $this->batch !== null;
    }

    /**
     * Resolve dynamic metadata access. Public Context properties remain submission data.
     */
    public function __get(string $name): mixed
    {
        return $this->batch ? $this->wait()->getMetadata($name) : $this->getMetadata($name);
    }

    public function __isset(string $name): bool
    {
        return $this->batch ? $this->wait()->getMetadata($name) !== null : isset($this->metadata[$name]);
    }
}
