# Pipelines Deep Dive

Pipelines are the orchestration layer of Sentinels. They define how agents are executed, in what order, and under what conditions. This guide covers all pipeline patterns and execution modes.

## Basic Pipeline Creation

### Using the Facade

The simplest way to create pipelines:

```php
use Vampires\Sentinels\Facades\Sentinels;

$result = Sentinels::pipeline()
    ->pipe(new ValidateOrderAgent())
    ->pipe(new ProcessPaymentAgent())
    ->pipe(new SendEmailAgent())
    ->through($order);
```

### Using Pipeline Class

For more control, use the Pipeline class directly:

```php
use Vampires\Sentinels\Pipeline\Pipeline;
use Vampires\Sentinels\Core\Context;

$mediator = app(AgentMediator::class);
$pipeline = new Pipeline($mediator, app('events'));

$context = Context::create($order);
$result = $pipeline
    ->pipe(new ValidateOrderAgent())
    ->pipe(new ProcessPaymentAgent())
    ->process($context);
```

### Static Factory Method

```php
$pipeline = Pipeline::create()
    ->pipe(new ValidateOrderAgent())
    ->pipe(new ProcessPaymentAgent());
    
$result = $pipeline->through($order);
```

## Execution Modes

Pipelines support different execution modes for various use cases.

### Sequential Mode (Default)

Agents execute one after another, with each receiving the output of the previous:

```php
$result = Sentinels::pipeline()
    ->mode('sequential') // Default, can be omitted
    ->pipe(new ValidateOrderAgent())     // Output: validated order
    ->pipe(new ProcessPaymentAgent())    // Input: validated order
    ->pipe(new UpdateInventoryAgent())   // Input: order with payment
    ->through($order);
```

### Parallel Mode

Agents execute simultaneously, all receiving the same input:

```php
$result = Sentinels::pipeline()
    ->mode('parallel')
    ->pipe(new SendEmailAgent())         // All receive original order
    ->pipe(new UpdateAnalyticsAgent())   // All execute at same time
    ->pipe(new LogAuditAgent())          // Results are merged
    ->through($order);
```

### Map-Reduce Mode

Transform collections of data through all agents:

```php
$orders = Order::where('status', 'pending')->get();

$results = Sentinels::pipeline()
    ->mode('map_reduce')
    ->pipe(new ValidateOrderAgent())
    ->pipe(new ProcessPaymentAgent())
    ->through($orders); // Each order processed through all agents
```

## Pipeline Configuration

### Timeout Settings

Set maximum execution time:

```php
$result = Sentinels::pipeline()
    ->timeout(120) // 120 seconds max
    ->pipe(new SlowApiCallAgent())
    ->through($data);
```

### Error Handling

Define how pipelines handle errors:

```php
$result = Sentinels::pipeline()
    ->pipe(new ValidateOrderAgent())
    ->pipe(new ProcessPaymentAgent())
    ->onError(function (Context $context, \Throwable $exception) {
        // Log error with context
        logger()->error('Pipeline failed', [
            'correlation_id' => $context->correlationId,
            'error' => $exception->getMessage(),
            'stage' => 'payment_processing',
            'payload_type' => get_class($context->payload)
        ]);
        
        // Return recovery context or re-throw
        return $context
            ->addError('Payment processing failed')
            ->withMetadata('error_recovery', 'initiated');
    })
    ->through($order);
```

### Success Callbacks

Execute code after successful completion:

```php
$result = Sentinels::pipeline()
    ->pipe(new ProcessOrderAgent())
    ->onSuccess(function (Context $context) {
        // Log successful processing
        logger()->info('Order processed successfully', [
            'order_id' => $context->payload->id,
            'correlation_id' => $context->correlationId,
            'execution_time' => $context->getElapsedTime()
        ]);
        
        // Trigger additional events
        event(new OrderProcessed($context->payload));
    })
    ->through($order);
```

## Advanced Pipeline Patterns

### Conditional Branching

Execute different agents based on conditions:

```php
$pipeline = Sentinels::pipeline()
    ->pipe(new ValidateOrderAgent())
    ->branch(
        condition: fn(Context $context) => $context->payload->total > 1000,
        truePipeline: Pipeline::create()
            ->pipe(new VipProcessingAgent())
            ->pipe(new PriorityShippingAgent()),
        falsePipeline: Pipeline::create()
            ->pipe(new StandardProcessingAgent())
            ->pipe(new RegularShippingAgent())
    )
    ->pipe(new SendConfirmationAgent());

$result = $pipeline->through($order);
```

### Nested Pipelines

Compose pipelines within pipelines:

```php
// Create sub-pipeline for payment processing
$paymentPipeline = Pipeline::create()
    ->pipe(new ValidatePaymentAgent())
    ->pipe(new ProcessCreditCardAgent())
    ->pipe(new SendPaymentReceiptAgent());

// Use it in main pipeline
$result = Sentinels::pipeline()
    ->pipe(new ValidateOrderAgent())
    ->pipe($paymentPipeline)          // Nested pipeline
    ->pipe(new UpdateInventoryAgent())
    ->through($order);
```

### Map Operations

Transform collections with custom logic:

```php
$numbers = [1, 2, 3, 4, 5];

$doubled = Sentinels::pipeline()
    ->map(function ($item) {
        return $item * 2;
    })
    ->through($numbers);

// Result: [2, 4, 6, 8, 10]
```

### Reduce Operations

Aggregate data across pipeline stages:

```php
$orders = Order::pending()->get();

// First, transform orders through pipeline
$processedOrders = Sentinels::pipeline()
    ->pipe(new CalculateOrderValueAgent())
    ->through($orders);

// Then reduce to get total value
$totalValue = Sentinels::pipeline()
    ->reduce(function ($carry, $order) {
        return $carry + $order->calculated_value;
    }, 0)
    ->through($processedOrders);
```

### Pipeline Middleware

Wrap entire pipelines with cross-cutting concerns:

```php
class AuthenticationMiddleware
{
    public function handle(Context $context, \Closure $next): Context
    {
        if (!auth()->check()) {
            throw new UnauthorizedException('User not authenticated');
        }
        
        return $next($context->withMetadata('user_id', auth()->id()));
    }
}

$result = Sentinels::pipeline()
    ->middleware(new AuthenticationMiddleware())
    ->pipe(new ProcessSecureOrderAgent())
    ->through($order);
```

### Dynamic Pipeline Composition

Build pipelines at runtime based on conditions:

```php
class OrderPipelineBuilder
{
    public function build(Order $order): Pipeline
    {
        $pipeline = Sentinels::pipeline()
            ->pipe(new ValidateOrderAgent());
            
        // Add payment processing based on order type
        if ($order->payment_type === 'credit_card') {
            $pipeline->pipe(new CreditCardAgent());
        } elseif ($order->payment_type === 'bank_transfer') {
            $pipeline->pipe(new BankTransferAgent());
        }
        
        // Add shipping for physical products
        if ($order->hasPhysicalProducts()) {
            $pipeline->pipe(new CalculateShippingAgent())
                    ->pipe(new ScheduleDeliveryAgent());
        }
        
        // Always send confirmation
        $pipeline->pipe(new SendConfirmationAgent());
        
        return $pipeline;
    }
}

// Usage
$builder = new OrderPipelineBuilder();
$pipeline = $builder->build($order);
$result = $pipeline->through($order);
```

## Pipeline Observability

### Pipeline Events

Sentinels fires events at key pipeline lifecycle points:

```php
// Listen for pipeline events
Event::listen(PipelineStarted::class, function ($event) {
    logger()->info('Pipeline started', [
        'correlation_id' => $event->context->correlationId,
        'stage_count' => $event->pipeline->getStageCount()
    ]);
});

Event::listen(AgentCompleted::class, function ($event) {
    logger()->info('Agent completed', [
        'agent' => $event->agent->getName(),
        'execution_time' => $event->context->getElapsedTime(),
        'correlation_id' => $event->context->correlationId
    ]);
});
```

### Pipeline Statistics

Access execution statistics:

```php
$pipeline = Sentinels::pipeline()
    ->pipe(new ValidateOrderAgent())
    ->pipe(new ProcessPaymentAgent());

// Get pipeline info before execution
$stats = $pipeline->getStats();
/*
[
    'stage_count' => 2,
    'estimated_time' => 1500, // milliseconds
    'has_branches' => false,
    'middleware_count' => 0,
    'mode' => 'sequential'
]
*/

$result = $pipeline->through($order);

// Access execution timing
$executionTime = $result->getElapsedTime(); // seconds
$metadata = $result->metadata; // All execution metadata
```

### Custom Metrics

Add custom metrics collection:

```php
class MetricsCollectionAgent extends BaseAgent
{
    protected function afterExecute(Context $originalContext, Context $result): Context
    {
        // Collect custom metrics
        Metrics::increment('agents.executed', [
            'agent' => $this->getName(),
            'status' => 'success'
        ]);
        
        Metrics::timing('agents.execution_time', $result->getElapsedTime(), [
            'agent' => $this->getName()
        ]);
        
        return $result;
    }
    
    protected function onError(Context $context, \Throwable $exception): Context
    {
        Metrics::increment('agents.executed', [
            'agent' => $this->getName(),
            'status' => 'error'
        ]);
        
        return parent::onError($context, $exception);
    }
}
```

## Pipeline Testing

### Unit Testing Pipelines

Test pipeline composition and flow:

```php
class OrderPipelineTest extends TestCase
{
    public function test_processes_order_through_complete_pipeline()
    {
        $order = Order::factory()->create();
        
        $result = Sentinels::pipeline()
            ->pipe(new ValidateOrderAgent())
            ->pipe(new ProcessPaymentAgent())
            ->pipe(new SendEmailAgent())
            ->through($order);
            
        // Assert pipeline completed successfully
        $this->assertInstanceOf(Order::class, $result);
        $this->assertEquals('processed', $result->status);
        
        // Assert emails were sent
        Mail::assertSent(OrderProcessed::class);
    }
    
    public function test_handles_validation_errors()
    {
        $invalidOrder = Order::factory()->invalid()->create();
        
        $pipeline = Sentinels::pipeline()
            ->pipe(new ValidateOrderAgent())
            ->onError(function (Context $context, \Throwable $exception) {
                return $context->addError($exception->getMessage());
            });
            
        $result = $pipeline->process(Context::create($invalidOrder));
        
        $this->assertTrue($result->hasErrors());
        $this->assertStringContains('validation', implode(' ', $result->errors));
    }
}
```

### Integration Testing

Test pipelines with real dependencies:

```php
/**
 * @group integration
 */
class OrderProcessingIntegrationTest extends TestCase
{
    public function test_processes_real_order_end_to_end()
    {
        // Setup real payment gateway (test mode)
        config(['services.stripe.key' => env('STRIPE_TEST_KEY')]);
        
        $order = Order::factory()->create([
            'payment_method' => 'stripe',
            'total' => 99.99
        ]);
        
        $result = Sentinels::pipeline()
            ->pipe(new ValidateOrderAgent())
            ->pipe(new StripePaymentAgent())      // Real API call
            ->pipe(new UpdateInventoryAgent())    // Real database
            ->pipe(new SendEmailAgent())          // Real email
            ->through($order);
            
        // Verify real changes were made
        $this->assertEquals('paid', $order->fresh()->payment_status);
        $this->assertDatabaseHas('inventory', [
            'product_id' => $order->items->first()->product_id,
            'quantity' => $this->originalStock - $order->items->first()->quantity
        ]);
    }
}
```

### Performance Testing

Test pipeline performance under load:

```php
class PipelinePerformanceTest extends TestCase
{
    public function test_pipeline_performance_under_load()
    {
        $orders = Order::factory()->count(100)->create();
        
        $startTime = microtime(true);
        
        $results = Sentinels::pipeline()
            ->mode('parallel')
            ->pipe(new FastValidationAgent())
            ->pipe(new CachedLookupAgent())
            ->through($orders);
            
        $executionTime = microtime(true) - $startTime;
        
        // Assert performance requirements
        $this->assertLessThan(5.0, $executionTime); // Under 5 seconds
        $this->assertCount(100, $results);
    }
    
    public function test_sequential_vs_parallel_performance()
    {
        $data = range(1, 50);
        
        // Test sequential
        $sequentialStart = microtime(true);
        Sentinels::pipeline()
            ->mode('sequential')
            ->pipe(new SlowProcessingAgent())
            ->through($data);
        $sequentialTime = microtime(true) - $sequentialStart;
        
        // Test parallel
        $parallelStart = microtime(true);
        Sentinels::pipeline()
            ->mode('parallel')
            ->pipe(new SlowProcessingAgent())
            ->through($data);
        $parallelTime = microtime(true) - $parallelStart;
        
        // Parallel should be significantly faster
        $this->assertLessThan($sequentialTime * 0.5, $parallelTime);
    }
}
```

## Best Practices

### 1. Pipeline Composition

Keep pipelines focused and composable:

```php
// ✅ Good - focused pipelines
class OrderPipelines
{
    public static function validation(): Pipeline
    {
        return Pipeline::create()
            ->pipe(new ValidateOrderDataAgent())
            ->pipe(new CheckBusinessRulesAgent());
    }
    
    public static function payment(): Pipeline
    {
        return Pipeline::create()
            ->pipe(new ProcessPaymentAgent())
            ->pipe(new SendPaymentReceiptAgent());
    }
    
    public static function fulfillment(): Pipeline
    {
        return Pipeline::create()
            ->pipe(new UpdateInventoryAgent())
            ->pipe(new ScheduleShippingAgent());
    }
}

// Compose them together
$result = Sentinels::pipeline()
    ->pipe(OrderPipelines::validation())
    ->pipe(OrderPipelines::payment())
    ->pipe(OrderPipelines::fulfillment())
    ->through($order);
```

### 2. Error Boundaries

Isolate errors to prevent cascade failures:

```php
$result = Sentinels::pipeline()
    ->pipe(new CriticalValidationAgent()) // Must succeed
    ->pipe(Pipeline::create()              // Optional processing
        ->pipe(new OptionalAnalyticsAgent())
        ->pipe(new OptionalRecommendationAgent())
        ->onError(fn($ctx, $e) => $ctx->addError("Optional processing failed: {$e->getMessage()}"))
    )
    ->pipe(new RequiredFinalAgent())       // Must succeed
    ->through($order);
```

### 3. Resource Management

Manage resources efficiently in long-running pipelines:

```php
class ResourceManagedPipeline
{
    public function process(Collection $orders): Collection
    {
        return $orders->chunk(50)->map(function ($chunk) {
            return Sentinels::pipeline()
                ->pipe(new DatabaseConnectionAgent()) // Manages connections
                ->pipe(new MemoryEfficientAgent())    // Cleans up after itself
                ->onSuccess(function (Context $context) {
                    // Force garbage collection for large datasets
                    if (memory_get_usage() > 100 * 1024 * 1024) { // 100MB
                        gc_collect_cycles();
                    }
                })
                ->through($chunk);
        })->flatten();
    }
}
```

### 4. Configuration Management

Externalize pipeline configuration:

```php
// config/pipelines.php
return [
    'order_processing' => [
        'timeout' => 120,
        'mode' => 'sequential',
        'agents' => [
            ValidateOrderAgent::class,
            ProcessPaymentAgent::class,
            SendEmailAgent::class,
        ],
        'error_handlers' => [
            PaymentFailedException::class => RecoverPaymentErrorAgent::class,
        ]
    ]
];

// PipelineFactory
class PipelineFactory
{
    public function create(string $name): Pipeline
    {
        $config = config("pipelines.{$name}");
        
        $pipeline = Sentinels::pipeline()
            ->timeout($config['timeout'])
            ->mode($config['mode']);
            
        foreach ($config['agents'] as $agentClass) {
            $pipeline->pipe(app($agentClass));
        }
        
        return $pipeline;
    }
}
```

## Next Steps

- **[Master Context](context.md)** - Work effectively with immutable context
- **[Handle Errors](error-handling.md)** - Build robust error recovery
- **[Write Tests](testing.md)** - Test pipelines effectively
- **[See Examples](examples.md)** - Real-world pipeline patterns