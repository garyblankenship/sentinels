<?php

namespace Vampires\Sentinels\Enums;

/**
 * Represents different execution modes for pipelines.
 */
enum PipelineMode: string
{
    case Sequential = 'sequential';
    case Parallel = 'parallel';
    case Conditional = 'conditional';
    case MapReduce = 'map_reduce';

    /**
     * Get the human-readable description of this mode.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::Sequential => 'Execute agents one after another in order',
            self::Parallel => 'Execute compatible agents simultaneously',
            self::Conditional => 'Execute agents based on conditional logic',
            self::MapReduce => 'Process collections with map and reduce operations',
        };
    }

    /**
     * Check if this mode supports parallel execution.
     */
    public function supportsParallelism(): bool
    {
        return match ($this) {
            self::Parallel, self::MapReduce => true,
            self::Sequential, self::Conditional => false,
        };
    }

    /**
     * Check if this mode requires special handling.
     */
    public function requiresSpecialHandling(): bool
    {
        return match ($this) {
            self::Conditional, self::MapReduce => true,
            self::Sequential, self::Parallel => false,
        };
    }

    /**
     * Get the recommended timeout multiplier for this mode.
     *
     * Different modes may need different timeout considerations.
     */
    public function getTimeoutMultiplier(): float
    {
        return match ($this) {
            self::Sequential => 1.0,
            self::Parallel => 0.6, // Parallel execution should be faster
            self::Conditional => 1.2, // May have branching overhead
            self::MapReduce => 1.5, // Collection processing may take longer
        };
    }

    /**
     * Get the expected performance characteristics.
     *
     * @return array{
     *     throughput: string,
     *     latency: string,
     *     resource_usage: string,
     *     complexity: string
     * }
     */
    public function getPerformanceCharacteristics(): array
    {
        return match ($this) {
            self::Sequential => [
                'throughput' => 'low',
                'latency' => 'predictable',
                'resource_usage' => 'low',
                'complexity' => 'simple',
            ],
            self::Parallel => [
                'throughput' => 'high',
                'latency' => 'low',
                'resource_usage' => 'high',
                'complexity' => 'medium',
            ],
            self::Conditional => [
                'throughput' => 'variable',
                'latency' => 'variable',
                'resource_usage' => 'low-medium',
                'complexity' => 'medium',
            ],
            self::MapReduce => [
                'throughput' => 'high',
                'latency' => 'high',
                'resource_usage' => 'high',
                'complexity' => 'high',
            ],
        };
    }

    /**
     * Get configuration keys specific to this mode.
     *
     * @return array<string>
     */
    public function getConfigKeys(): array
    {
        return match ($this) {
            self::Sequential => ['timeout', 'retry_attempts', 'retry_delay'],
            self::Parallel => ['timeout', 'max_workers', 'chunk_size'],
            self::Conditional => ['timeout', 'max_depth', 'retry_attempts'],
            self::MapReduce => ['timeout', 'max_workers', 'chunk_size', 'reduce_timeout'],
        };
    }

    /**
     * Check if this mode is suitable for the given context.
     *
     * @param mixed $payload The payload to be processed
     * @param int $stageCount Number of pipeline stages
     * @return bool Whether this mode is suitable
     */
    public function isSuitableFor(mixed $payload, int $stageCount): bool
    {
        return match ($this) {
            self::Sequential => true, // Always suitable
            self::Parallel => $stageCount >= 2, // Need multiple stages
            self::Conditional => $stageCount >= 2, // Need branching options
            self::MapReduce => is_array($payload) || is_iterable($payload), // Need collection
        };
    }

    /**
     * Get all modes that support the given feature.
     *
     * @param string $feature The feature name
     * @return array<PipelineMode>
     */
    public static function withFeature(string $feature): array
    {
        return match ($feature) {
            'parallelism' => [self::Parallel, self::MapReduce],
            'branching' => [self::Conditional],
            'collections' => [self::MapReduce],
            'simplicity' => [self::Sequential],
            default => [],
        };
    }
}
