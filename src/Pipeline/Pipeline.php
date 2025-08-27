<?php

namespace Vampires\Sentinels\Pipeline;

use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Vampires\Sentinels\Contracts\AgentContract;
use Vampires\Sentinels\Contracts\AgentMediator;
use Vampires\Sentinels\Contracts\AgentMiddlewareContract;
use Vampires\Sentinels\Contracts\PipelineContract;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Enums\PipelineMode;
use Vampires\Sentinels\Events\PipelineCompleted;
use Vampires\Sentinels\Events\PipelineStarted;
use Vampires\Sentinels\Exceptions\PipelineException;

/**
 * Main pipeline implementation for orchestrating agent execution.
 *
 * Supports multiple execution modes and provides a fluent interface
 * for building complex processing pipelines.
 */
class Pipeline implements PipelineContract
{
    /**
     * Pipeline stages (agents, callables, or nested pipelines).
     *
     * @var array<AgentContract|callable|PipelineContract|string>
     */
    protected array $stages = [];

    /**
     * Middleware applied to this pipeline.
     *
     * @var array<AgentMiddlewareContract>
     */
    protected array $middleware = [];

    /**
     * Execution mode for this pipeline.
     */
    protected PipelineMode $mode = PipelineMode::Sequential;

    /**
     * Timeout in seconds.
     */
    protected int $timeout = 300;

    /**
     * Error handler callback.
     *
     * @var callable(Context, \Throwable): Context|null
     */
    protected $errorHandler = null;

    /**
     * Success callback.
     *
     * @var callable(Context): void|null
     */
    protected $successCallback = null;

    /**
     * Pipeline execution statistics.
     *
     * @var array<string, mixed>
     */
    protected array $stats = [];

    /**
     * Create a new pipeline instance.
     */
    public function __construct(
        protected AgentMediator $mediator,
        protected EventDispatcher $events
    ) {
    }

    /**
     * Add a stage to the pipeline.
     */
    public function pipe(AgentContract|callable|PipelineContract|string $stage): self
    {
        $this->stages[] = $stage;

        return $this;
    }

    /**
     * Process the given input through the pipeline.
     */
    public function through(mixed $input): mixed
    {
        $context = Context::create($input);
        $result = $this->process($context);

        return $result->payload;
    }

    /**
     * Process the given context through the pipeline.
     */
    public function process(Context $context): Context
    {
        if ($this->isEmpty()) {
            return $context;
        }

        $startTime = microtime(true);
        $this->stats = [
            'start_time' => $startTime,
            'stage_count' => $this->getStageCount(),
            'mode' => $this->mode->value,
        ];

        // Fire pipeline started event
        $this->fireEvent(new PipelineStarted($context, $this));

        try {
            $result = match ($this->mode) {
                PipelineMode::Sequential => $this->processSequential($context),
                PipelineMode::Parallel => $this->processParallel($context),
                PipelineMode::Conditional => $this->processConditional($context),
                PipelineMode::MapReduce => $this->processMapReduce($context),
            };

            // Check if result has errors and call error handler
            if ($result->hasErrors() && $this->errorHandler) {
                // Create a generic exception from the context errors
                $errorMessage = implode('; ', $result->errors);
                $exception = new \RuntimeException($errorMessage);
                $result = call_user_func($this->errorHandler, $result, $exception);
            } elseif (!$result->hasErrors() && $this->successCallback) {
                // Only call success callback if there are no errors
                call_user_func($this->successCallback, $result);
            }

            $this->updateStats($result, null, microtime(true) - $startTime);

            // Fire pipeline completed event
            $this->fireEvent(new PipelineCompleted($context, $result, $this));

            return $result;

        } catch (\Throwable $exception) {
            if ($this->errorHandler) {
                $result = call_user_func($this->errorHandler, $context, $exception);
            } else {
                $result = $context->addError($exception->getMessage());
            }

            $this->updateStats($result, $exception, microtime(true) - $startTime);

            return $result;
        }
    }

