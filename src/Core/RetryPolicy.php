<?php

namespace Vampires\Sentinels\Core;

/**
 * Immutable configuration for retry behavior of agents.
 *
 * Defines how many times to retry, what delays to use,
 * and which exceptions should trigger retries.
 */
readonly class RetryPolicy
{
    public readonly mixed $shouldRetry;

    /**
     * Create a new RetryPolicy.
     *
     * @param int $maxAttempts Maximum number of retry attempts (0 = no retries)
     * @param string $backoffStrategy Strategy for calculating delays ('linear', 'exponential', 'fixed')
     * @param int $baseDelay Base delay in milliseconds
     * @param int $maxDelay Maximum delay in milliseconds
     * @param float $multiplier Multiplier for exponential backoff
     * @param array<string> $retryableExceptions Exception class names that should trigger retries
     * @param array<string> $nonRetryableExceptions Exception class names that should NOT trigger retries
     * @param callable|null $shouldRetry Custom function to determine if retry should happen
     */
    public function __construct(
        public int $maxAttempts,
        public string $backoffStrategy = 'exponential',
        public int $baseDelay = 1000,
        public int $maxDelay = 60000,
        public float $multiplier = 2.0,
        public array $retryableExceptions = [],
        public array $nonRetryableExceptions = [],
        ?callable $shouldRetry = null,
    ) {
        $this->shouldRetry = $shouldRetry;
        if ($this->maxAttempts < 0) {
            throw new \InvalidArgumentException('Max attempts cannot be negative');
        }

        if ($this->baseDelay < 0) {
            throw new \InvalidArgumentException('Base delay cannot be negative');
        }

        if ($this->maxDelay < $this->baseDelay) {
            throw new \InvalidArgumentException('Max delay cannot be less than base delay');
        }

        if (!in_array($this->backoffStrategy, ['linear', 'exponential', 'fixed'])) {
            throw new \InvalidArgumentException('Invalid backoff strategy');
        }
    }

    /**
     * Create a policy with no retries.
     */
    public static function none(): self
    {
        return new self(maxAttempts: 0);
    }

    /**
     * Create a policy with fixed delay retries.
     */
    public static function fixed(int $attempts, int $delay = 1000): self
    {
        return new self(
            maxAttempts: $attempts,
            backoffStrategy: 'fixed',
            baseDelay: $delay,
            maxDelay: $delay,
        );
    }

    /**
     * Create a policy with linear backoff.
     */
    public static function linear(int $attempts, int $baseDelay = 1000, int $maxDelay = 60000): self
    {
        return new self(
            maxAttempts: $attempts,
            backoffStrategy: 'linear',
            baseDelay: $baseDelay,
            maxDelay: $maxDelay,
        );
    }

    /**
     * Create a policy with exponential backoff.
     */
    public static function exponential(
        int $attempts,
        int $baseDelay = 1000,
        int $maxDelay = 60000,
        float $multiplier = 2.0
    ): self {
        return new self(
            maxAttempts: $attempts,
            backoffStrategy: 'exponential',
            baseDelay: $baseDelay,
            maxDelay: $maxDelay,
            multiplier: $multiplier,
        );
    }

    /**
     * Create a default retry policy.
     */
    public static function default(): self
    {
        return self::exponential(3, 1000, 30000, 2.0);
    }

    /**
     * Check if retries are enabled.
     */
    public function hasRetries(): bool
    {
        return $this->maxAttempts > 0;
    }

    /**
     * Calculate the delay for a specific retry attempt.
     *
     * @param int $attempt The attempt number (1-based)
     * @return int Delay in milliseconds
     */
    public function calculateDelay(int $attempt): int
    {
        if ($attempt <= 1) {
            return $this->baseDelay;
        }

        $delay = match ($this->backoffStrategy) {
            'fixed' => $this->baseDelay,
            'linear' => $this->baseDelay * $attempt,
            'exponential' => (int) ($this->baseDelay * pow($this->multiplier, $attempt - 1)),
            default => $this->baseDelay,
        };

        return min($delay, $this->maxDelay);
    }

    /**
     * Get all delays for all retry attempts.
     *
     * @return array<int> Array of delays in milliseconds
     */
    public function getAllDelays(): array
    {
        $delays = [];
        for ($i = 1; $i <= $this->maxAttempts; $i++) {
            $delays[] = $this->calculateDelay($i);
        }

        return $delays;
    }

    /**
     * Get the total time for all retries.
     *
     * @return int Total time in milliseconds
     */
    public function getTotalRetryTime(): int
    {
        return array_sum($this->getAllDelays());
    }

    /**
     * Check if an exception should trigger a retry.
     */
    public function shouldRetryException(\Throwable $exception): bool
    {
        $exceptionClass = get_class($exception);

        // Check non-retryable exceptions first
        foreach ($this->nonRetryableExceptions as $nonRetryable) {
            if ($exception instanceof $nonRetryable) {
                return false;
            }
        }

        // If we have specific retryable exceptions, only retry those
        if (!empty($this->retryableExceptions)) {
            foreach ($this->retryableExceptions as $retryable) {
                if ($exception instanceof $retryable) {
                    return true;
                }
            }

            return false;
        }

        // Default: retry all exceptions except non-retryable ones
        return true;
    }

    /**
     * Check if a retry should happen using custom logic.
     */
    public function shouldRetryWith(Context $context, \Throwable $exception, int $attempt): bool
    {
        // Check attempt limit
        if ($attempt > $this->maxAttempts) {
            return false;
        }

        // Check exception eligibility
        if (!$this->shouldRetryException($exception)) {
            return false;
        }

        // Use custom retry logic if provided
        if ($this->shouldRetry !== null) {
            return call_user_func($this->shouldRetry, $context, $exception, $attempt);
        }

        return true;
    }

    /**
     * Create a new policy with different max attempts.
     */
    public function withMaxAttempts(int $maxAttempts): self
    {
        return new self(
            maxAttempts: $maxAttempts,
            backoffStrategy: $this->backoffStrategy,
            baseDelay: $this->baseDelay,
            maxDelay: $this->maxDelay,
            multiplier: $this->multiplier,
            retryableExceptions: $this->retryableExceptions,
            nonRetryableExceptions: $this->nonRetryableExceptions,
            shouldRetry: $this->shouldRetry,
        );
    }

    /**
     * Create a new policy with different backoff strategy.
     */
    public function withBackoffStrategy(string $strategy): self
    {
        return new self(
            maxAttempts: $this->maxAttempts,
            backoffStrategy: $strategy,
            baseDelay: $this->baseDelay,
            maxDelay: $this->maxDelay,
            multiplier: $this->multiplier,
            retryableExceptions: $this->retryableExceptions,
            nonRetryableExceptions: $this->nonRetryableExceptions,
            shouldRetry: $this->shouldRetry,
        );
    }

    /**
     * Create a new policy with different delays.
     */
    public function withDelays(int $baseDelay, int $maxDelay = null): self
    {
        return new self(
            maxAttempts: $this->maxAttempts,
            backoffStrategy: $this->backoffStrategy,
            baseDelay: $baseDelay,
            maxDelay: $maxDelay ?? $this->maxDelay,
            multiplier: $this->multiplier,
            retryableExceptions: $this->retryableExceptions,
            nonRetryableExceptions: $this->nonRetryableExceptions,
            shouldRetry: $this->shouldRetry,
        );
    }

    /**
     * Create a new policy with additional retryable exceptions.
     */
    public function withRetryableExceptions(array $exceptions): self
    {
        return new self(
            maxAttempts: $this->maxAttempts,
            backoffStrategy: $this->backoffStrategy,
            baseDelay: $this->baseDelay,
            maxDelay: $this->maxDelay,
            multiplier: $this->multiplier,
            retryableExceptions: array_unique(array_merge($this->retryableExceptions, $exceptions)),
            nonRetryableExceptions: $this->nonRetryableExceptions,
            shouldRetry: $this->shouldRetry,
        );
    }

    /**
     * Create a new policy with additional non-retryable exceptions.
     */
    public function withNonRetryableExceptions(array $exceptions): self
    {
        return new self(
            maxAttempts: $this->maxAttempts,
            backoffStrategy: $this->backoffStrategy,
            baseDelay: $this->baseDelay,
            maxDelay: $this->maxDelay,
            multiplier: $this->multiplier,
            retryableExceptions: $this->retryableExceptions,
            nonRetryableExceptions: array_unique(array_merge($this->nonRetryableExceptions, $exceptions)),
            shouldRetry: $this->shouldRetry,
        );
    }

    /**
     * Convert the policy to an array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'backoff_strategy' => $this->backoffStrategy,
            'base_delay' => $this->baseDelay,
            'max_delay' => $this->maxDelay,
            'multiplier' => $this->multiplier,
            'retryable_exceptions' => $this->retryableExceptions,
            'non_retryable_exceptions' => $this->nonRetryableExceptions,
            'has_custom_logic' => $this->shouldRetry !== null,
            'total_retry_time' => $this->getTotalRetryTime(),
            'all_delays' => $this->getAllDelays(),
        ];
    }
}
