<?php

namespace Vampires\Sentinels\Agents;

use Illuminate\Support\Str;
use Illuminate\Validation\Factory as ValidationFactory;
use Vampires\Sentinels\Contracts\AgentContract;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\RetryPolicy;
use Vampires\Sentinels\Core\ValidationResult;

/**
 * Base abstract class for all Sentinels agents.
 *
 * Provides default implementations of common functionality and
 * enforces the agent contract. Subclasses should implement the
 * handle() method to define their specific behavior.
 */
abstract class BaseAgent implements AgentContract
{
    /**
     * The agent's unique identifier.
     */
    protected ?string $id = null;

    /**
     * Whether this agent should be executed.
     */
    protected bool $enabled = true;

    /**
     * The agent's priority (higher = earlier execution).
     */
    protected int $priority = 0;

    /**
     * Process the context through this agent.
     *
     * This method orchestrates the full agent lifecycle:
     * 1. Validate the context
     * 2. Check if execution should proceed
     * 3. Execute beforeExecute hook
     * 4. Call the handle method
     * 5. Execute afterExecute hook
     * 6. Handle any errors with onError hook
     */
    final public function __invoke(Context $context): Context
    {
        if (!$this->enabled) {
            return $context->withMetadata('agent_skipped', $this->getName());
        }

        // Validate the context
        $validation = $this->validate($context);
        if (!$validation->valid) {
            return $context
                ->addErrors($validation->getAllErrors())
                ->withMetadata('validation_failed', true);
        }

        // Check if we should execute
        if (!$this->shouldExecute($context)) {
            return $context->withMetadata('agent_skipped_condition', $this->getName());
        }

        try {
            // Before execution hook
            $context = $this->beforeExecute($context);

            // Main execution
            $result = $this->handle($context);

            // After execution hook
            $result = $this->afterExecute($context, $result);

            return $result->withMetadata('agent_executed', $this->getName());

        } catch (\Throwable $exception) {
            return $this->onError($context, $exception);
        }
    }

    /**
     * The main processing logic for this agent.
     *
     * This method should be implemented by subclasses to define
     * their specific behavior. It receives a context and should
     * return a modified context.
     */
    abstract protected function handle(Context $context): Context;

    /**
     * Hook called before the agent executes.
     *
     * This can be used for:
     * - Setting up resources
     * - Logging
     * - Adding metadata
     * - Modifying the context
     */
    protected function beforeExecute(Context $context): Context
    {
        return $context
            ->withMetadata('agent_started', $this->getName())
            ->withMetadata('agent_start_time', microtime(true));
    }

    /**
     * Hook called after successful execution.
     *
     * This can be used for:
     * - Cleanup
     * - Logging
     * - Adding metadata
     * - Post-processing
     */
    protected function afterExecute(Context $originalContext, Context $result): Context
    {
        $executionTime = microtime(true) - $originalContext->getMetadata('agent_start_time', 0);

        return $result
            ->withMetadata('agent_completed', $this->getName())
            ->withMetadata('agent_execution_time', $executionTime);
    }

    /**
     * Hook called when an error occurs during execution.
     *
     * This can be used for:
     * - Error logging
     * - Recovery attempts
     * - Providing fallback values
     * - Converting errors to context data
     */
    protected function onError(Context $context, \Throwable $exception): Context
    {
        return $context
            ->addError($exception->getMessage())
            ->withMetadata('agent_failed', $this->getName())
            ->withMetadata('agent_error', [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'type' => get_class($exception),
            ]);
    }

    /**
     * Get the agent's name.
     *
     * By default, this returns the class name without namespace.
     * Override this method to provide a custom name.
     */
    public function getName(): string
    {
        $className = static::class;
        $shortName = class_basename($className);

        // Remove "Agent" suffix if present
        if (str_ends_with($shortName, 'Agent')) {
            $shortName = substr($shortName, 0, -5);
        }

        return Str::title(Str::snake($shortName, ' '));
    }

    /**
     * Get the agent's description.
     *
     * Override this method to provide a meaningful description.
     */
    public function getDescription(): string
    {
        return "Processes context through {$this->getName()}";
    }

