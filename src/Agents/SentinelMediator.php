<?php

namespace Vampires\Sentinels\Agents;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Vampires\Sentinels\Contracts\AgentContract;
use Vampires\Sentinels\Contracts\AgentMediator;
use Vampires\Sentinels\Contracts\AgentMiddlewareContract;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Events\AgentCompleted;
use Vampires\Sentinels\Events\AgentFailed;
use Vampires\Sentinels\Events\AgentStarted;
use Vampires\Sentinels\Exceptions\AgentException;
use Vampires\Sentinels\Exceptions\AgentResolutionException;

/**
 * Central mediator for orchestrating agent execution.
 *
 * Handles:
 * - Agent resolution and instantiation
 * - Middleware application
 * - Event dispatching
 * - Error handling and recovery
 * - Performance monitoring
 */
class SentinelMediator implements AgentMediator
{
    /**
     * Global middleware applied to all agents.
     *
     * @var array<AgentMiddlewareContract>
     */
    protected array $globalMiddleware = [];

    /**
     * Pattern-based middleware registration.
     *
     * @var array<string, array<AgentMiddlewareContract>>
     */
    protected array $patternMiddleware = [];

    /**
     * Execution statistics.
     *
     * @var array<string, mixed>
     */
    protected array $stats = [
        'total_executions' => 0,
        'successful_executions' => 0,
        'failed_executions' => 0,
        'total_execution_time' => 0.0,
        'agent_usage' => [],
    ];

    /**
     * Whether event dispatching is enabled.
     */
    protected bool $eventDispatchingEnabled = true;

    /**
     * Create a new mediator instance.
     */
    public function __construct(
        protected Container $container,
        protected EventDispatcher $events,
        array $globalMiddleware = []
    ) {
        foreach ($globalMiddleware as $middleware) {
            $this->addGlobalMiddleware($this->resolveMiddleware($middleware));
        }
    }

    /**
     * Dispatch a context through an agent with full middleware support.
     */
    public function dispatch(Context $context, AgentContract|string $agent): Context
    {
        $startTime = microtime(true);
        $agentInstance = $this->resolveAgent($agent);
        $agentName = $agentInstance->getName();

        $this->stats['total_executions']++;
        $this->stats['agent_usage'][$agentName] = ($this->stats['agent_usage'][$agentName] ?? 0) + 1;

        try {
            // Fire agent started event
            $this->fireEvent(new AgentStarted($agentName, $context));

            // Apply middleware and execute agent
            $result = $this->executeWithMiddleware($context, $agentInstance);

            // Update statistics
            $executionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            $this->stats['successful_executions']++;
            $this->stats['total_execution_time'] += $executionTime;

            // Fire agent completed event
            $this->fireEvent(new AgentCompleted($agentName, $context, $result, $executionTime));

            return $result;

        } catch (\Throwable $exception) {
            // Update statistics
            $executionTime = (microtime(true) - $startTime) * 1000;
            $this->stats['failed_executions']++;
            $this->stats['total_execution_time'] += $executionTime;

            // Fire agent failed event
            $this->fireEvent(new AgentFailed($agentName, $context, $exception, $executionTime));

            // Handle the error through middleware
            $result = $this->handleErrorWithMiddleware($context, $agentInstance, $exception);

            return $result;
        }
    }

    /**
     * Execute multiple agents in sequence.
     */
    public function dispatchSequence(Context $context, array $agents): Context
    {
        $result = $context;

        foreach ($agents as $agent) {
            // Stop if context is cancelled
            if ($result->isCancelled()) {
                break;
            }

            $result = $this->dispatch($result, $agent);
        }

        return $result;
    }

