# Understanding Context

Context is the heart of Sentinels' immutable data flow. It carries your data through the pipeline while maintaining traceability, metadata, and state. This guide covers everything you need to know to work effectively with Context.

## The Immutable Context Pattern

Context objects are **immutable** - once created, they cannot be changed. Every modification returns a new Context instance. This provides several benefits:

- **Thread Safety**: Multiple agents can safely access the same context
- **Audit Trail**: Every change is tracked through new instances
- **Debugging**: You can trace exactly how data flowed through the pipeline
- **Rollback**: Previous states are preserved for error recovery

## Context Anatomy

```php
use Vampires\Sentinels\Core\Context;

$context = new Context(
    payload: $order,                    // Your data
    metadata: ['source' => 'api'],      // Additional information
    correlationId: 'uuid-here',         // Unique identifier (auto-generated)
    tags: ['urgent', 'vip'],           // Classification labels
    traceId: 'trace-123',              // Distributed tracing ID
    cancelled: false,                   // Execution control
    errors: [],                         // Error accumulation
    startTime: microtime(true)          // Execution timing
);
```

## Creating Context

### Basic Creation

```php
// Simple payload
$context = Context::create($order);

// With metadata
$context = new Context(
    payload: $order,
    metadata: ['user_id' => 123, 'source' => 'web']
);

// Static factory method
$context = Context::create($order)
    ->withMetadata('priority', 'high')
    ->withTag('express');
```

### From HTTP Requests

```php
class OrderController extends Controller
{
    public function process(Request $request, Order $order)
    {
        $context = Context::create($order)
            ->withMetadata('user_id', auth()->id())
            ->withMetadata('ip_address', $request->ip())
            ->withMetadata('user_agent', $request->userAgent())
            ->withTag('web_request');
            
        $result = Sentinels::pipeline()
            ->pipe(new ProcessOrderAgent())
            ->process($context);
            
        return response()->json($result->payload);
    }
}
```

### From Queue Jobs

```php
class ProcessOrderJob implements ShouldQueue
{
    public function handle(Order $order)
    {
        $context = Context::create($order)
            ->withMetadata('queue', 'orders')
            ->withMetadata('attempts', $this->attempts())
            ->withTag('queued')
            ->withTag('background');
            
        Sentinels::pipeline()
            ->pipe(new ProcessOrderAgent())
            ->process($context);
    }
}
```

## Working with Payload

The payload is your main data that flows through the pipeline:

```php
class TransformOrderAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $order = $context->payload;
        
        // Transform the order
        $transformedOrder = $this->transform($order);
        
        // Return new context with updated payload
        return $context->with($transformedOrder);
    }
}
```

### Type Safety

Ensure payload types for robust pipelines:

```php
class TypeSafeAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        // Assert expected type
        if (!$context->payload instanceof Order) {
            throw new InvalidArgumentException(
                'Expected Order, got ' . get_class($context->payload)
            );
        }
        
        $order = $context->payload;
        // Safe to use order methods now
        
        return $context->with($order);
    }
}
```

### Payload Validation

Use the validation system for complex payloads:

```php
class OrderValidationAgent extends BaseAgent
{
    protected function validatePayload(Context $context): ValidationResult
    {
        $order = $context->payload;
        
        if (!$order instanceof Order) {
            return ValidationResult::invalid(['payload' => ['Must be Order instance']]);
        }
        
        if ($order->total <= 0) {
            return ValidationResult::invalid(['total' => ['Must be positive']]);
        }
        
        return ValidationResult::valid($order);
    }
    
    protected function handle(Context $context): Context
    {
        // Validation passed, mark as validated
        return $context->withTag('validated');
    }
}
```

## Metadata Management

Metadata carries additional information alongside your payload:

### Adding Metadata

```php
// Single metadata value
$context = $context->withMetadata('processed_at', now());

// Multiple metadata values
$context = $context->withMergedMetadata([
    'processed_by' => auth()->user()->name,
    'processing_time' => 1.23,
    'version' => '2.0'
]);
```

### Reading Metadata

```php
// Get specific metadata
$processedBy = $context->getMetadata('processed_by');

// Get with default value
$priority = $context->getMetadata('priority', 'normal');

// Check if metadata exists
if ($context->hasMetadata('user_id')) {
    $userId = $context->getMetadata('user_id');
}

// Get all metadata
$allMetadata = $context->metadata;
```

### Metadata Patterns