    /**
     * Add conditional branching to the pipeline.
     */
    public function branch(
        callable $condition,
        PipelineContract $truePipeline,
        ?PipelineContract $falsePipeline = null
    ): self {
        $branchStage = new ConditionalStage($condition, $truePipeline, $falsePipeline);

        return $this->pipe($branchStage);
    }

    /**
     * Apply a transformation to collection payloads.
     */
    public function map(callable $mapper): self
    {
        $mapStage = new MapStage($mapper);

        return $this->pipe($mapStage);
    }

    /**
     * Reduce collection payloads to a single value.
     */
    public function reduce(callable $reducer, mixed $initial = null): mixed
    {
        $context = Context::create($initial);

        foreach ($this->stages as $stage) {
            $context = $this->executeStage($stage, $context);
        }

        if (!is_iterable($context->payload)) {
            throw new PipelineException('Cannot reduce non-iterable payload');
        }

        $result = $initial;
        foreach ($context->payload as $key => $value) {
            $result = call_user_func($reducer, $result, $value, $key, $context);
        }

        return $result;
    }

    /**
     * Add middleware to this pipeline.
     */
    public function middleware(AgentMiddlewareContract $middleware): self
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * Set the execution mode for this pipeline.
     */
    public function mode(string $mode): self
    {
        $this->mode = PipelineMode::from($mode);

        return $this;
    }

    /**
     * Set a timeout for pipeline execution.
     */
    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Add error handling to the pipeline.
     */
    public function onError(callable $handler): self
    {
        $this->errorHandler = $handler;

        return $this;
    }

    /**
     * Add a callback to execute when the pipeline completes successfully.
     */
    public function onSuccess(callable $callback): self
    {
        $this->successCallback = $callback;

        return $this;
    }

    /**
     * Get all stages in this pipeline.
     */
    public function getStages(): array
    {
        return $this->stages;
    }

    /**
     * Get the number of stages in this pipeline.
     */
    public function getStageCount(): int
    {
        return count($this->stages);
    }

    /**
     * Check if this pipeline is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->stages);
    }

    /**
     * Create a copy of this pipeline.
     */
    public function clone(): self
    {
        $clone = new self($this->mediator, $this->events);
        $clone->stages = $this->stages;
        $clone->middleware = $this->middleware;
        $clone->mode = $this->mode;
        $clone->timeout = $this->timeout;
        $clone->errorHandler = $this->errorHandler;
        $clone->successCallback = $this->successCallback;

        return $clone;
    }

    /**
     * Get pipeline execution statistics.
     */
    public function getStats(): array
    {
        $estimatedTime = 0;
        $hasBranches = false;

        foreach ($this->stages as $stage) {
            if ($stage instanceof AgentContract) {
                $estimatedTime += $stage->getEstimatedExecutionTime();
            } elseif ($stage instanceof ConditionalStage) {
                $hasBranches = true;
            } elseif ($stage instanceof PipelineContract) {
                $stageStats = $stage->getStats();
                $estimatedTime += $stageStats['estimated_time'];
                $hasBranches = $hasBranches || $stageStats['has_branches'];
            }
        }

        return [
            'stage_count' => $this->getStageCount(),
            'estimated_time' => $estimatedTime,
            'has_branches' => $hasBranches,
            'middleware_count' => count($this->middleware),
            'mode' => $this->mode->value,
            'timeout' => $this->timeout,
            'execution_stats' => $this->stats,
        ];
    }

    /**
     * Process stages sequentially.
     */
    protected function processSequential(Context $context): Context
    {
        $result = $context;

        foreach ($this->stages as $stage) {
            if ($result->isCancelled()) {
                break;
            }

            $result = $this->executeStage($stage, $result);
        }

        return $result;
    }

    /**
     * Process stages in parallel (simulated for v0.1).
     */
    protected function processParallel(Context $context): Context
    {
        // For v0.1, we'll simulate parallel execution
        // True async execution will be implemented in v0.3
        $agents = [];

        foreach ($this->stages as $stage) {
            if ($stage instanceof AgentContract || is_string($stage)) {
                $agents[] = $stage;
            } else {
                // Non-agent stages still execute sequentially
                $context = $this->executeStage($stage, $context);
            }
        }

        if (!empty($agents)) {
            $context = $this->mediator->dispatchParallel($context, $agents);
        }

        return $context;
    }

