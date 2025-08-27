<?php

namespace Vampires\Sentinels\Routing;

use Vampires\Sentinels\Contracts\AgentContract;
use Vampires\Sentinels\Contracts\AgentMediator;
use Vampires\Sentinels\Contracts\RouterContract;
use Vampires\Sentinels\Core\Context;

/**
 * Basic content-based router implementation (stub for v0.1).
 *
 * This is a minimal implementation to satisfy the contract.
 * Full routing functionality will be implemented in v0.4.
 */
class ContentRouter implements RouterContract
{
    /**
     * Routing rules.
     *
     * @var array<array{condition: mixed, agent: string, priority: int}>
     */
    protected array $routes = [];

    /**
     * Fallback agent.
     */
    protected AgentContract|string|null $fallbackAgent = null;

    /**
     * Statistics.
     *
     * @var array<string, mixed>
     */
    protected array $stats = [
        'total_routes' => 0,
        'cache_hits' => 0,
        'cache_misses' => 0,
        'most_used_routes' => [],
        'fallback_usage' => 0,
    ];

    /**
     * Create a new router instance.
     */
    public function __construct(
        protected AgentMediator $mediator,
        protected array $config = []
    ) {
    }

    /**
     * Route a context to the most appropriate agent.
     */
    public function route(Context $context): ?AgentContract
    {
        // For v0.1, this is a simple stub implementation
        // Full routing logic will be implemented in v0.4

        if ($this->fallbackAgent) {
            $this->stats['fallback_usage']++;

            return $this->mediator->resolveAgent($this->fallbackAgent);
        }

        return null;
    }

    /**
     * Add a routing rule with a condition and corresponding agent.
     */
    public function addRoute(string|callable $condition, AgentContract|string $agent, int $priority = 0): self
    {
        $this->routes[] = [
            'condition' => $condition,
            'agent' => is_string($agent) ? $agent : get_class($agent),
            'priority' => $priority,
        ];

        // Sort by priority (highest first)
        usort($this->routes, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        $this->stats['total_routes'] = count($this->routes);

        return $this;
    }

    /**
     * Add a pattern-based route.
     */
    public function addPatternRoute(
        string $pattern,
        AgentContract|string $agent,
        string $field = 'payload',
        int $priority = 0
    ): self {
        return $this->addRoute($pattern, $agent, $priority);
    }

    /**
     * Add a type-based route.
     */
    public function addTypeRoute(string $type, AgentContract|string $agent, int $priority = 0): self
    {
        return $this->addRoute($type, $agent, $priority);
    }

    /**
     * Add a predicate-based route.
     */
    public function addPredicateRoute(callable $predicate, AgentContract|string $agent, int $priority = 0): self
    {
        return $this->addRoute($predicate, $agent, $priority);
    }

    /**
     * Add a metadata-based route.
     */
    public function addMetadataRoute(string $key, mixed $value, AgentContract|string $agent, int $priority = 0): self
    {
        return $this->addRoute("metadata:{$key}:{$value}", $agent, $priority);
    }

    /**
     * Add a tag-based route.
     */
    public function addTagRoute(string $tag, AgentContract|string $agent, int $priority = 0): self
    {
        return $this->addRoute("tag:{$tag}", $agent, $priority);
    }

    /**
     * Set a fallback agent for when no routes match.
     */
    public function setFallback(AgentContract|string|null $agent): self
    {
        $this->fallbackAgent = $agent;

        return $this;
    }

    /**
     * Remove a routing rule by its condition or agent.
     */
    public function removeRoute(string|callable|AgentContract $identifier): bool
    {
        $originalCount = count($this->routes);

        $this->routes = array_filter($this->routes, function ($route) use ($identifier) {
            if ($identifier instanceof AgentContract) {
                return $route['agent'] !== get_class($identifier);
            }

            return $route['condition'] !== $identifier && $route['agent'] !== $identifier;
        });

        $this->stats['total_routes'] = count($this->routes);

        return count($this->routes) < $originalCount;
    }

    /**
     * Get all routing rules ordered by priority.
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Clear all routing rules.
     */
    public function clearRoutes(): self
    {
        $this->routes = [];
        $this->stats['total_routes'] = 0;

        return $this;
    }

    /**
     * Get all agents that could potentially handle the given context.
     */
    public function getCandidates(Context $context): array
    {
        // Stub implementation for v0.1
        $candidates = [];

        if ($this->fallbackAgent) {
            $candidates[] = $this->mediator->resolveAgent($this->fallbackAgent);
        }

        return $candidates;
    }

    /**
     * Check if the router can handle the given context.
     */
    public function canRoute(Context $context): bool
    {
        return !empty($this->routes) || $this->fallbackAgent !== null;
    }

    /**
     * Enable or disable route caching.
     */
    public function setCacheEnabled(bool $enabled): self
    {
        // Stub for v0.1 - caching will be implemented in v0.4
        return $this;
    }

    /**
     * Clear the routing cache.
     */
    public function clearCache(): self
    {
        // Stub for v0.1 - caching will be implemented in v0.4
        return $this;
    }

    /**
     * Get routing statistics.
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}
