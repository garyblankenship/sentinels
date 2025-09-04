# Laravel Pipeline vs Sentinels: When to Use Which?

## "Doesn't Laravel already do this pipeline thing?"

Great question! Laravel does include a Pipeline class, and it's excellent for many use cases. However, Sentinels addresses different needs. Here's when to use each:

## Quick Decision Guide

### Use **Laravel's Pipeline** when you need:
- ✅ Simple data transformations or filtering
- ✅ Middleware-style processing
- ✅ Lightweight, minimal overhead
- ✅ Working with any data types
- ✅ Basic sequential processing

### Use **Sentinels Pipeline** when you need:
- ✅ Complex business workflows with multiple steps
- ✅ Parallel or asynchronous execution
- ✅ Rich error handling and retry policies
- ✅ Observability (logging, tracing, correlation IDs)
- ✅ Conditional branching and routing
- ✅ Agent-based architecture for team development

## Side-by-Side Comparison

| Feature | Laravel Pipeline | Sentinels Pipeline |
|---------|------------------|-------------------|
| **Complexity** | Simple, lightweight | Feature-rich, comprehensive |
| **Learning Curve** | Minimal | Moderate |
| **Performance** | Fast, minimal overhead | Good, with observability overhead |
| **Async Support** | ❌ | ✅ True parallel execution |
| **Error Handling** | Basic exceptions | ✅ Retry policies, recovery |
| **Observability** | ❌ | ✅ Events, metrics, correlation |
| **Conditional Logic** | ❌ | ✅ Branching, routing |
| **Testing** | Standard PHPUnit | ✅ Built-in test helpers |
| **Team Development** | Single developer friendly | ✅ Multi-developer workflows |

## Code Examples

### Laravel Pipeline - Perfect for Simple Transformations

```php
use Illuminate\Pipeline\Pipeline;

// Simple data processing
$result = app(Pipeline::class)
    ->send($user)
    ->through([
        function ($user, $next) {
            $user->name = strtoupper($user->name);
            return $next($user);
        },
        function ($user, $next) {
            $user->email = strtolower($user->email);
            return $next($user);
        },
    ])
    ->thenReturn();
```

### Sentinels Pipeline - Complex Business Workflows

```php
use Vampires\Sentinels\Facades\Sentinels;

// Complex order processing with error handling and observability
$result = Sentinels::pipeline()
    ->pipe(new ValidateOrderAgent())
    ->pipe(new CheckInventoryAgent())
    ->pipe(new ProcessPaymentAgent())
    ->branch(
        fn($ctx) => $ctx->hasTag('premium'),
        $premiumPipeline,
        $standardPipeline
    )
    ->onError(function ($context, $exception) {
        // Sophisticated error recovery
        return $context->addError("Order failed: " . $exception->getMessage());
    })
    ->through($order);

// Every step is logged with correlation IDs for debugging
// Automatic retry policies for transient failures
// Rich context preserved throughout execution
```

## Migration Strategies

### 1. Start Simple, Scale Complex

```php
// Begin with Laravel Pipeline for simple cases
$basicResult = app(Pipeline::class)
    ->send($data)
    ->through($simpleTransformations)
    ->thenReturn();

// Migrate to Sentinels when complexity grows
$complexResult = Sentinels::pipeline()
    ->pipe(new BusinessLogicAgent())
    ->mode('parallel')
    ->async()
    ->through($data);
```

### 2. Use Both in the Same Project

```php
// Laravel Pipeline for simple utilities
class UserFormatter 
{
    public function format($user) 
    {
        return app(Pipeline::class)
            ->send($user)
            ->through([FormatName::class, FormatEmail::class])
            ->thenReturn();
    }
}

// Sentinels for complex business processes
class OrderProcessor 
{
    public function process($order) 
    {
        return Sentinels::pipeline()
            ->pipe(new ValidateOrderAgent())
            ->pipe(new ProcessPaymentAgent())
            ->through($order);
    }
}
```

## When Each Pattern Shines

### Laravel Pipeline: The Swiss Army Knife

