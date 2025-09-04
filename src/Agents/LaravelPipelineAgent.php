<?php

namespace Vampires\Sentinels\Agents;

use Illuminate\Pipeline\Pipeline as LaravelPipeline;
use Vampires\Sentinels\Core\Context;

/**
 * Bridge agent that allows Laravel Pipeline to be used within Sentinels workflows.
 * 
 * This agent wraps Laravel's Pipeline functionality, making it easy to incorporate
 * existing Laravel Pipeline pipes into Sentinels agent-based workflows.
 */
class LaravelPipelineAgent extends BaseAgent
{
    /**
     * Create a new Laravel Pipeline bridge agent.
     *
     * @param array $pipes Array of Laravel Pipeline pipes (classes, closures, or strings)
     * @param string $method Method to call on pipe classes (default: 'handle')
     */
    public function __construct(
        protected array $pipes = [],
        protected string $method = 'handle'
    ) {
    }

    /**
     * Execute the Laravel Pipeline with the context payload.
     */
    protected function handle(Context $context): Context
    {
        // Extract the payload for Laravel Pipeline processing
        $payload = $context->payload;

        try {
            // Use Laravel's Pipeline to process the payload
            $result = app(LaravelPipeline::class)
                ->send($payload)
                ->through($this->pipes)
                ->via($this->method)
                ->thenReturn();

            // Return new context with the processed payload
            return $context->with($result)
                ->withMetadata('laravel_pipeline_executed', true)
                ->withMetadata('pipe_count', count($this->pipes))
                ->withTag('laravel-pipeline');

        } catch (\Throwable $exception) {
            // Transform Laravel Pipeline exceptions into Sentinels error handling
            return $context->addError(
                sprintf(
                    'Laravel Pipeline failed: %s (Pipe: %s)',
                    $exception->getMessage(),
                    $this->getCurrentPipeName($exception)
                )
            );
        }
    }

    /**
     * Add a pipe to the Laravel Pipeline.
     */
    public function pipe($pipe): self
    {
        $this->pipes[] = $pipe;
        return $this;
    }

    /**
     * Add multiple pipes to the Laravel Pipeline.
     */
    public function pipes(array $pipes): self
    {
        $this->pipes = array_merge($this->pipes, $pipes);
        return $this;
    }

    /**
     * Set the method to call on pipe classes.
     */
    public function via(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    /**
     * Get the pipes configured for this agent.
     */
    public function getPipes(): array
    {
        return $this->pipes;
    }

    /**
     * Get the method that will be called on pipe classes.
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get agent name for logging and debugging.
     */
    public function getName(): string
    {
        $pipeNames = collect($this->pipes)->map(function ($pipe) {
            if (is_string($pipe)) {
                return class_basename($pipe);
            } elseif (is_object($pipe)) {
                return class_basename(get_class($pipe));
            } else {
                return 'Closure';
            }
        })->implode(', ');

        return sprintf('LaravelPipelineAgent[%s]', $pipeNames ?: 'empty');
    }

    /**
     * Get estimated execution time based on number of pipes.
     */
    public function getEstimatedExecutionTime(): int
    {
        // Estimate 10ms per pipe (can be overridden in subclasses)
        return count($this->pipes) * 10;
    }

    /**
     * Attempt to extract the current pipe name from exception stack trace.
     */
    protected function getCurrentPipeName(\Throwable $exception): string
    {
        $trace = $exception->getTrace();
        
        foreach ($trace as $frame) {
            if (isset($frame['class']) && in_array($frame['class'], $this->pipes)) {
                return class_basename($frame['class']);
            }
        }

        return 'unknown';
    }

    /**
     * Static factory method for fluent creation.
     */
    public static function create(array $pipes = [], string $method = 'handle'): self
    {
        return new self($pipes, $method);
    }

    /**
     * Static factory method with fluent pipe addition.
     */
    public static function through(...$pipes): self
    {
        return new self($pipes);
    }
}