```php
class MetadataPatterns
{
    // Timing information
    public function addTimingMetadata(Context $context): Context
    {
        return $context->withMergedMetadata([
            'started_at' => now()->toISOString(),
            'processing_node' => gethostname(),
            'memory_usage' => memory_get_usage(true),
        ]);
    }
    
    // User context
    public function addUserContext(Context $context): Context
    {
        $user = auth()->user();
        
        return $context->withMergedMetadata([
            'user_id' => $user->id,
            'user_role' => $user->role,
            'tenant_id' => $user->tenant_id,
        ]);
    }
    
    // Request context
    public function addRequestContext(Context $context, Request $request): Context
    {
        return $context->withMergedMetadata([
            'request_id' => $request->header('X-Request-ID'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'route' => $request->route()->getName(),
        ]);
    }
}
```

## Tags and Classification

Tags provide a simple way to classify and filter contexts:

### Adding Tags

```php
// Single tag
$context = $context->withTag('priority');

// Multiple tags
$context = $context->withTags(['urgent', 'vip', 'express']);

// Conditional tagging
if ($order->total > 1000) {
    $context = $context->withTag('high_value');
}
```

### Using Tags

```php
// Check for tags
if ($context->hasTag('urgent')) {
    // Handle urgently
}

// Get all tags
$tags = $context->tags;

// Tag-based routing
class TagBasedRoutingAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        if ($context->hasTag('vip')) {
            return $this->processVip($context);
        }
        
        if ($context->hasTag('bulk')) {
            return $this->processBulk($context);
        }
        
        return $this->processStandard($context);
    }
}
```

### Tag Conventions

Establish consistent tagging patterns:

```php
class TagConventions
{
    // Priority levels
    const PRIORITY_LOW = 'priority:low';
    const PRIORITY_NORMAL = 'priority:normal';
    const PRIORITY_HIGH = 'priority:high';
    const PRIORITY_URGENT = 'priority:urgent';
    
    // Data sources
    const SOURCE_API = 'source:api';
    const SOURCE_WEB = 'source:web';
    const SOURCE_BATCH = 'source:batch';
    
    // Processing stages
    const STAGE_VALIDATION = 'stage:validation';
    const STAGE_PROCESSING = 'stage:processing';
    const STAGE_COMPLETION = 'stage:completion';
    
    public static function addPriorityTag(Context $context, string $priority): Context
    {
        return match ($priority) {
            'low' => $context->withTag(self::PRIORITY_LOW),
            'high' => $context->withTag(self::PRIORITY_HIGH),
            'urgent' => $context->withTag(self::PRIORITY_URGENT),
            default => $context->withTag(self::PRIORITY_NORMAL),
        };
    }
}
```

## Correlation and Tracing

### Correlation IDs

Every context has a unique correlation ID for tracing requests:

```php
// Automatically generated UUID
$context = Context::create($order);
echo $context->correlationId; // "9d4e5f2a-8b1c-4d3e-9f0a-1b2c3d4e5f6a"

// Custom correlation ID
$context = new Context($order, correlationId: 'custom-id-123');

// Propagate correlation ID
class LoggingAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        logger()->info('Processing order', [
            'correlation_id' => $context->correlationId,
            'order_id' => $context->payload->id,
        ]);
        
        return $context;
    }
}
```

### Distributed Tracing

Use trace IDs for distributed system tracing:

```php
// Set trace ID from incoming request
$traceId = $request->header('X-Trace-ID') ?? Str::uuid();
$context = Context::create($order)->withTraceId($traceId);

// Propagate to external services
class ExternalApiAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $response = Http::withHeaders([
            'X-Correlation-ID' => $context->correlationId,
            'X-Trace-ID' => $context->traceId,
        ])->post('https://api.example.com/process', [
            'order' => $context->payload->toArray()
        ]);
        
        return $context->withMetadata('external_response', $response->json());
    }
}
```

## Error Handling

Context accumulates errors without stopping execution:

### Adding Errors

```php
// Single error
$context = $context->addError('Payment processing failed');

// Multiple errors
$context = $context->addErrors([
    'Invalid email address',
    'Insufficient inventory',
    'Payment method expired'
]);

// Structured errors
$context = $context->addError(json_encode([
    'code' => 'PAYMENT_FAILED',
    'message' => 'Credit card declined',
    'gateway_response' => $gatewayError
]));
```

### Checking for Errors

```php
// Check if context has errors
if ($context->hasErrors()) {
    logger()->error('Pipeline errors detected', [
        'correlation_id' => $context->correlationId,
        'errors' => $context->errors
    ]);
}

// Get error count
$errorCount = count($context->errors);

// Error-based routing
class ErrorHandlingAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        if ($context->hasErrors()) {
            return $this->handleErrorRecovery($context);
        }
        
        return $this->processNormally($context);
    }
}
```

## State Management

### Cancellation

Stop pipeline execution by cancelling context:

