<?php

namespace Vampires\Sentinels\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Vampires\Sentinels\Core\Context;

/**
 * Event fired when an agent completes execution successfully.
 */
class AgentCompleted
{
    use Dispatchable;

    public readonly float $endTime;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly string $agentName,
        public readonly Context $originalContext,
        public readonly Context $resultContext,
        public readonly float $executionTime,
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
     * Get the execution time in different units.
     */
    public function getExecutionTimeIn(string $unit): float
    {
        return match ($unit) {
            'ms', 'milliseconds' => $this->executionTime,
            's', 'seconds' => $this->executionTime / 1000,
            'μs', 'microseconds' => $this->executionTime * 1000,
            default => $this->executionTime,
        };
    }

    /**
     * Get event data for logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'agent_name' => $this->agentName,
            'correlation_id' => $this->getCorrelationId(),
            'trace_id' => $this->getTraceId(),
            'execution_time_ms' => $this->executionTime,
            'has_errors' => $this->hasErrors(),
            'error_count' => count($this->resultContext->errors),
            'context_tags' => $this->resultContext->tags,
            'end_time' => $this->endTime,
            'event_type' => 'agent_completed',
        ];
    }
}