    /**
     * Process stages with conditional logic.
     */
    protected function processConditional(Context $context): Context
    {
        // This will be enhanced when ConditionalStage is implemented
        return $this->processSequential($context);
    }

    /**
     * Process stages with map/reduce logic.
     */
    protected function processMapReduce(Context $context): Context
    {
        if (!is_iterable($context->payload)) {
            throw new PipelineException('Map/Reduce mode requires iterable payload');
        }

        $mappedResults = [];

        foreach ($context->payload as $key => $item) {
            $itemContext = $context->with($item)->withMetadata('map_key', $key);

            $result = $this->processSequential($itemContext);
            $mappedResults[$key] = $result->payload;
        }

        return $context->with($mappedResults);
    }

    /**
     * Execute a single stage of the pipeline.
     */
    protected function executeStage(
        AgentContract|callable|PipelineContract|string $stage,
        Context $context
    ): Context {
        if ($stage instanceof PipelineContract) {
            return $stage->process($context);
        }

        if ($stage instanceof AgentContract) {
            return $this->mediator->dispatch($context, $stage);
        }

        if ($stage instanceof MapStage || $stage instanceof ConditionalStage) {
            return $stage($context);
        }

        if (is_callable($stage) && !is_string($stage)) {
            $result = call_user_func($stage, $context->payload, $context);

            // Handle different return types from callable stages
            if (is_array($result) && isset($result[1]) && $result[1] instanceof Context) {
                // Return [payload, context] tuple
                return $result[1]->with($result[0]);
            } elseif ($result instanceof Context) {
                // Return Context directly (e.g., from validation stages)
                return $result;
            } else {
                // Return simple value as new payload
                return $context->with($result);
            }
        }

        // Agent string
        return $this->mediator->dispatch($context, $stage);
    }

    /**
     * Update pipeline statistics.
     */
    protected function updateStats(Context $result, ?\Throwable $exception, float $executionTime): void
    {
        $this->stats = array_merge($this->stats, [
            'end_time' => microtime(true),
            'execution_time' => $executionTime * 1000, // Convert to milliseconds
            'success' => $exception === null,
            'error' => $exception?->getMessage(),
            'context_has_errors' => $result->hasErrors(),
            'context_cancelled' => $result->isCancelled(),
        ]);
    }

    /**
     * Fire an event.
     */
    protected function fireEvent(object $event): void
    {
        $this->events->dispatch($event);
    }

    /**
     * Add debugging tap to the pipeline.
     *
     * Executes a callback with the context without modifying it.
     * Useful for logging, debugging, or side effects during processing.
     *
     * @param callable(Context): void $callback The debug callback
     */
    public function tap(callable $callback): self
    {
        return $this->pipe(function (mixed $payload, Context $context) use ($callback): Context {
            call_user_func($callback, $context);
            return $context;
        });
    }

    /**
     * Dump context information for debugging.
     *
     * Outputs context information using dump() for debugging purposes.
     * In production, this should be used sparingly.
     *
     * @param string|null $label Optional label for the dump output
     */
    public function dump(string $label = null): self
    {
        return $this->pipe(function (mixed $payload, Context $context) use ($label): Context {
            if (function_exists('dump')) {
                $output = [
                    'payload' => $context->payload,
                    'metadata' => $context->metadata,
                    'tags' => $context->tags,
                    'correlationId' => $context->correlationId,
                    'errors' => $context->errors,
                    'cancelled' => $context->cancelled,
                    'elapsedTime' => $context->getElapsedTime(),
                ];

                if ($label) {
                    dump("[$label]", $output);
                } else {
                    dump($output);
                }
            }
            return $context;
        });
    }

