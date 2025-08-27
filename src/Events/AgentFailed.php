<?php

namespace Vampires\Sentinels\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Throwable;
use Vampires\Sentinels\Core\Context;

/**
 * Event fired when an agent execution fails.
 */
class AgentFailed
{
    use Dispatchable;

    public readonly float $failureTime;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public readonly string $agentName,
        public readonly Context $context,
        public readonly Throwable $exception,
        public readonly float $executionTime,
        ?float $failureTime = null,
    ) {
        $this->failureTime = $failureTime ?? microtime(true);
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
     * Get the exception class name.
     */
    public function getExceptionClass(): string
    {
        return get_class($this->exception);
    }

    /**
     * Get the exception message.
     */
    public function getExceptionMessage(): string
    {
        return $this->exception->getMessage();
    }

    /**
     * Get the exception code.
     */
    public function getExceptionCode(): int|string
    {
        return $this->exception->getCode();
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
     * Get exception stack trace as array.
     *
     * @return array<array<string, mixed>>
     */
    public function getStackTrace(): array
    {
        return array_map(function ($trace) {
            return [
                'file' => $trace['file'] ?? null,
                'line' => $trace['line'] ?? null,
                'function' => $trace['function'],
                'class' => $trace['class'] ?? null,
                'type' => $trace['type'] ?? null,
            ];
        }, $this->exception->getTrace());
    }

    /**
     * Check if the exception should trigger a retry.
     */
    public function isRetryable(): bool
    {
        // Common retryable exceptions
        $retryableExceptions = [
            \RuntimeException::class,
            \ErrorException::class,
        ];

        foreach ($retryableExceptions as $retryable) {
            if ($this->exception instanceof $retryable) {
                return true;
            }
        }

        return false;
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
            'exception_class' => $this->getExceptionClass(),
            'exception_message' => $this->getExceptionMessage(),
            'exception_code' => $this->getExceptionCode(),
            'exception_file' => $this->exception->getFile(),
            'exception_line' => $this->exception->getLine(),
            'is_retryable' => $this->isRetryable(),
            'context_tags' => $this->context->tags,
            'failure_time' => $this->failureTime,
            'event_type' => 'agent_failed',
        ];
    }
}
