<?php

namespace Vampires\Sentinels\Enums;

/**
 * Represents different strategies for routing contexts to agents.
 */
enum RoutingStrategy: string
{
    case ContentBased = 'content_based';
    case PatternMatching = 'pattern_matching';
    case AttributeBased = 'attribute_based';
    case PredicateBased = 'predicate_based';
    case TypeBased = 'type_based';
    case MetadataBased = 'metadata_based';

    /**
     * Get the human-readable description of this strategy.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::ContentBased => 'Route based on analysis of context content and structure',
            self::PatternMatching => 'Route based on regex or glob patterns in payload',
            self::AttributeBased => 'Route based on PHP attributes on agent classes',
            self::PredicateBased => 'Route based on custom predicate functions',
            self::TypeBased => 'Route based on payload data types',
            self::MetadataBased => 'Route based on context metadata values',
        };
    }

    /**
     * Get the complexity level of implementing this strategy.
     */
    public function getComplexity(): string
    {
        return match ($this) {
            self::TypeBased, self::AttributeBased => 'simple',
            self::PatternMatching, self::MetadataBased => 'medium',
            self::ContentBased => 'complex',
            self::PredicateBased => 'variable',
        };
    }

    /**
     * Get performance characteristics for this routing strategy.
     *
     * @return array{speed: string, accuracy: string, overhead: string, flexibility: string}
     */
    public function getPerformanceCharacteristics(): array
    {
        return match ($this) {
            self::ContentBased => [
                'speed' => 'medium',
                'accuracy' => 'high',
                'overhead' => 'medium',
                'flexibility' => 'high',
            ],
            self::PatternMatching => [
                'speed' => 'fast',
                'accuracy' => 'medium',
                'overhead' => 'low',
                'flexibility' => 'medium',
            ],
            self::AttributeBased => [
                'speed' => 'very-fast',
                'accuracy' => 'high',
                'overhead' => 'very-low',
                'flexibility' => 'low',
            ],
            self::PredicateBased => [
                'speed' => 'variable',
                'accuracy' => 'very-high',
                'overhead' => 'variable',
                'flexibility' => 'very-high',
            ],
            self::TypeBased => [
                'speed' => 'very-fast',
                'accuracy' => 'medium',
                'overhead' => 'very-low',
                'flexibility' => 'low',
            ],
            self::MetadataBased => [
                'speed' => 'fast',
                'accuracy' => 'high',
                'overhead' => 'low',
                'flexibility' => 'medium',
            ],
        };
    }

    /**
     * Check if this strategy is suitable for the given use case.
     */
    public function isSuitableFor(string $useCase): bool
    {
        return match ($this) {
            self::ContentBased => in_array($useCase, [
                'ai_processing', 'content_analysis', 'smart_routing', 'complex_decisions',
            ]),
            self::PatternMatching => in_array($useCase, [
                'file_processing', 'text_analysis', 'url_routing', 'simple_rules',
            ]),
            self::AttributeBased => in_array($useCase, [
                'static_routing', 'class_based', 'compile_time', 'performance_critical',
            ]),
            self::PredicateBased => in_array($useCase, [
                'business_logic', 'complex_conditions', 'runtime_decisions', 'custom_logic',
            ]),
            self::TypeBased => in_array($useCase, [
                'polymorphic_processing', 'type_specific', 'simple_dispatch', 'data_transformation',
            ]),
            self::MetadataBased => in_array($useCase, [
                'tagged_processing', 'context_aware', 'categorized_routing', 'multi_tenant',
            ]),
        };
    }

    /**
     * Get configuration options specific to this strategy.
     */
    public function getDefaultConfiguration(): array
    {
        return match ($this) {
            self::ContentBased => [
                'max_content_size' => 1024 * 1024, // 1MB
                'analysis_depth' => 3,
                'cache_results' => false,
            ],
            self::PatternMatching => [
                'case_sensitive' => true,
                'use_regex' => true,
                'cache_patterns' => true,
            ],
            self::AttributeBased => [
                'scan_on_boot' => true,
                'cache_attributes' => true,
                'inheritance_aware' => true,
            ],
            self::PredicateBased => [
                'timeout' => 100, // milliseconds
                'cache_results' => false,
                'allow_closures' => true,
            ],
            self::TypeBased => [
                'strict_types' => false,
                'include_subtypes' => true,
                'cache_types' => true,
            ],
            self::MetadataBased => [
                'require_exact_match' => false,
                'case_sensitive_keys' => true,
                'cache_metadata' => true,
            ],
        };
    }

    /**
     * Get strategies ordered by performance (fastest first).
     */
    public static function byPerformance(): array
    {
        return [
            self::AttributeBased,
            self::TypeBased,
            self::MetadataBased,
            self::PatternMatching,
            self::ContentBased,
            self::PredicateBased,
        ];
    }

    /**
     * Get strategies suitable for the given complexity level.
     */
    public static function byComplexity(string $complexity): array
    {
        return array_filter(
            self::cases(),
            fn (RoutingStrategy $strategy) => $strategy->getComplexity() === $complexity
        );
    }
}