    /**
     * Send context information to Ray for debugging.
     *
     * If Spatie Ray is available, sends context information for debugging.
     * Falls back to dump() if Ray is not available.
     *
     * @param string|null $label Optional label for the Ray output
     */
    public function ray(string $label = null): self
    {
        return $this->pipe(function (mixed $payload, Context $context) use ($label): Context {
            if (function_exists('ray')) {
                $ray = ray($context->toArray());
                if ($label) {
                    $ray->label($label);
                }
            } elseif (function_exists('dump')) {
                // Fallback to dump if Ray is not available
                if ($label) {
                    dump("Ray: $label", $context->toArray());
                } else {
                    dump('Ray Debug', $context->toArray());
                }
            }
            return $context;
        });
    }

    /**
     * Log context information at specified level.
     *
     * Logs context information using Laravel's logger with appropriate
     * context filtering to avoid logging sensitive information.
     *
     * @param string $level Log level (debug, info, warning, error)
     * @param string|null $message Optional message
     * @param array<string> $excludeKeys Metadata keys to exclude from logs
     */
    public function logContext(
        string $level = 'debug',
        string $message = null,
        array $excludeKeys = ['password', 'token', 'secret', 'key']
    ): self {
        return $this->pipe(function (mixed $payload, Context $context) use ($level, $message, $excludeKeys): Context {
            if (!app()->bound('log')) {
                return $context;
            }

            // Filter sensitive metadata
            $filteredMetadata = $context->metadata;
            foreach ($excludeKeys as $key) {
                if (isset($filteredMetadata[$key])) {
                    $filteredMetadata[$key] = '[FILTERED]';
                }
            }

            $logData = [
                'correlation_id' => $context->correlationId,
                'trace_id' => $context->traceId,
                'tags' => $context->tags,
                'metadata' => $filteredMetadata,
                'has_errors' => $context->hasErrors(),
                'error_count' => count($context->errors),
                'elapsed_time' => $context->getElapsedTime(),
                'payload_size' => $context->getPayloadSize(),
            ];

            $message = $message ?? 'Pipeline context debug';

            app('log')->{$level}($message, $logData);
            return $context;
        });
    }

    /**
     * Validate context at this point in the pipeline.
     *
     * Adds validation step with custom validation logic.
     * Continues processing if validation passes, adds errors if not.
     *
     * @param callable(Context): bool|array $validator Validation callback
     * @param string $errorMessage Error message if validation fails
     */
    public function validate(callable $validator, string $errorMessage = 'Pipeline validation failed'): self
    {
        return $this->pipe(function (mixed $payload, Context $context) use ($validator, $errorMessage): Context {
            $result = $validator($context);

            if ($result === false) {
                // Hard failure - add error and cancel processing
                return $context->addError($errorMessage)->cancel();
            } elseif (is_array($result) && !empty($result)) {
                // Soft failure - add errors but continue processing
                return $context->addErrors($result);
            }

            return $context;
        });
    }

    /**
     * Create a new pipeline instance.
     */
    public static function create(AgentMediator $mediator = null, EventDispatcher $events = null): self
    {
        $mediator ??= app(AgentMediator::class);
        $events ??= app(EventDispatcher::class);

        return new self($mediator, $events);
    }
}

/**
 * Helper class for conditional pipeline stages.
 */
class ConditionalStage
{
    public function __construct(
        protected $condition,
        protected PipelineContract $truePipeline,
        protected ?PipelineContract $falsePipeline = null
    ) {
    }

    public function __invoke(Context $context): Context
    {
        $shouldExecuteTrue = call_user_func($this->condition, $context);

        if ($shouldExecuteTrue) {
            return $this->truePipeline->process($context);
        } elseif ($this->falsePipeline) {
            return $this->falsePipeline->process($context);
        }

        return $context;
    }
}

/**
 * Helper class for map operations.
 */
class MapStage
{
    public function __construct(
        protected $mapper
    ) {
    }

    public function __invoke(Context $context): Context
    {
        if (!is_iterable($context->payload)) {
            return $context;
        }

        $mapped = [];
        foreach ($context->payload as $key => $value) {
            $mapped[$key] = call_user_func($this->mapper, $value, $key, $context);
        }

        return $context->with($mapped);
    }
}
