<?php

namespace Vampires\Sentinels\Contracts;

use Throwable;
use Vampires\Sentinels\Core\Context;

/**
 * Contract for middleware that can wrap agent execution.
 *
 * Middleware allows for cross-cutting concerns like:
 * - Logging and monitoring
 * - Performance measurement
 * - Authentication and authorization
 * - Caching
 * - Retry logic
 * - Error handling
 */
interface AgentMiddlewareContract
{
    /**
     * Process the context before the agent executes.
     *
     * This method can:
     * - Modify the context before processing
     * - Add metadata or logging
     * - Perform authentication checks
     * - Start performance timers
     *
     * @param Context $context The context about to be processed
     * @param AgentContract $agent The agent that will process the context
     * @return Context The potentially modified context
     */
    public function before(Context $context, AgentContract $agent): Context;

    /**
     * Process the context after successful agent execution.
     *
     * This method can:
     * - Log successful execution
     * - Add performance metrics
     * - Cache results
     * - Transform the output
     *
     * @param Context $context The original context
     * @param AgentContract $agent The agent that processed the context
     * @param Context $result The result from the agent
     * @return Context The potentially modified result
     */
    public function after(Context $context, AgentContract $agent, Context $result): Context;

    /**
     * Handle errors that occur during agent execution.
     *
     * This method can:
     * - Log errors
     * - Transform errors into context
     * - Implement retry logic
     * - Provide fallback values
     *
     * @param Context $context The original context
     * @param AgentContract $agent The agent that encountered the error
     * @param Throwable $error The error that occurred
     * @return Context A context containing error information or recovery
     */
    public function onError(Context $context, AgentContract $agent, Throwable $error): Context;

    /**
     * Get the priority of this middleware.
     *
     * Higher priority middleware executes first (before) and last (after).
     *
     * @return int Priority value (higher = earlier execution)
     */
    public function getPriority(): int;

    /**
     * Determine if this middleware should run for the given agent.
     *
     * This allows selective middleware application based on:
     * - Agent type
     * - Context content
     * - Configuration
     *
     * @param AgentContract $agent The agent being executed
     * @param Context $context The context being processed
     * @return bool Whether this middleware should execute
     */
    public function shouldRun(AgentContract $agent, Context $context): bool;
}
