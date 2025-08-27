<?php

namespace Vampires\Sentinels\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Vampires\Sentinels\Core\Context;

/**
 * Event fired when an agent starts execution.
 */
class AgentStarted
{
    use Dispatchable;

    public readonly float $startTime;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly string $agentName,
        public readonly Context $context,
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
            'context_tags' => $this->context->tags,
            'start_time' => $this->startTime,
            'event_type' => 'agent_started',
        ];
    }
}