    /**
     * Execute multiple agents in parallel (simulated for v0.1).
     *
     * Note: True parallel execution requires async support which will
     * be implemented in a future version. For now, this executes
     * agents sequentially but applies the same context to each.
     */
    public function dispatchParallel(Context $context, array $agents): Context
    {
        $results = [];
        $errors = [];

        foreach ($agents as $agent) {
            try {
                $results[] = $this->dispatch($context, $agent);
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        // Merge results (simplified merging for v0.1)
        $finalContext = $context;

        // Combine outputs
        $combinedOutput = null;
        if (count($results) > 0) {
            $outputs = array_map(fn ($ctx) => $ctx->payload, $results);

            // If all outputs are arrays, merge them
            if (count(array_filter($outputs, 'is_array')) === count($outputs)) {
                $combinedOutput = array_merge(...$outputs);
            } else {
                // For mixed or non-array outputs, return array of all results
                $combinedOutput = $outputs;
            }

            $finalContext = $finalContext->with($combinedOutput);
        }

        // Add any errors
        if (!empty($errors)) {
            $finalContext = $finalContext->addErrors($errors);
        }

        return $finalContext->withMetadata('parallel_execution', [
            'agent_count' => count($agents),
            'successful_count' => count($results),
            'error_count' => count($errors),
        ]);
    }

    /**
     * Resolve an agent from various sources.
     */
    public function resolveAgent(AgentContract|string $agent): AgentContract
    {
        if ($agent instanceof AgentContract) {
            return $agent;
        }

        try {
            // Try to resolve from container
            if ($this->container->bound($agent)) {
                $resolved = $this->container->make($agent);
                if (!$resolved instanceof AgentContract) {
                    throw new AgentResolutionException("Resolved class {$agent} does not implement AgentContract");
                }

                return $resolved;
            }

            // Try to instantiate directly
            if (class_exists($agent)) {
                $resolved = new $agent();
                if (!$resolved instanceof AgentContract) {
                    throw new AgentResolutionException("Class {$agent} does not implement AgentContract");
                }

                return $resolved;
            }

            throw new AgentResolutionException("Cannot resolve agent: {$agent}");

        } catch (\Throwable $exception) {
            throw new AgentResolutionException(
                "Failed to resolve agent {$agent}: {$exception->getMessage()}",
                previous: $exception
            );
        }
    }

    /**
     * Add global middleware that applies to all agent executions.
     */
    public function addGlobalMiddleware(AgentMiddlewareContract $middleware): void
    {
        $this->globalMiddleware[] = $middleware;

        // Sort by priority (highest first)
        usort($this->globalMiddleware, function ($a, $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }

    /**
     * Add middleware for specific agent types or names.
     */
    public function addMiddleware(string $agentPattern, AgentMiddlewareContract $middleware): void
    {
        if (!isset($this->patternMiddleware[$agentPattern])) {
            $this->patternMiddleware[$agentPattern] = [];
        }

        $this->patternMiddleware[$agentPattern][] = $middleware;

        // Sort by priority
        usort($this->patternMiddleware[$agentPattern], function ($a, $b) {
            return $b->getPriority() <=> $a->getPriority();
        });
    }

    /**
     * Remove middleware by instance or pattern.
     */
    public function removeMiddleware(AgentMiddlewareContract|string $middleware): bool
    {
        $removed = false;

        if ($middleware instanceof AgentMiddlewareContract) {
            // Remove from global middleware
            $this->globalMiddleware = array_filter(
                $this->globalMiddleware,
                fn ($m) => $m !== $middleware
            );

            // Remove from pattern middleware
            foreach ($this->patternMiddleware as $pattern => $middlewares) {
                $this->patternMiddleware[$pattern] = array_filter(
                    $middlewares,
                    fn ($m) => $m !== $middleware
                );
                if (empty($this->patternMiddleware[$pattern])) {
                    unset($this->patternMiddleware[$pattern]);
                }
            }

            $removed = true;
        } else {
            // Remove by pattern
            if (isset($this->patternMiddleware[$middleware])) {
                unset($this->patternMiddleware[$middleware]);
                $removed = true;
            }
        }

        return $removed;
    }

    /**
     * Get all middleware that would apply to the given agent.
     */
    public function getMiddlewareFor(AgentContract $agent, Context $context): array
    {
        $middleware = [];

        // Add global middleware
        foreach ($this->globalMiddleware as $m) {
            if ($m->shouldRun($agent, $context)) {
                $middleware[] = $m;
            }
        }

        // Add pattern-based middleware
        $agentClass = get_class($agent);
        $agentName = $agent->getName();

        foreach ($this->patternMiddleware as $pattern => $patternMiddleware) {
            // Simple pattern matching (can be enhanced later)
            if (fnmatch($pattern, $agentClass) || fnmatch($pattern, $agentName)) {
                foreach ($patternMiddleware as $m) {
                    if ($m->shouldRun($agent, $context)) {
                        $middleware[] = $m;
                    }
                }
            }
        }

        return $middleware;
    }

    /**
     * Check if an agent can be resolved.
     */
    public function canResolve(AgentContract|string $agent): bool
    {
        if ($agent instanceof AgentContract) {
            return true;
        }

        try {
            $this->resolveAgent($agent);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Get statistics about agent executions.
     */
    public function getExecutionStats(): array
    {
        $stats = $this->stats;

        // Calculate average execution time
        $stats['average_execution_time'] = $stats['total_executions'] > 0
            ? $stats['total_execution_time'] / $stats['total_executions']
            : 0.0;

        // Sort most used agents
        arsort($stats['agent_usage']);
        $stats['most_used_agents'] = $stats['agent_usage'];

        return $stats;
    }

    /**
     * Clear execution statistics.
     */
    public function clearStats(): void
    {
        $this->stats = [
            'total_executions' => 0,
            'successful_executions' => 0,
            'failed_executions' => 0,
            'total_execution_time' => 0.0,
            'agent_usage' => [],
        ];
    }

    /**
     * Enable or disable event dispatching.
     */
    public function setEventDispatchingEnabled(bool $enabled): void
    {
        $this->eventDispatchingEnabled = $enabled;
    }

    /**
     * Check if event dispatching is enabled.
     */
    public function isEventDispatchingEnabled(): bool
    {
        return $this->eventDispatchingEnabled;
    }

    /**
     * Execute an agent with middleware applied.
     */
    protected function executeWithMiddleware(Context $context, AgentContract $agent): Context
    {
        $middleware = $this->getMiddlewareFor($agent, $context);
        $processedContext = $context;

        // Apply before middleware
        foreach ($middleware as $m) {
            $processedContext = $m->before($processedContext, $agent);
        }

        // Execute the agent
        $result = $agent($processedContext);

        // Apply after middleware (in reverse order)
        foreach (array_reverse($middleware) as $m) {
            $result = $m->after($processedContext, $agent, $result);
        }

        return $result;
    }

    /**
     * Handle errors through middleware.
     */
    protected function handleErrorWithMiddleware(
        Context $context,
        AgentContract $agent,
        \Throwable $exception
    ): Context {
        $middleware = $this->getMiddlewareFor($agent, $context);
        $errorContext = $context;

        // Apply error handling middleware
        foreach ($middleware as $m) {
            $errorContext = $m->onError($errorContext, $agent, $exception);
        }

        // If no middleware handled the error, add it to context
        if (!$errorContext->hasErrors() || !in_array($exception->getMessage(), $errorContext->errors)) {
            $errorContext = $errorContext->addError($exception->getMessage());
        }

        return $errorContext;
    }

    /**
     * Resolve middleware from various sources.
     */
    protected function resolveMiddleware(AgentMiddlewareContract|string $middleware): AgentMiddlewareContract
    {
        if ($middleware instanceof AgentMiddlewareContract) {
            return $middleware;
        }

        if ($this->container->bound($middleware)) {
            return $this->container->make($middleware);
        }

        if (class_exists($middleware)) {
            return new $middleware();
        }

        throw new AgentException("Cannot resolve middleware: {$middleware}");
    }

    /**
     * Fire an event if event dispatching is enabled.
     */
    protected function fireEvent(object $event): void
    {
        if ($this->eventDispatchingEnabled) {
            $this->events->dispatch($event);
        }
    }
}
