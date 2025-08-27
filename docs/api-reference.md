# API Reference

Complete reference documentation for all Sentinels classes, methods, and interfaces.

## Table of Contents

- [Core Classes](#core-classes)
  - [Context](#context)
  - [ValidationResult](#validationresult)
  - [RetryPolicy](#retrypolicy)
- [Agents](#agents)
  - [BaseAgent](#baseagent)
  - [AgentContract](#agentcontract)
- [Pipelines](#pipelines)
  - [Pipeline](#pipeline)
  - [PipelineContract](#pipelinecontract)
- [Mediator](#mediator)
  - [AgentMediator](#agentmediator)
- [Events](#events)
- [Facades](#facades)
- [Artisan Commands](#artisan-commands)
- [Configuration](#configuration)
- [Enums](#enums)
- [Exceptions](#exceptions)

---

## Core Classes

### Context

**Namespace:** `Vampires\Sentinels\Core\Context`

Immutable data container that flows through the pipeline, carrying payload, metadata, and execution state.

#### Constructor

```php
public function __construct(
    public mixed $payload = null,
    public array $metadata = [],
    ?string $correlationId = null,
    public array $tags = [],
    public ?string $traceId = null,
    public bool $cancelled = false,
    public array $errors = [],
    ?float $startTime = null
)
```

**Parameters:**
- `$payload` - The main data being processed
- `$metadata` - Additional key-value data
- `$correlationId` - Unique identifier (auto-generated if null)
- `$tags` - Array of classification labels
- `$traceId` - Distributed tracing identifier
- `$cancelled` - Execution control flag
- `$errors` - Array of error messages
- `$startTime` - Execution start time (auto-set if null)

#### Static Methods

```php
// Create new context with payload
public static function create(mixed $payload): self

// Create empty context
public static function empty(): self
```

#### Payload Methods

```php
// Create new context with different payload
public function with(mixed $payload): self

// Check if payload is empty/null
public function isEmpty(): bool

// Get payload size in bytes (for serializable payloads)
public function getPayloadSize(): int
```

#### Metadata Methods

```php
// Add single metadata value
public function withMetadata(string $key, mixed $value): self

// Add multiple metadata values
public function withMergedMetadata(array $metadata): self

// Get metadata value with optional default
public function getMetadata(string $key, mixed $default = null): mixed

// Check if metadata key exists
public function hasMetadata(string $key): bool
```

#### Tag Methods

```php
// Add single tag
public function withTag(string $tag): self

// Add multiple tags
public function withTags(array $tags): self

// Check if context has specific tag
public function hasTag(string $tag): bool

// Remove a tag
public function withoutTag(string $tag): self
```

#### Error Methods

```php
// Add single error
public function addError(string $error): self

// Add multiple errors
public function addErrors(array $errors): self

// Check if context has any errors
public function hasErrors(): bool

// Get error count
public function getErrorCount(): int
```

#### State Methods

```php
// Cancel execution
public function cancel(): self

// Check if cancelled
public function isCancelled(): bool

// Set trace ID for distributed tracing
public function withTraceId(string $traceId): self
```

#### Timing Methods

```php
// Get elapsed execution time in seconds
public function getElapsedTime(): float

// Get start time as Unix timestamp
public function getStartTime(): float
```

#### Serialization Methods

```php
// Convert to array representation
public function toArray(): array

// Convert to JSON string
public function toJson(): string
```

---

### ValidationResult

**Namespace:** `Vampires\Sentinels\Core\ValidationResult`

Represents the result of payload validation with success/failure state and error details.

#### Static Factory Methods

```php
// Create successful validation result
public static function valid(mixed $payload): self

// Create failed validation result
public static function invalid(array $errors): self

// Create result for missing required field
public static function requiredFieldMissing(string $field): self

// Create result for type mismatch
public static function typeMismatch(string $expected, string $actual): self
```

#### Properties

```php
public readonly bool $valid;           // Whether validation passed
public readonly mixed $payload;        // The validated payload
public readonly array $errors;         // Validation errors by field
```

#### Methods

```php
// Get all error messages as flat array
public function getAllErrors(): array

// Get errors for specific field
public function getFieldErrors(string $field): array

// Check if specific field has errors
public function hasFieldErrors(string $field): bool

// Get first error message
public function getFirstError(): ?string

// Convert to array representation
public function toArray(): array
```

#### Usage Example

```php
public function validatePayload(Context $context): ValidationResult
{
    $order = $context->payload;
    
    if (!$order instanceof Order) {
        return ValidationResult::typeMismatch('Order', get_class($order));
    }
    
    if (!$order->customer) {
        return ValidationResult::requiredFieldMissing('customer');
    }
    
    if ($order->total <= 0) {
        return ValidationResult::invalid([
            'total' => ['Must be positive value']
        ]);
    }
    
    return ValidationResult::valid($order);
}
```

---

### RetryPolicy

**Namespace:** `Vampires\Sentinels\Core\RetryPolicy`

Defines retry behavior for failed agent executions with various backoff strategies.

#### Static Factory Methods

```php
// Fixed delay between attempts
public static function fixedDelay(int $delayMs): self

// Exponential backoff (delay doubles each attempt)
public static function exponentialBackoff(): self

// Linear backoff (delay increases linearly)
public static function linearBackoff(): self

// No retries
public static function none(): self
```

#### Configuration Methods

```php
// Set maximum retry attempts
public function maxAttempts(int $attempts): self

// Set base delay in milliseconds
public function baseDelay(int $delayMs): self

// Set maximum delay cap in milliseconds
public function maxDelay(int $delayMs): self

// Set multiplier for exponential/linear backoff
public function multiplier(float $multiplier): self

// Add jitter to prevent thundering herd
public function withJitter(bool $jitter = true): self

// Set exceptions that should trigger retry
public function retryOn(array $exceptions): self

// Set exceptions that should not trigger retry
public function dontRetryOn(array $exceptions): self
```

#### Query Methods

```php
// Get next delay for given attempt number
public function getDelay(int $attempt): int

// Check if exception should trigger retry
public function shouldRetry(\Throwable $exception): bool

// Get maximum attempts
public function getMaxAttempts(): int
```

#### Usage Example

```php
public function getRetryPolicy(): ?RetryPolicy
{
    return RetryPolicy::exponentialBackoff()
        ->maxAttempts(3)
        ->baseDelay(1000)        // 1 second
        ->maxDelay(10000)        // 10 seconds max
        ->withJitter()
        ->retryOn([
            ConnectionException::class,
            TimeoutException::class
        ])
        ->dontRetryOn([
            AuthenticationException::class
        ]);
}
```

---

## Agents

### BaseAgent

**Namespace:** `Vampires\Sentinels\Agents\BaseAgent`

Abstract base class providing default implementations for the agent lifecycle.

#### Abstract Methods

```php
// Main processing logic (must be implemented)
abstract protected function handle(Context $context): Context;
```

#### Lifecycle Hook Methods

```php
// Called before execution
protected function beforeExecute(Context $context): Context

// Called after successful execution
protected function afterExecute(Context $originalContext, Context $result): Context

// Called when an error occurs
protected function onError(Context $context, \Throwable $exception): Context
```

#### Validation Methods

```php
// Validate the entire context
public function validate(Context $context): ValidationResult

// Validate just the payload (override in subclasses)
protected function validatePayload(Context $context): ValidationResult
```

#### Configuration Methods

```php
// Determine if agent should execute
public function shouldExecute(Context $context): bool

// Get agent name (auto-generated or override)
public function getName(): string

// Get agent description
public function getDescription(): string

// Get agent tags
public function getTags(): array

// Get expected input type
public function getInputType(): ?string

// Get expected output type  
public function getOutputType(): ?string

// Get estimated execution time in milliseconds
public function getEstimatedExecutionTime(): int

// Get required permissions
public function getRequiredPermissions(): array

// Get retry policy
public function getRetryPolicy(): ?RetryPolicy

// Get unique agent ID
public function getId(): string

// Enable/disable agent
public function setEnabled(bool $enabled): self
public function isEnabled(): bool

// Set agent priority
public function setPriority(int $priority): self
public function getPriority(): int
```

#### Magic Method

```php
// Process context through agent (invokes lifecycle)
final public function __invoke(Context $context): Context
```

---

### AgentContract

**Namespace:** `Vampires\Sentinels\Contracts\AgentContract`

Interface defining the contract all agents must implement.

#### Required Methods

```php
// Process the context
public function __invoke(Context $context): Context;

// Validate the context
public function validate(Context $context): ValidationResult;

// Check if agent should execute
public function shouldExecute(Context $context): bool;

// Get agent metadata
public function getName(): string;
public function getDescription(): string;
public function getTags(): array;
public function getId(): string;

// Get type information
public function getInputType(): ?string;
public function getOutputType(): ?string;

// Get configuration
public function getEstimatedExecutionTime(): int;
public function getRequiredPermissions(): array;
public function getRetryPolicy(): ?RetryPolicy;

// Control execution
public function isEnabled(): bool;
public function getPriority(): int;
```

---

## Pipelines

### Pipeline

**Namespace:** `Vampires\Sentinels\Pipeline\Pipeline`

Main pipeline implementation for orchestrating agent execution.

#### Constructor

```php
public function __construct(
    AgentMediator $mediator,
    EventDispatcher $events
)
```

#### Static Factory

```php
// Create new pipeline instance
public static function create(): self
```

#### Stage Management

```php
// Add agent/callable/pipeline to execution chain
public function pipe(
    AgentContract|callable|PipelineContract|string $stage
): self

// Add multiple stages at once
public function pipes(array $stages): self

// Get all pipeline stages
public function getStages(): array

// Get stage count
public function getStageCount(): int
```

#### Execution

```php
// Process context through pipeline
public function process(Context $context): Context

// Process payload (creates context automatically)
public function through(mixed $payload): Context
```

#### Configuration

```php
// Set execution mode
public function mode(string $mode): self

// Set execution timeout in seconds
public function timeout(int $seconds): self

// Add error handler
public function onError(callable $handler): self

// Add success handler
public function onSuccess(callable $handler): self

// Add general completion handler
public function onComplete(callable $handler): self

// Get current configuration
public function getConfig(): array
```

#### Middleware

```php
// Add middleware to pipeline
public function middleware(AgentMiddlewareContract $middleware): self

// Get all middleware
public function getMiddleware(): array
```

#### Conditional Execution

```php
// Branch execution based on condition
public function branch(
    callable $condition,
    PipelineContract $truePipeline,
    PipelineContract $falsePipeline
): self

// Execute pipeline only if condition is true
public function when(callable $condition, PipelineContract $pipeline): self

// Execute pipeline only if condition is false
public function unless(callable $condition, PipelineContract $pipeline): self
```

#### Collection Operations

```php
// Apply function to each item in collection
public function map(callable $callback): self

// Reduce collection to single value
public function reduce(callable $callback, mixed $initial = null): self

// Filter items in collection
public function filter(callable $callback): self

// Process items in chunks
public function chunk(int $size): self
```

#### Statistics

```php
// Get execution statistics
public function getStats(): array

// Get execution history
public function getExecutionHistory(): array

// Reset statistics
public function resetStats(): self
```

---

### PipelineContract

**Namespace:** `Vampires\Sentinels\Contracts\PipelineContract`

Interface defining the contract for all pipelines.

#### Required Methods

```php
// Process context through pipeline
public function process(Context $context): Context;

// Add stage to pipeline
public function pipe(
    AgentContract|callable|PipelineContract|string $stage
): self;

// Set execution mode
public function mode(string $mode): self;

// Set timeout
public function timeout(int $seconds): self;

// Get pipeline statistics
public function getStats(): array;
```

---

## Mediator

### AgentMediator

**Namespace:** `Vampires\Sentinels\Mediator\AgentMediator`

Central dispatcher responsible for agent execution, retry handling, and event firing.

#### Methods

```php
// Dispatch context to agent
public function dispatch(Context $context, AgentContract $agent): Context

// Register event listeners
public function listen(string $event, callable $listener): void

// Get registered listeners
public function getListeners(string $event): array

// Enable/disable event firing
public function enableEvents(): void
public function disableEvents(): void

// Get execution statistics
public function getStats(): array

// Reset statistics
public function resetStats(): void
```

#### Usage in Custom Pipeline

```php
$mediator = app(AgentMediator::class);
$context = Context::create($data);
$agent = new MyCustomAgent();

$result = $mediator->dispatch($context, $agent);
```

---

## Events

All events extend `Vampires\Sentinels\Events\BaseEvent` and are dispatched during pipeline execution.

### PipelineStarted

**Properties:**
- `public readonly Context $context` - Initial context
- `public readonly PipelineContract $pipeline` - The pipeline instance
- `public readonly array $stages` - All pipeline stages

### PipelineCompleted

**Properties:**
- `public readonly Context $context` - Final context
- `public readonly PipelineContract $pipeline` - The pipeline instance  
- `public readonly float $executionTime` - Total execution time in seconds

### AgentStarted

**Properties:**
- `public readonly Context $context` - Context before agent execution
- `public readonly AgentContract $agent` - The agent instance
- `public readonly string $agentName` - Agent name

### AgentCompleted

**Properties:**
- `public readonly Context $originalContext` - Context before agent execution
- `public readonly Context $resultContext` - Context after agent execution
- `public readonly AgentContract $agent` - The agent instance
- `public readonly float $executionTime` - Agent execution time in seconds

### AgentFailed

**Properties:**
- `public readonly Context $context` - Context when failure occurred
- `public readonly AgentContract $agent` - The agent that failed
- `public readonly \Throwable $exception` - The exception that occurred
- `public readonly int $attempt` - Current retry attempt number

### Usage Example

```php
use Vampires\Sentinels\Events\AgentCompleted;

Event::listen(AgentCompleted::class, function (AgentCompleted $event) {
    logger()->info('Agent completed', [
        'agent' => $event->agentName,
        'execution_time' => $event->executionTime,
        'correlation_id' => $event->resultContext->correlationId
    ]);
});
```

---

## Facades

### Sentinels

**Namespace:** `Vampires\Sentinels\Facades\Sentinels`

Laravel facade providing convenient access to pipeline creation.

#### Methods

```php
// Create new pipeline
public static function pipeline(): Pipeline

// Create pipeline from configuration
public static function fromConfig(string $name): Pipeline

// Get agent mediator instance
public static function mediator(): AgentMediator

// Get default retry policy
public static function defaultRetryPolicy(): RetryPolicy

// Register global error handler
public static function onError(callable $handler): void

// Register global success handler  
public static function onSuccess(callable $handler): void

// Get framework statistics
public static function getStats(): array

// Reset all statistics
public static function resetStats(): void
```

#### Usage Examples

```php
use Vampires\Sentinels\Facades\Sentinels;

// Create and execute pipeline
$result = Sentinels::pipeline()
    ->pipe(new ValidateDataAgent())
    ->pipe(new ProcessDataAgent())
    ->through($data);

// Use configured pipeline
$result = Sentinels::fromConfig('order_processing')
    ->through($order);

// Global error handling
Sentinels::onError(function (Context $context, \Throwable $exception) {
    logger()->error('Pipeline failed', [
        'correlation_id' => $context->correlationId,
        'error' => $exception->getMessage()
    ]);
});
```

---

## Artisan Commands

### make:agent

Create a new agent class.

```bash
php artisan make:agent {name} {--path=} {--namespace=}
```

**Arguments:**
- `name` - The name of the agent class

**Options:**
- `--path` - Custom path for the agent file
- `--namespace` - Custom namespace for the agent class

**Examples:**
```bash
# Basic agent
php artisan make:agent ProcessOrderAgent

# Custom location
php artisan make:agent PaymentAgent --path=app/Domain/Payment/Agents

# Custom namespace  
php artisan make:agent EmailAgent --namespace=App\\Services\\Email\\Agents
```

### make:pipeline

Create a new pipeline class.

```bash
php artisan make:pipeline {name} {--path=} {--namespace=}
```

**Arguments:**
- `name` - The name of the pipeline class

**Options:**
- `--path` - Custom path for the pipeline file
- `--namespace` - Custom namespace for the pipeline class

### sentinels:list

List all registered agents and their metadata.

```bash
php artisan sentinels:list {--agent=} {--tag=}
```

**Options:**
- `--agent` - Filter by agent name pattern
- `--tag` - Filter by tag

### sentinels:stats

Display execution statistics and performance metrics.

```bash
php artisan sentinels:stats {--reset}
```

**Options:**
- `--reset` - Reset statistics after displaying

---

## Configuration

### Configuration File: `config/sentinels.php`

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Execution Mode
    |--------------------------------------------------------------------------
    |
    | The default mode for pipeline execution when not explicitly specified.
    | Supported: "sequential", "parallel", "map_reduce"
    |
    */
    'default_mode' => env('SENTINELS_DEFAULT_MODE', 'sequential'),

    /*
    |--------------------------------------------------------------------------
    | Default Timeout
    |--------------------------------------------------------------------------
    |
    | Default timeout in seconds for pipeline execution.
    |
    */
    'default_timeout' => env('SENTINELS_DEFAULT_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    |
    | Default retry policy configuration for failed agents.
    |
    */
    'retry_policy' => [
        'type' => env('SENTINELS_RETRY_TYPE', 'exponential_backoff'),
        'max_attempts' => env('SENTINELS_RETRY_MAX_ATTEMPTS', 3),
        'base_delay' => env('SENTINELS_RETRY_BASE_DELAY', 1000),
        'max_delay' => env('SENTINELS_RETRY_MAX_DELAY', 10000),
        'jitter' => env('SENTINELS_RETRY_JITTER', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which events should be fired during execution.
    |
    */
    'events' => [
        'enabled' => env('SENTINELS_EVENTS_ENABLED', true),
        'pipeline_events' => env('SENTINELS_PIPELINE_EVENTS', true),
        'agent_events' => env('SENTINELS_AGENT_EVENTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure automatic logging of pipeline execution.
    |
    */
    'logging' => [
        'enabled' => env('SENTINELS_LOGGING_ENABLED', false),
        'level' => env('SENTINELS_LOGGING_LEVEL', 'info'),
        'channel' => env('SENTINELS_LOGGING_CHANNEL', 'default'),
        'include_payload' => env('SENTINELS_LOG_PAYLOAD', false),
        'include_metadata' => env('SENTINELS_LOG_METADATA', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for performance monitoring and optimization.
    |
    */
    'performance' => [
        'memory_limit' => env('SENTINELS_MEMORY_LIMIT', '256M'),
        'enable_profiling' => env('SENTINELS_ENABLE_PROFILING', false),
        'max_payload_size' => env('SENTINELS_MAX_PAYLOAD_SIZE', 10485760), // 10MB
        'parallel_workers' => env('SENTINELS_PARALLEL_WORKERS', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Named Pipelines
    |--------------------------------------------------------------------------
    |
    | Pre-configured pipelines that can be accessed by name.
    |
    */
    'pipelines' => [
        'example' => [
            'mode' => 'sequential',
            'timeout' => 120,
            'agents' => [
                // Agent class names or configurations
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Discovery
    |--------------------------------------------------------------------------
    |
    | Paths where agents should be automatically discovered.
    |
    */
    'discovery' => [
        'paths' => [
            app_path('Agents'),
        ],
        'enabled' => env('SENTINELS_AUTO_DISCOVERY', true),
    ],
];
```

### Environment Variables

All configuration values can be set via environment variables:

```bash
# Execution Configuration
SENTINELS_DEFAULT_MODE=sequential
SENTINELS_DEFAULT_TIMEOUT=300

# Retry Configuration
SENTINELS_RETRY_TYPE=exponential_backoff
SENTINELS_RETRY_MAX_ATTEMPTS=3
SENTINELS_RETRY_BASE_DELAY=1000
SENTINELS_RETRY_MAX_DELAY=10000
SENTINELS_RETRY_JITTER=true

# Event Configuration
SENTINELS_EVENTS_ENABLED=true
SENTINELS_PIPELINE_EVENTS=true
SENTINELS_AGENT_EVENTS=true

# Logging Configuration
SENTINELS_LOGGING_ENABLED=false
SENTINELS_LOGGING_LEVEL=info
SENTINELS_LOGGING_CHANNEL=default
SENTINELS_LOG_PAYLOAD=false
SENTINELS_LOG_METADATA=true

# Performance Configuration
SENTINELS_MEMORY_LIMIT=256M
SENTINELS_ENABLE_PROFILING=false
SENTINELS_MAX_PAYLOAD_SIZE=10485760
SENTINELS_PARALLEL_WORKERS=4

# Discovery Configuration
SENTINELS_AUTO_DISCOVERY=true
```

---

## Enums

### PipelineMode

**Namespace:** `Vampires\Sentinels\Enums\PipelineMode`

Defines available pipeline execution modes.

```php
enum PipelineMode: string
{
    case Sequential = 'sequential';   // Execute agents one after another
    case Parallel = 'parallel';       // Execute agents simultaneously
    case MapReduce = 'map_reduce';     // Process collections through all agents
}
```

### AgentStatus

**Namespace:** `Vampires\Sentinels\Enums\AgentStatus`

Represents agent execution states.

```php
enum AgentStatus: string
{
    case Pending = 'pending';         // Waiting to execute
    case Running = 'running';         // Currently executing
    case Completed = 'completed';     // Successfully completed
    case Failed = 'failed';           // Execution failed
    case Skipped = 'skipped';         // Execution skipped
    case Cancelled = 'cancelled';     // Execution cancelled
}
```

---

## Exceptions

### Base Exception

**Namespace:** `Vampires\Sentinels\Exceptions\SentinelsException`

Base exception class for all Sentinels exceptions.

### Pipeline Exceptions

- **PipelineException** - General pipeline execution errors
- **PipelineTimeoutException** - Pipeline execution timeout
- **PipelineCancelledException** - Pipeline execution cancelled

### Agent Exceptions

- **AgentException** - General agent execution errors
- **AgentValidationException** - Agent validation failures
- **AgentTimeoutException** - Agent execution timeout

### Context Exceptions

- **ContextException** - General context-related errors
- **InvalidContextException** - Invalid context state
- **ContextSerializationException** - Context serialization errors

### Configuration Exceptions

- **ConfigurationException** - Configuration-related errors
- **InvalidRetryPolicyException** - Invalid retry policy configuration

### Usage Example

```php
use Vampires\Sentinels\Exceptions\AgentValidationException;

protected function handle(Context $context): Context
{
    try {
        return $this->processData($context);
    } catch (ValidationException $e) {
        throw new AgentValidationException(
            "Data validation failed: {$e->getMessage()}",
            previous: $e
        );
    }
}
```

---

## Type Definitions

### Common Type Aliases

```php
// Pipeline stage can be any of these types
type PipelineStage = AgentContract|callable|PipelineContract|string;

// Error handler signature
type ErrorHandler = callable(Context, \Throwable): Context;

// Success handler signature  
type SuccessHandler = callable(Context): void;

// Condition callable signature
type ConditionCallable = callable(Context): bool;

// Map function signature
type MapFunction = callable(mixed): mixed;

// Reduce function signature
type ReduceFunction = callable(mixed, mixed): mixed;
```

---

## Framework Integration

### Laravel Service Container

Sentinels automatically registers these bindings:

```php
// Singleton bindings
$this->app->singleton(AgentMediator::class);
$this->app->singleton('sentinels.mediator', AgentMediator::class);

// Pipeline factory
$this->app->bind('sentinels.pipeline', function ($app) {
    return new Pipeline($app[AgentMediator::class], $app['events']);
});

// Configuration
$this->app->bind('sentinels.config', function ($app) {
    return $app['config']['sentinels'];
});
```

### Laravel Events Integration

Sentinels events integrate with Laravel's event system:

```php
// In EventServiceProvider
protected $listen = [
    \Vampires\Sentinels\Events\PipelineStarted::class => [
        \App\Listeners\LogPipelineStart::class,
    ],
    \Vampires\Sentinels\Events\AgentFailed::class => [
        \App\Listeners\NotifyAdminOfFailure::class,
    ],
];
```

### Queue Integration

Agents can be dispatched to queues:

```php
use Vampires\Sentinels\Queue\QueueableAgent;

class AsyncAgent extends BaseAgent implements ShouldQueue
{
    use QueueableAgent;
    
    protected function handle(Context $context): Context
    {
        // This will be executed on a queue worker
        return $context;
    }
}
```

This comprehensive API reference covers all public interfaces and methods available in the Sentinels framework. Use it as your definitive guide when building agent-based processing pipelines.