<?php

namespace Vampires\Sentinels\Contracts;

use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\RetryPolicy;
use Vampires\Sentinels\Core\ValidationResult;

/**
 * Core contract that all Sentinels agents must implement.
 *
 * Agents are single-purpose, invokable classes that process Context objects
 * and return modified Context objects. They should be stateless and focused
 * on a specific transformation or action.
 */
interface AgentContract
{
    /**
     * Process the given context and return a modified context.
     *
     * This is the main entry point for agent execution. Agents should:
     * - Process the context payload
     * - Add relevant metadata
     * - Handle errors gracefully
     * - Return a new context with results
     *
     * @param Context $context The context to process
     * @return Context The processed context
     */
    public function __invoke(Context $context): Context;

    /**
     * Get the human-readable name of this agent.
     */
    public function getName(): string;

    /**
     * Get a description of what this agent does.
     */
    public function getDescription(): string;

    /**
     * Validate that the given context is suitable for this agent.
     *
     * This method should check:
     * - Required payload structure
     * - Required metadata
     * - Input constraints
     * - Permissions and authorization
     */
    public function validate(Context $context): ValidationResult;

    /**
     * Determine if this agent should execute for the given context.
     *
     * This allows for conditional execution based on:
     * - Context state
     * - Payload content
     * - Metadata values
     * - Business logic
     */
    public function shouldExecute(Context $context): bool;

    /**
     * Get the expected input type for this agent.
     *
     * This can be used for:
     * - Routing decisions
     * - Validation
     * - Documentation
     *
     * @return string|null The input type (e.g., 'array', 'string', 'App\Models\User')
     */
    public function getInputType(): ?string;

    /**
     * Get the required permissions for this agent to execute.
     *
     * @return array<string> Array of required permissions
     */
    public function getRequiredPermissions(): array;

    /**
     * Get the retry policy for this agent.
     *
     * If null, the default system retry policy will be used.
     */
    public function getRetryPolicy(): ?RetryPolicy;

    /**
     * Get the expected output type for this agent.
     *
     * @return string|null The output type
     */
    public function getOutputType(): ?string;

    /**
     * Get the estimated execution time for this agent in milliseconds.
     *
     * This can be used for:
     * - Pipeline optimization
     * - Timeout configuration
     * - Performance monitoring
     */
    public function getEstimatedExecutionTime(): int;

    /**
     * Get tags that describe this agent's capabilities or category.
     *
     * @return array<string>
     */
    public function getTags(): array;
}
