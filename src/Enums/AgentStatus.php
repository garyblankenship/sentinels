<?php

namespace Vampires\Sentinels\Enums;

/**
 * Represents the execution status of an agent.
 */
enum AgentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';

    /**
     * Check if this status represents a terminal state.
     *
     * Terminal states are final and should not transition further.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Timeout, self::Cancelled => true,
            self::Pending, self::Running, self::Skipped => false,
        };
    }

    /**
     * Check if this status represents a successful state.
     */
    public function isSuccessful(): bool
    {
        return match ($this) {
            self::Completed => true,
            default => false,
        };
    }

    /**
     * Check if this status represents an error state.
     */
    public function isError(): bool
    {
        return match ($this) {
            self::Failed, self::Timeout, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Check if this status represents an active state.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Running => true,
            default => false,
        };
    }

    /**
     * Get the human-readable description of this status.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Pending => 'Agent is waiting to execute',
            self::Running => 'Agent is currently executing',
            self::Completed => 'Agent executed successfully',
            self::Failed => 'Agent execution failed with an error',
            self::Timeout => 'Agent execution exceeded timeout limit',
            self::Skipped => 'Agent execution was skipped',
            self::Cancelled => 'Agent execution was cancelled',
        };
    }

    /**
     * Get the color associated with this status (for UI/logging).
     */
    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Running => 'blue',
            self::Completed => 'green',
            self::Failed => 'red',
            self::Timeout => 'orange',
            self::Skipped => 'gray',
            self::Cancelled => 'purple',
        };
    }

    /**
     * Get all terminal statuses.
     *
     * @return array<AgentStatus>
     */
    public static function terminal(): array
    {
        return [
            self::Completed,
            self::Failed,
            self::Timeout,
            self::Cancelled,
        ];
    }

    /**
     * Get all error statuses.
     *
     * @return array<AgentStatus>
     */
    public static function errors(): array
    {
        return [
            self::Failed,
            self::Timeout,
            self::Cancelled,
        ];
    }
}
