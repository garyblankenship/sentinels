<?php

namespace Vampires\Sentinels\Contracts;

use Vampires\Sentinels\Core\Context;

/**
 * Contract for dynamic agent routing based on context analysis.
 *
 * Routers analyze context content and metadata to determine
 * which agent should process the context. They support:
 * - Pattern-based routing
 * - Content analysis
 * - Predicate matching
 * - Priority-based selection
 */
interface RouterContract
{
    /**
     * Route a context to the most appropriate agent.
     *
     * Analyzes the context and returns the best matching agent
     * based on configured routing rules.
     *
     * @param Context $context The context to route
     * @return AgentContract|null The selected agent, or null if no match
     */
    public function route(Context $context): ?AgentContract;

    /**
     * Add a routing rule with a condition and corresponding agent.
     *
     * @param string|callable $condition The routing condition
     * @param AgentContract|string $agent The agent to route to
     * @param int $priority Priority for this rule (higher = checked first)
     * @return self For method chaining
     */
    public function addRoute(string|callable $condition, AgentContract|string $agent, int $priority = 0): self;

    /**
     * Add a pattern-based route.
     *
     * Routes based on string patterns in the payload or metadata.
     *
     * @param string $pattern Regex or glob pattern to match
     * @param AgentContract|string $agent The agent to route to
     * @param string $field The field to match against ('payload', 'metadata.key')
     * @param int $priority Priority for this rule
     * @return self For method chaining
     */
    public function addPatternRoute(
        string $pattern,
        AgentContract|string $agent,
        string $field = 'payload',
        int $priority = 0
    ): self;

    /**
     * Add a type-based route.
     *
     * Routes based on the type of the context payload.
     *
     * @param string $type The type to match (class name, primitive type, etc.)
     * @param AgentContract|string $agent The agent to route to
     * @param int $priority Priority for this rule
     * @return self For method chaining
     */
    public function addTypeRoute(string $type, AgentContract|string $agent, int $priority = 0): self;

    /**
     * Add a predicate-based route.
     *
     * Routes based on a custom callable that evaluates the context.
     *
     * @param callable(Context): bool $predicate Function that returns true if the route matches
     * @param AgentContract|string $agent The agent to route to
     * @param int $priority Priority for this rule
     * @return self For method chaining
     */
    public function addPredicateRoute(callable $predicate, AgentContract|string $agent, int $priority = 0): self;

    /**
     * Add a metadata-based route.
     *
     * Routes based on metadata key-value pairs.
     *
     * @param string $key The metadata key to check
     * @param mixed $value The value to match (or null for key existence)
     * @param AgentContract|string $agent The agent to route to
     * @param int $priority Priority for this rule
     * @return self For method chaining
     */
    public function addMetadataRoute(string $key, mixed $value, AgentContract|string $agent, int $priority = 0): self;

    /**
     * Add a tag-based route.
     *
     * Routes based on context tags.
     *
     * @param string $tag The tag to match
     * @param AgentContract|string $agent The agent to route to
     * @param int $priority Priority for this rule
     * @return self For method chaining
     */
    public function addTagRoute(string $tag, AgentContract|string $agent, int $priority = 0): self;

    /**
     * Set a fallback agent for when no routes match.
     *
     * @param AgentContract|string|null $agent The fallback agent, or null to remove
     * @return self For method chaining
     */
    public function setFallback(AgentContract|string|null $agent): self;

    /**
     * Remove a routing rule by its condition or agent.
     *
     * @param string|callable|AgentContract $identifier The condition or agent to remove
     * @return bool Whether a rule was found and removed
     */
    public function removeRoute(string|callable|AgentContract $identifier): bool;

    /**
     * Get all routing rules ordered by priority.
     *
     * @return array<array{condition: mixed, agent: string, priority: int}>
     */
    public function getRoutes(): array;

    /**
     * Clear all routing rules.
     *
     * @return self For method chaining
     */
    public function clearRoutes(): self;

    /**
     * Get all agents that could potentially handle the given context.
     *
     * Returns an array of agents ordered by match confidence/priority.
     *
     * @param Context $context
     * @return array<AgentContract>
     */
    public function getCandidates(Context $context): array;

    /**
     * Check if the router can handle the given context.
     *
     * @param Context $context
     * @return bool Whether any route matches the context
     */
    public function canRoute(Context $context): bool;

    /**
     * Enable or disable route caching.
     *
     * @param bool $enabled
     * @return self For method chaining
     */
    public function setCacheEnabled(bool $enabled): self;

    /**
     * Clear the routing cache.
     *
     * @return self For method chaining
     */
    public function clearCache(): self;

    /**
     * Get routing statistics.
     *
     * @return array{
     *     total_routes: int,
     *     cache_hits: int,
     *     cache_misses: int,
     *     most_used_routes: array<string, int>,
     *     fallback_usage: int
     * }
     */
    public function getStats(): array;
}