    /**
     * Validate the context for this agent.
     *
     * By default, this performs basic validation. Override this method
     * to implement specific validation rules for your agent.
     */
    public function validate(Context $context): ValidationResult
    {
        // Basic validation - context should not be cancelled or have critical errors
        if ($context->isCancelled()) {
            return ValidationResult::invalid(['context' => ['Context has been cancelled']]);
        }

        // Check for critical errors that should stop processing
        $criticalErrors = array_filter($context->errors, function ($error) {
            return str_contains(strtolower($error), 'critical') || str_contains(strtolower($error), 'fatal');
        });

        if (!empty($criticalErrors)) {
            return ValidationResult::invalid(['context' => ['Context has critical errors']]);
        }

        // Additional validation can be implemented by subclasses
        return $this->validatePayload($context);
    }

    /**
     * Validate the payload specifically.
     *
     * Override this method to implement payload-specific validation.
     */
    protected function validatePayload(Context $context): ValidationResult
    {
        return ValidationResult::valid($context->payload);
    }

    /**
     * Determine if this agent should execute for the given context.
     *
     * By default, this returns true. Override this method to implement
     * conditional execution logic.
     */
    public function shouldExecute(Context $context): bool
    {
        return $this->enabled;
    }

    /**
     * Get the expected input type for this agent.
     *
     * Override this method to specify what type of input this agent expects.
     */
    public function getInputType(): ?string
    {
        return null; // Accept any input type by default
    }

    /**
     * Get the required permissions for this agent.
     *
     * Override this method to specify required permissions.
     */
    public function getRequiredPermissions(): array
    {
        return [];
    }

    /**
     * Get the retry policy for this agent.
     *
     * Override this method to provide a custom retry policy.
     */
    public function getRetryPolicy(): ?RetryPolicy
    {
        return null; // Use system default
    }

    /**
     * Get the expected output type for this agent.
     *
     * Override this method to specify what type of output this agent produces.
     */
    public function getOutputType(): ?string
    {
        return $this->getInputType(); // Default: same as input
    }

    /**
     * Get the estimated execution time in milliseconds.
     *
     * Override this method to provide a more accurate estimate.
     */
    public function getEstimatedExecutionTime(): int
    {
        return 100; // Default: 100ms
    }

    /**
     * Get tags that describe this agent's capabilities.
     *
     * Override this method to provide meaningful tags.
     */
    public function getTags(): array
    {
        return ['agent', 'processor'];
    }

    /**
     * Get the agent's unique identifier.
     */
    public function getId(): string
    {
        return $this->id ??= Str::uuid()->toString();
    }

    /**
     * Set whether this agent is enabled.
     */
    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    /**
     * Check if this agent is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Set the agent's priority.
     */
    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * Get the agent's priority.
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Create a simple validation result using Laravel's validator.
     *
     * This is a helper method for subclasses that want to use Laravel's
     * validation system.
     *
     * @param mixed $data The data to validate
     * @param array<string, mixed> $rules Validation rules
     * @param array<string, string> $messages Custom error messages
     */
    protected function validateWithRules(mixed $data, array $rules, array $messages = []): ValidationResult
    {
        if (!app()->bound(ValidationFactory::class)) {
            // Fallback if validation factory is not available
            return ValidationResult::valid($data);
        }

        $validator = app(ValidationFactory::class)->make(
            is_array($data) ? $data : ['payload' => $data],
            $rules,
            $messages
        );

        return ValidationResult::fromValidator($validator);
    }

    /**
     * Get configuration for this agent.
     *
     * This can be used to retrieve agent-specific configuration
     * from Laravel's config system.
     *
     * @return array<string, mixed>
     */
    protected function getConfig(): array
    {
        $agentKey = Str::snake(class_basename(static::class));

        return config("sentinels.agents.{$agentKey}", []);
    }

    /**
     * Log a message with agent context.
     *
     * This is a helper method for logging from within agents.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (!app()->bound('log')) {
            return;
        }

        $context = array_merge([
            'agent' => $this->getName(),
            'agent_id' => $this->getId(),
        ], $context);

        app('log')->{$level}($message, $context);
    }
}
