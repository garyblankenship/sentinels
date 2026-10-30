<?php

namespace Vampires\Sentinels\Routing;

use Vampires\Sentinels\Contracts\AgentContract;
use Vampires\Sentinels\Contracts\AgentMediator;
use Vampires\Sentinels\Contracts\RouterContract;
use Vampires\Sentinels\Core\Context;

/**
 * Content-based routing with priority-ordered rules and an optional fallback.
 */
class ContentRouter implements RouterContract
{
    /**
     * Routing rules.
     *
     * @var array<array{condition: mixed, agent: AgentContract|string, priority: int}>
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
        foreach ($this->routes as $route) {
            if ($this->matches($route['condition'], $context)) {
                $agent = $this->mediator->resolveAgent($route['agent']);
                $name = $agent->getName();
                $this->stats['most_used_routes'][$name] = ($this->stats['most_used_routes'][$name] ?? 0) + 1;

                return $agent;
            }
        }

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
            'agent' => $agent,
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
        return $this->addRoute(function (Context $context) use ($pattern, $field): bool {
            $value = data_get(['payload' => $context->payload, 'metadata' => $context->metadata], $field);

            return $this->matchesPattern($pattern, $value);
        }, $agent, $priority);
    }

    /**
     * Add a type-based route.
     */
    public function addTypeRoute(string $type, AgentContract|string $agent, int $priority = 0): self
    {
        return $this->addRoute(
            fn (Context $context): bool => $this->matchesType($type, $context->payload),
            $agent,
            $priority
        );
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
        return $this->addRoute(static fn (Context $context): bool => $context->hasMetadata($key)
            && ($value === null || $context->getMetadata($key) === $value), $agent, $priority);
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
                return $route['agent'] !== $identifier && $route['agent'] !== get_class($identifier);
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
        $candidates = [];
        foreach ($this->routes as $route) {
            if ($this->matches($route['condition'], $context)) {
                $candidates[] = $this->mediator->resolveAgent($route['agent']);
            }
        }

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
        foreach ($this->routes as $route) {
            if ($this->matches($route['condition'], $context)) {
                return true;
            }
        }

        return $this->fallbackAgent !== null;
    }

    /**
     * Evaluate the public string shorthand or a context predicate.
     */
    protected function matches(string|callable $condition, Context $context): bool
    {
        if (is_callable($condition)) {
            return (bool) $condition($context);
        }

        if (str_starts_with($condition, 'tag:')) {
            return $context->hasTag(substr($condition, 4));
        }

        if (str_starts_with($condition, 'metadata:')) {
            $parts = explode(':', substr($condition, 9), 2);

            return $context->hasMetadata($parts[0])
                && (!isset($parts[1]) || $context->getMetadata($parts[0]) === $parts[1]);
        }

        return $this->matchesType($condition, $context->payload)
            || $this->matchesPattern($condition, $context->payload);
    }

    protected function matchesType(string $type, mixed $payload): bool
    {
        return match (strtolower($type)) {
            'int', 'integer' => is_int($payload),
            'float', 'double' => is_float($payload),
            'bool', 'boolean' => is_bool($payload),
            'string' => is_string($payload),
            'array' => is_array($payload),
            'object' => is_object($payload),
            'null' => $payload === null,
            'numeric' => is_numeric($payload),
            'scalar' => is_scalar($payload),
            'callable' => is_callable($payload),
            'iterable' => is_iterable($payload),
            'resource' => is_resource($payload),
            default => is_object($payload) && is_a($payload, $type),
        };
    }

    protected function matchesPattern(string $pattern, mixed $value): bool
    {
        if (!is_scalar($value) && !$value instanceof \Stringable) {
            return false;
        }

        $value = (string) $value;
        $match = @preg_match($pattern, $value);

        return $match === false ? fnmatch($pattern, $value) : $match === 1;
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