```php
// Perfect for:
// - HTTP middleware
// - Data validation chains
// - Simple transformations
// - Request/response processing

$request = app(Pipeline::class)
    ->send($request)
    ->through([
        AuthMiddleware::class,
        ValidationMiddleware::class,
        RateLimitMiddleware::class,
    ])
    ->then(function ($request) {
        return $this->handleRequest($request);
    });
```

### Sentinels: The Orchestra Conductor

```php
// Perfect for:
// - Multi-step business processes
// - Background job orchestration
// - API integrations with retry logic
// - Complex workflows with branching

$result = Sentinels::pipeline()
    ->pipe(new ExtractDataAgent())
    ->pipe(new TransformDataAgent())
    ->mode('parallel')
    ->async()
    ->pipe(new SaveToDbAgent())
    ->pipe(new SendNotificationAgent())
    ->pipe(new UpdateAnalyticsAgent())
    ->onError(new RetryWithBackoffPolicy())
    ->through($importData);
```

## Interoperability

### Using Laravel Pipeline within Sentinels Agents

```php
class DataTransformationAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        // Use Laravel Pipeline for data transformation within an agent
        $transformedData = app(Pipeline::class)
            ->send($context->payload)
            ->through([
                new NormalizeFormat(),
                new ValidateFields(),
                new EnrichData(),
            ])
            ->thenReturn();

        return $context->with($transformedData);
    }
}
```

### Converting Laravel Pipeline to Sentinels

```php
// Create an agent that wraps Laravel Pipeline behavior
class LaravelPipelineAgent extends BaseAgent
{
    public function __construct(
        protected array $pipes,
        protected string $method = 'handle'
    ) {}

    protected function handle(Context $context): Context
    {
        $result = app(Pipeline::class)
            ->send($context->payload)
            ->through($this->pipes)
            ->via($this->method)
            ->thenReturn();

        return $context->with($result);
    }
}

// Use it in Sentinels pipelines
$result = Sentinels::pipeline()
    ->pipe(new LaravelPipelineAgent([
        FormatDataPipe::class,
        ValidateDataPipe::class,
    ]))
    ->pipe(new ComplexBusinessAgent())
    ->through($data);
```

## Best Practices

### 1. Choose Based on Complexity

```php
// Simple? Laravel Pipeline
if ($isSimpleTransformation) {
    return app(Pipeline::class)->send($data)->through($pipes)->thenReturn();
}

// Complex? Sentinels Pipeline
return Sentinels::pipeline()
    ->pipe($agents)
    ->mode('parallel')
    ->onError($errorHandler)
    ->through($data);
```

### 2. Progressive Enhancement

```php
// Start with Laravel Pipeline
class DataProcessor 
{
    public function process($data) 
    {
        return app(Pipeline::class)
            ->send($data)
            ->through($this->getTransformations())
            ->thenReturn();
    }
}

// Enhance to Sentinels when needed
class EnhancedDataProcessor 
{
    public function process($data) 
    {
        return Sentinels::pipeline()
            ->pipe(new ValidationAgent())
            ->pipe(new LaravelPipelineAgent($this->getTransformations()))
            ->pipe(new AuditAgent())
            ->through($data);
    }
}
```

### 3. Team Guidelines

```php
// Establish clear patterns in your team:

// For HTTP middleware and request processing
use Illuminate\Pipeline\Pipeline;

// For background jobs and business workflows  
use Vampires\Sentinels\Facades\Sentinels;

// For simple data transformations
use Illuminate\Pipeline\Pipeline;

// For complex processes requiring observability
use Vampires\Sentinels\Facades\Sentinels;
```

## Conclusion

Laravel's Pipeline and Sentinels Pipeline serve different purposes:

- **Laravel Pipeline**: Simple, fast, perfect for middleware and basic transformations
- **Sentinels Pipeline**: Complex workflows, observability, error recovery, team collaboration

Both are excellent tools. Choose based on your specific needs, and don't hesitate to use both in the same application for different purposes.

The key is matching the tool to the job complexity and requirements.