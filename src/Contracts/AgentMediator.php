<?php

namespace Vampires\Sentinels\Contracts;

use Vampires\Sentinels\Core\Context;

/**
 * Contract for the central mediator that orchestrates agent execution.
 *
 * The mediator coordinates:
 * - Agent instantiation and resolution
 * - Middleware application
 * - Event dispatching
 * - Error handling and recovery
 * - Performance monitoring
 */
interface AgentMediator
{
    /**
     * Dispatch a context through an agent with full middleware support.
     *
     * This is the primary method for executing agents with:
     * - Middleware application
     * - Event dispatching
     * - Error handling
     * - Performance monitoring
     *
     * @param Context $context The context to process
     * @param AgentContract|string $agent The agent to execute
     * @return Context The processed context
     */
    public function dispatch(Context $context, AgentContract|string $agent): Context;

    /**
     * Execute multiple agents in sequence.
     *
     * @param Context $context The initial context
     * @param array<AgentContract|string> $agents The agents to execute
     * @return Context The final processed context
     */
    public function dispatchSequence(Context $context, array $agents): Context;

    /**
     * Execute multiple agents in parallel (where possible).
     *
     * @param Context $context The initial context
     * @param array<AgentContract|string> $agents The agents to execute
     * @return Context The merged processed context
     */
    public function dispatchParallel(Context $context, array $agents): Context;

    /**
     * Resolve an agent from various sources.
     *
     * Can resolve from:
     * - Agent instances
     * - Class names
     * - Container bindings
     * - Configured aliases
     *
     * @param AgentContract|string $agent
     * @return AgentContract The resolved agent instance
     */
    public function resolveAgent(AgentContract|string $agent): AgentContract;

    /**
     * Add global middleware that applies to all agent executions.
     *
     * @param AgentMiddlewareContract $middleware
     * @return void
     */
    public function addGlobalMiddleware(AgentMiddlewareContract $middleware): void;

    /**
     * Add middleware for specific agent types or names.
     *
     * @param string $agentPattern Pattern to match agent names/types
     * @param AgentMiddlewareContract $middleware
     * @return void
     */
    public function addMiddleware(string $agentPattern, AgentMiddlewareContract $middleware): void;

    /**
     * Remove middleware by instance or pattern.
     *
     * @param AgentMiddlewareContract|string $middleware
     * @return bool Whether middleware was found and removed
     */
    public function removeMiddleware(AgentMiddlewareContract|string $middleware): bool;

    /**
     * Get all middleware that would apply to the given agent.
     *
     * @param AgentContract $agent
     * @param Context $context
     * @return array<AgentMiddlewareContract>
     */
    public function getMiddlewareFor(AgentContract $agent, Context $context): array;

    /**
     * Check if an agent can be resolved.
     *
     * @param AgentContract|string $agent
     * @return bool
     */
    public function canResolve(AgentContract|string $agent): bool;

    /**
     * Get statistics about agent executions.
     *
     * @return array{
     *     total_executions: int,
     *     successful_executions: int,
     *     failed_executions: int,
     *     average_execution_time: float,
     *     most_used_agents: array<string, int>
     * }
     */
    public function getExecutionStats(): array;

    /**
     * Clear execution statistics.
     *
     * @return void
     */
    public function clearStats(): void;

    /**
     * Enable or disable event dispatching.
     *
     * @param bool $enabled
     * @return void
     */
    public function setEventDispatchingEnabled(bool $enabled): void;

    /**
     * Check if event dispatching is enabled.
     *
     * @return bool
     */
    public function isEventDispatchingEnabled(): bool;
}
