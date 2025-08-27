<?php

namespace Vampires\Sentinels\Core;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

/**
 * Asynchronous Context that transparently handles batch execution.
 * 
 * This class extends Context to provide async execution while maintaining
 * the same API. When properties are accessed, it automatically waits for
 * batch completion and returns the aggregated results.
 * 
 * Developers use it exactly like Context - the async behavior is transparent.
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
     * Override payload access to trigger auto-wait.
     */
    public function __get(string $name): mixed
    {
        $resolved = $this->getResolvedContext();
        return $resolved->{$name} ?? parent::__get($name);
    }

    /**
     * Get resolved context (auto-waits if needed).
     */
    protected function getResolvedContext(): Context
    {
        if (!$this->batch) {
            return $this;
        }

        // Check if batch is ready
        $currentBatch = Bus::findBatch($this->batch->id);
        if (!$currentBatch || !$currentBatch->finished()) {
            // Auto-wait for completion
            $this->waitForCompletion();
        }

        // Get final result from cache
        $cacheKey = "sentinels:batch:{$this->batch->id}:final";
        $resolved = Cache::get($cacheKey);
        return $resolved ?: $this->addError('Async pipeline failed to produce results');
    }

    /**
     * Wait for batch completion.
     */
    protected function waitForCompletion(int $timeout = 300): void
    {
        if (!$this->batch) {
            return;
        }

        $startTime = time();
        while ($this->batch && (time() - $startTime) < $timeout) {
            $this->batch = Bus::findBatch($this->batch->id);
            if ($this->batch && $this->batch->finished()) {
                return;
            }
            sleep(1);
        }

        throw new \RuntimeException("Async pipeline timed out after {$timeout} seconds");
    }

    /**
     * Check if the async execution is complete.
     */
    public function isReady(): bool
    {
        if (!$this->batch) {
            return true; // No batch means we're already sync
        }

        // Refresh batch status
        $currentBatch = Bus::findBatch($this->batch->id);
        return $currentBatch ? $currentBatch->finished() : false;
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
        return $currentBatch ? $currentBatch->progress() : 100;
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
        return $this->getResolvedContext();
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

        return [
            'id' => $this->batch->id,
            'name' => $this->batch->name,
            'total_jobs' => $this->batch->totalJobs,
            'pending_jobs' => $this->batch->pendingJobs,
            'processed_jobs' => $this->batch->processedJobs(),
            'failed_jobs' => $this->batch->failedJobs,
            'progress' => $this->batch->progress(),
            'finished' => $this->batch->finished(),
            'cancelled' => $this->batch->cancelled(),
            'created_at' => $this->batch->createdAt,
            'finished_at' => $this->batch->finishedAt,
        ];
    }

    /**
     * Create a new context with different payload.
     */
    public function with(mixed $payload): Context
    {
        // If we have a batch and it's not ready, wait for it
        if ($this->batch && !$this->isReady()) {
            $resolved = $this->getResolvedContext();
            return $resolved->with($payload);
        }

        return parent::with($payload);
    }

    // Removed ensureResolved() - using getResolvedContext() instead

    /**
     * Check if this is an async context.
     */
    public function isAsync(): bool
    {
        return $this->batch !== null;
    }

    /**
     * Proxy method calls to resolved context for transparent API.
     */
    public function __call(string $method, array $arguments): mixed
    {
        $resolved = $this->getResolvedContext();
        
        if (!method_exists($resolved, $method)) {
            throw new \BadMethodCallException("Method {$method} does not exist on Context");
        }
        
        return $resolved->{$method}(...$arguments);
    }

    /**
     * Magic method to handle property access.
     */
    public function __isset(string $name): bool
    {
        $resolved = $this->getResolvedContext();
        return isset($resolved->{$name});
    }
}