```php
class ConditionalAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $order = $context->payload;
        
        // Cancel if order is already processed
        if ($order->status === 'completed') {
            return $context->cancel();
        }
        
        return $context; // Continue processing
    }
}

// Check for cancellation
if ($context->isCancelled()) {
    // Handle cancellation
    return $this->handleCancellation($context);
}
```

### Empty Check

Check if context payload is empty:

```php
if ($context->isEmpty()) {
    // Handle empty payload
    return $context->addError('No data to process');
}
```

## Context Serialization

Convert context to/from arrays for storage or transmission:

### To Array

```php
$contextArray = $context->toArray();
/*
[
    'payload' => [...],
    'metadata' => [...],
    'correlationId' => 'uuid',
    'tags' => [...],
    'traceId' => 'trace-id',
    'cancelled' => false,
    'errors' => [...],
    'startTime' => 1640995200.123,
    'elapsedTime' => 1.456
]
*/
```

### Storage Examples

```php
// Store context state in Redis
class ContextStorage
{
    public function store(Context $context): string
    {
        $key = "context:{$context->correlationId}";
        
        Redis::setex($key, 3600, json_encode($context->toArray()));
        
        return $key;
    }
    
    public function retrieve(string $key): ?array
    {
        $data = Redis::get($key);
        
        return $data ? json_decode($data, true) : null;
    }
}

// Database storage
class ContextLog extends Model
{
    protected $casts = [
        'payload' => 'json',
        'metadata' => 'json',
        'tags' => 'json',
        'errors' => 'json',
    ];
    
    public static function fromContext(Context $context): self
    {
        return new self($context->toArray());
    }
}
```

## Performance Considerations

### Payload Size

Monitor payload size for performance:

```php
class PayloadSizeAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $size = $context->getPayloadSize();
        
        if ($size > 1024 * 1024) { // 1MB
            logger()->warning('Large payload detected', [
                'size' => $size,
                'correlation_id' => $context->correlationId
            ]);
        }
        
        return $context->withMetadata('payload_size', $size);
    }
}
```

### Memory Management

For large payloads, consider streaming or chunking:

```php
class LargeDatasetAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $largeDataset = $context->payload;
        
        // Process in chunks to manage memory
        $results = collect($largeDataset)
            ->chunk(1000)
            ->map(function ($chunk) use ($context) {
                return $this->processChunk($chunk, $context);
            });
            
        // Clear memory
        unset($largeDataset);
        gc_collect_cycles();
        
        return $context->with($results->flatten());
    }
}
```

## Best Practices

### 1. Immutability

Always return new contexts, never modify existing ones:

```php
// ❌ Wrong - modifying context directly
protected function handle(Context $context): Context
{
    $context->metadata['processed'] = true; // Don't do this!
    return $context;
}

// ✅ Correct - returning new context
protected function handle(Context $context): Context
{
    return $context->withMetadata('processed', true);
}
```

### 2. Meaningful Metadata

Use descriptive metadata keys and consistent naming:

```php
// ❌ Vague metadata
$context->withMetadata('data', $someValue)
        ->withMetadata('flag', true)
        ->withMetadata('x', $result);

// ✅ Descriptive metadata
$context->withMetadata('external_api_response', $apiResponse)
        ->withMetadata('validation_passed', true)
        ->withMetadata('processing_duration_ms', $duration);
```

### 3. Tag Organization

Use hierarchical tags for better organization:

```php
// ✅ Hierarchical tags
$context->withTags([
    'priority:high',
    'source:api',
    'stage:validation',
    'feature:order_processing'
]);
```

### 4. Error Context

Provide detailed error context:

```php
protected function onError(Context $context, \Throwable $exception): Context
{
    return $context->addError(json_encode([
        'agent' => $this->getName(),
        'error_type' => get_class($exception),
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'payload_type' => get_class($context->payload),
        'metadata_keys' => array_keys($context->metadata)
    ]));
}
```

### 5. Tracing Integration

Integrate with your tracing system:

```php
class TracingAgent extends BaseAgent
{
    protected function beforeExecute(Context $context): Context
    {
        // Start tracing span
        $span = app('tracer')->startSpan($this->getName(), [
            'correlation_id' => $context->correlationId,
            'trace_id' => $context->traceId,
        ]);
        
        return $context->withMetadata('trace_span', $span);
    }
    
    protected function afterExecute(Context $originalContext, Context $result): Context
    {
        // End tracing span
        $span = $originalContext->getMetadata('trace_span');
        if ($span) {
            $span->finish();
        }
        
        return $result;
    }
}
```

## Next Steps

- **[Handle Errors](error-handling.md)** - Build robust error recovery
- **[Write Tests](testing.md)** - Test with context effectively
- **[See Examples](examples.md)** - Real-world context patterns
- **[API Reference](api-reference.md)** - Complete Context API