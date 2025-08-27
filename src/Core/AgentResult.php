<?php

namespace Vampires\Sentinels\Core;

use Vampires\Sentinels\Enums\AgentStatus;

/**
 * Immutable result object returned by agent execution.
 *
 * Contains all information about an agent's execution including
 * status, performance metrics, and any errors that occurred.
 */
readonly class AgentResult
{
    /**
     * Create a new AgentResult.
     *
     * @param bool $success Whether the agent executed successfully
     * @param mixed $output The output/result from the agent
     * @param float $executionTime Execution time in milliseconds
     * @param int $memoryUsage Peak memory usage in bytes
     * @param AgentStatus $status The execution status
     * @param string|null $error Error message if execution failed
     * @param array<string, mixed> $metadata Additional execution metadata
     * @param string|null $agentName The name of the executed agent
     * @param float|null $startTime When execution started (microtime)
     * @param float|null $endTime When execution ended (microtime)
     */
    public function __construct(
        public bool $success,
        public mixed $output,
        public float $executionTime,
        public int $memoryUsage,
        public AgentStatus $status,
        public ?string $error = null,
        public array $metadata = [],
        public ?string $agentName = null,
        public ?float $startTime = null,
        public ?float $endTime = null,
    ) {
    }

    /**
     * Create a successful result.
     */
    public static function success(
        mixed $output,
        float $executionTime = 0.0,
        int $memoryUsage = 0,
        array $metadata = [],
        ?string $agentName = null
    ): self {
        return new self(
            success: true,
            output: $output,
            executionTime: $executionTime,
            memoryUsage: $memoryUsage,
            status: AgentStatus::Completed,
            metadata: $metadata,
            agentName: $agentName,
        );
    }

    /**
     * Create a failed result.
     */
    public static function failure(
        string $error,
        mixed $output = null,
        float $executionTime = 0.0,
        int $memoryUsage = 0,
        AgentStatus $status = AgentStatus::Failed,
        array $metadata = [],
        ?string $agentName = null
    ): self {
        return new self(
            success: false,
            output: $output,
            executionTime: $executionTime,
            memoryUsage: $memoryUsage,
            status: $status,
            error: $error,
            metadata: $metadata,
            agentName: $agentName,
        );
    }

    /**
     * Create a timeout result.
     */
    public static function timeout(
        float $executionTime,
        int $memoryUsage = 0,
        mixed $output = null,
        array $metadata = [],
        ?string $agentName = null
    ): self {
        return new self(
            success: false,
            output: $output,
            executionTime: $executionTime,
            memoryUsage: $memoryUsage,
            status: AgentStatus::Timeout,
            error: 'Agent execution exceeded timeout limit',
            metadata: $metadata,
            agentName: $agentName,
        );
    }

    /**
     * Create a cancelled result.
     */
    public static function cancelled(
        float $executionTime = 0.0,
        int $memoryUsage = 0,
        mixed $output = null,
        array $metadata = [],
        ?string $agentName = null
    ): self {
        return new self(
            success: false,
            output: $output,
            executionTime: $executionTime,
            memoryUsage: $memoryUsage,
            status: AgentStatus::Cancelled,
            error: 'Agent execution was cancelled',
            metadata: $metadata,
            agentName: $agentName,
        );
    }

    /**
     * Create a skipped result.
     */
    public static function skipped(
        string $reason = 'Agent execution was skipped',
        array $metadata = [],
        ?string $agentName = null
    ): self {
        return new self(
            success: true, // Skipped is considered successful
            output: null,
            executionTime: 0.0,
            memoryUsage: 0,
            status: AgentStatus::Skipped,
            error: $reason,
            metadata: $metadata,
            agentName: $agentName,
        );
    }

    /**
     * Check if the result represents a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Check if the result represents an error state.
     */
    public function isError(): bool
    {
        return $this->status->isError();
    }

    /**
     * Get a metadata value by key.
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Check if metadata key exists.
     */
    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    /**
     * Get execution time in different units.
     */
    public function getExecutionTimeIn(string $unit): float
    {
        return match ($unit) {
            'ms', 'milliseconds' => $this->executionTime,
            's', 'seconds' => $this->executionTime / 1000,
            'μs', 'microseconds' => $this->executionTime * 1000,
            default => throw new \InvalidArgumentException("Invalid time unit: {$unit}"),
        };
    }

    /**
     * Get memory usage in different units.
     */
    public function getMemoryUsageIn(string $unit): float
    {
        return match ($unit) {
            'bytes', 'b' => $this->memoryUsage,
            'kb', 'kilobytes' => $this->memoryUsage / 1024,
            'mb', 'megabytes' => $this->memoryUsage / (1024 * 1024),
            'gb', 'gigabytes' => $this->memoryUsage / (1024 * 1024 * 1024),
            default => throw new \InvalidArgumentException("Invalid memory unit: {$unit}"),
        };
    }

    /**
     * Get a human-readable summary of the result.
     */
    public function getSummary(): string
    {
        $status = $this->status->value;
        $time = number_format($this->executionTime, 2);
        $memory = $this->getMemoryUsageIn('kb');
        $agent = $this->agentName ?? 'Unknown Agent';

        if (!$this->success && $this->error) {
            return "{$agent} {$status} in {$time}ms ({$memory}KB): {$this->error}";
        }

        return "{$agent} {$status} in {$time}ms ({$memory}KB)";
    }

    /**
     * Convert the result to an array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'output' => $this->output,
            'execution_time' => $this->executionTime,
            'memory_usage' => $this->memoryUsage,
            'status' => $this->status->value,
            'error' => $this->error,
            'metadata' => $this->metadata,
            'agent_name' => $this->agentName,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'summary' => $this->getSummary(),
        ];
    }

    /**
     * Create a result from an array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            success: $data['success'],
            output: $data['output'],
            executionTime: $data['execution_time'],
            memoryUsage: $data['memory_usage'],
            status: AgentStatus::from($data['status']),
            error: $data['error'] ?? null,
            metadata: $data['metadata'] ?? [],
            agentName: $data['agent_name'] ?? null,
            startTime: $data['start_time'] ?? null,
            endTime: $data['end_time'] ?? null,
        );
    }
}
