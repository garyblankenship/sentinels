<?php

namespace Vampires\Sentinels\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Vampires\Sentinels\Contracts\PipelineContract;
use Vampires\Sentinels\Core\Context;

/**
 * Event fired when a pipeline starts execution.
 */
class PipelineStarted
{
    use Dispatchable;

    public readonly float $startTime;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly Context $context,
        public readonly PipelineContract $pipeline,
        ?float $startTime = null,
    ) {
        $this->startTime = $startTime ?? microtime(true);
    }

    /**
     * Get the correlation ID from the context.
     */
    public function getCorrelationId(): ?string
    {
        return $this->context->correlationId;
    }

    /**
     * Get the trace ID from the context.
     */
    public function getTraceId(): ?string
    {
        return $this->context->traceId;
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
            'context_tags' => $this->context->tags,
            'stage_count' => $stats['stage_count'],
            'estimated_time' => $stats['estimated_time'],
            'pipeline_mode' => $stats['mode'] ?? 'sequential',
            'start_time' => $this->startTime,
            'event_type' => 'pipeline_started',
        ];
    }
}
