<?php

namespace Vampires\Sentinels\Contracts;

use Vampires\Sentinels\Core\Context;

/**
 * Contract for pipeline orchestration.
 *
 * Pipelines coordinate the execution of multiple agents in sequence,
 * parallel, or conditional arrangements. They maintain context flow
 * and handle execution strategies.
 */
interface PipelineContract
{
    /**
     * Add a stage to the pipeline.
     *
     * Stages can be:
     * - Agent instances
     * - Agent class names
     * - Callable functions
     * - Nested pipelines
     *
     * @param AgentContract|callable|PipelineContract|string $stage
     * @return self For method chaining
     */
    public function pipe(AgentContract|callable|PipelineContract|string $stage): self;

    /**
     * Process the given input through the pipeline.
     *
     * This is a convenience method that wraps input in a Context
     * and returns the final payload.
     *
     * @param mixed $input The input to process
     * @return mixed The processed output
     */
    public function through(mixed $input): mixed;

    /**
     * Process the given context through the pipeline.
     *
     * This is the core method that orchestrates context flow
     * through all pipeline stages.
     *
     * @param Context $context The context to process
     * @return Context The final processed context
     */
    public function process(Context $context): Context;

    /**
     * Add conditional branching to the pipeline.
     *
     * Based on the condition result, execute either the true or false pipeline.
     *
     * @param callable(Context): bool $condition Condition to evaluate
     * @param PipelineContract $truePipeline Pipeline to execute if true
     * @param PipelineContract|null $falsePipeline Pipeline to execute if false
     * @return self For method chaining
     */
    public function branch(
        callable $condition,
        PipelineContract $truePipeline,
        ?PipelineContract $falsePipeline = null
    ): self;

    /**
     * Apply a transformation to collection payloads.
     *
     * If the context payload is an array/collection, apply the mapper
     * to each item and collect the results.
     *
     * @param callable(mixed, int, Context): mixed $mapper
     * @return self For method chaining
     */
    public function map(callable $mapper): self;

    /**
     * Reduce collection payloads to a single value.
     *
     * @param callable(mixed, mixed, int, Context): mixed $reducer
     * @param mixed $initial Initial value for reduction
     * @return mixed The reduced result
     */
    public function reduce(callable $reducer, mixed $initial = null): mixed;

    /**
     * Add middleware to this pipeline.
     *
     * @param AgentMiddlewareContract $middleware
     * @return self For method chaining
     */
    public function middleware(AgentMiddlewareContract $middleware): self;

    /**
     * Set the execution mode for this pipeline.
     *
     * @param string $mode Execution mode (sequential, parallel, etc.)
     * @return self For method chaining
     */
    public function mode(string $mode): self;

    /**
     * Set a timeout for pipeline execution.
     *
     * @param int $seconds Timeout in seconds
     * @return self For method chaining
     */
    public function timeout(int $seconds): self;

    /**
     * Add error handling to the pipeline.
     *
     * @param callable(Context, \Throwable): Context $handler
     * @return self For method chaining
     */
    public function onError(callable $handler): self;

    /**
     * Add a callback to execute when the pipeline completes successfully.
     *
     * @param callable(Context): void $callback
     * @return self For method chaining
     */
    public function onSuccess(callable $callback): self;

    /**
     * Get all stages in this pipeline.
     *
     * @return array<AgentContract|callable|PipelineContract|string>
     */
    public function getStages(): array;

    /**
     * Get the number of stages in this pipeline.
     */
    public function getStageCount(): int;

    /**
     * Check if this pipeline is empty.
     */
    public function isEmpty(): bool;

    /**
     * Create a copy of this pipeline.
     */
    public function clone(): self;

    /**
     * Get pipeline execution statistics.
     *
     * @return array{
     *     stage_count: int,
     *     estimated_time: int,
     *     has_branches: bool,
     *     middleware_count: int
     * }
     */
    public function getStats(): array;
}
