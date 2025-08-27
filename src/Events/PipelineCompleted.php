<?php

namespace Vampires\Sentinels\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Vampires\Sentinels\Contracts\PipelineContract;
use Vampires\Sentinels\Core\Context;

/**
 * Event fired when a pipeline completes execution.
 */
class PipelineCompleted
{
    use Dispatchable;

    public readonly float $endTime;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly Context $originalContext,
        public readonly Context $resultContext,
        public readonly PipelineContract $pipeline,
        ?float $endTime = null,
    ) {
        $this->endTime = $endTime ?? microtime(true);
    }

    /**
     * Get the correlation ID from the context.
     */
    public function getCorrelationId(): ?string
    {
        return $this->originalContext->correlationId;
    }

    /**
     * Get the trace ID from the context.
     */
    public function getTraceId(): ?string
    {
        return $this->originalContext->traceId;
    }

    /**
     * Check if the result has errors.
     */
    public function hasErrors(): bool
    {
        return $this->resultContext->hasErrors();
    }

    /**
     * Check if the pipeline was cancelled.
     */
    public function wasCancelled(): bool
    {
        return $this->resultContext->isCancelled();
    }

    /**
     * Get pipeline statistics.
     *
     * @return array<string, mixed>
     */
    public function getPipelineStats(): array
    {
        return $this->pipeline->getStats();
    }

    /**
     * Get the total execution time.
     */
    public function getExecutionTime(): float
    {
        $stats = $this->getPipelineStats();

        return $stats['execution_stats']['execution_time'] ?? 0.0;
    }

    /**
     * Get the execution time in different units.
     */
    public function getExecutionTimeIn(string $unit): float
    {
        $time = $this->getExecutionTime();

        return match ($unit) {
            'ms', 'milliseconds' => $time,
            's', 'seconds' => $time / 1000,
            'μs', 'microseconds' => $time * 1000,
            default => $time,
        };
    }

    /**
     * Get event data for logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $stats = $this->getPipelineStats();

        return [
            'correlation_id' => $this->getCorrelationId(),
            'trace_id' => $this->getTraceId(),
            'execution_time_ms' => $this->getExecutionTime(),
            'has_errors' => $this->hasErrors(),
            'was_cancelled' => $this->wasCancelled(),
            'error_count' => count($this->resultContext->errors),
            'context_tags' => $this->resultContext->tags,
            'stage_count' => $stats['stage_count'],
            'pipeline_mode' => $stats['mode'] ?? 'sequential',
            'success' => $stats['execution_stats']['success'] ?? true,
            'end_time' => $this->endTime,
            'event_type' => 'pipeline_completed',
        ];
    }
}
