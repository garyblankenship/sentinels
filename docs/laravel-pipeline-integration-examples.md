# Laravel Pipeline Integration Examples

This file demonstrates how to integrate Laravel's Pipeline with Sentinels, providing practical examples for common use cases.

## Basic Laravel Pipeline Bridge

```php
use Vampires\Sentinels\Facades\Sentinels;
use Vampires\Sentinels\Agents\LaravelPipelineAgent;

// Example Laravel pipes
class UppercaseNamePipe
{
    public function handle($data, $next)
    {
        $data['name'] = strtoupper($data['name']);
        return $next($data);
    }
}

class AddTimestampPipe
{
    public function handle($data, $next)
    {
        $data['processed_at'] = now();
        return $next($data);
    }
}

// Using Laravel Pipeline within Sentinels
$result = Sentinels::pipeline()
    ->pipe(new LaravelPipelineAgent([
        UppercaseNamePipe::class,
        AddTimestampPipe::class,
    ]))
    ->through(['name' => 'john doe']);

echo $result['name']; // "JOHN DOE"
echo $result['processed_at']; // Current timestamp
```

## Fluent Factory Methods

```php
// Using the facade helper
$result = Sentinels::pipeline()
    ->pipe(Sentinels::laravelPipeline([
        UppercaseNamePipe::class,
        AddTimestampPipe::class,
    ]))
    ->through($data);

// Or with fluent pipes
$result = Sentinels::pipeline()
    ->pipe(Sentinels::throughLaravelPipes(
        UppercaseNamePipe::class,
        AddTimestampPipe::class
    ))
    ->through($data);
```

## Mixing Laravel Pipes with Sentinels Agents

```php
use Vampires\Sentinels\Agents\BaseAgent;

class ValidateUserAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $user = $context->payload;
        
        if (empty($user['email'])) {
            return $context->addError('Email is required');
        }
        
        return $context->withTag('validated');
    }
}

class AuditLogAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        logger()->info('User processed', [
            'correlation_id' => $context->correlationId,
            'user_id' => $context->payload['id'] ?? 'unknown',
        ]);
        
        return $context;
    }
}

// Complex workflow combining both approaches
$result = Sentinels::pipeline()
    ->pipe(new ValidateUserAgent())                    // Sentinels agent
    ->pipe(Sentinels::laravelPipeline([                // Laravel pipes
        UppercaseNamePipe::class,
        'normalize_email',  // String pipe reference
        function ($data, $next) {                       // Closure pipe
            $data['slug'] = Str::slug($data['name']);
            return $next($data);
        }
    ]))
    ->pipe(new AuditLogAgent())                        // Back to Sentinels
    ->through($userData);
```

## Error Handling Between Systems

```php
// Laravel Pipeline errors are automatically caught and converted
$result = Sentinels::pipeline()
    ->pipe(Sentinels::laravelPipeline([
        function ($data, $next) {
            if (!isset($data['required_field'])) {
                throw new InvalidArgumentException('Missing required field');
            }
            return $next($data);
        }
    ]))
    ->onError(function (Context $context, \Throwable $exception) {
        // This will catch Laravel Pipeline exceptions
        logger()->error('Pipeline failed', [
            'error' => $exception->getMessage(),
            'correlation_id' => $context->correlationId,
        ]);
        
        return $context->addError('Data validation failed');
    })
    ->through($invalidData);

if ($result->hasErrors()) {
    echo "Errors: " . implode(', ', $result->errors);
}
```

## Request Processing Example

```php
// HTTP middleware-style processing with Sentinels observability
class AuthenticateUserPipe
{
    public function handle($request, $next)
    {
        if (!$request->user()) {
            throw new UnauthorizedException('User not authenticated');
        }
        return $next($request);
    }
}

class RateLimitPipe
{
    public function handle($request, $next)
    {
        if (!RateLimiter::attempt($request->ip())) {
            throw new TooManyRequestsException('Rate limit exceeded');
        }
        return $next($request);
    }
}

// Process request with Laravel middleware + Sentinels features
$response = Sentinels::pipeline()
    ->pipe(Sentinels::laravelPipeline([
        AuthenticateUserPipe::class,
        RateLimitPipe::class,
    ]))
    ->pipe(new BusinessLogicAgent())
    ->pipe(new ResponseFormatterAgent())
    ->onError(function (Context $context, \Throwable $exception) {
        return $context->with([
            'error' => $exception->getMessage(),
            'status' => 'failed'
        ]);
    })
    ->through($request);
```

## Data Transformation Pipeline

```php
// Complex data processing with multiple transformation stages
class JsonDecodePipe
{
    public function handle($data, $next)
    {
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        return $next($data);
    }
}

class ValidateStructurePipe
{
    public function handle($data, $next)
    {
        if (!is_array($data) || !isset($data['items'])) {
            throw new InvalidArgumentException('Invalid data structure');
        }
        return $next($data);
    }
}

// Process incoming data with validation and transformation
$result = Sentinels::pipeline()
    ->pipe(Sentinels::laravelPipeline([
        JsonDecodePipe::class,
        ValidateStructurePipe::class,
        function ($data, $next) {
            // Normalize item structure
            $data['items'] = array_map(function ($item) {
                return array_merge(['status' => 'pending'], $item);
            }, $data['items']);
            return $next($data);
        }
    ]))
    ->pipe(new ProcessItemsAgent())
    ->pipe(new SaveToStorageAgent())
    ->pipe(new SendNotificationAgent())
    ->mode('parallel')  // Process, save, and notify in parallel
    ->through($incomingJsonData);
```

## Conditional Laravel Pipeline Usage

```php
// Use Laravel Pipeline conditionally based on data type
$result = Sentinels::pipeline()
    ->pipe(new DetectDataTypeAgent())
    ->branch(
        condition: fn(Context $ctx) => $ctx->hasTag('simple-data'),
        truePipeline: Sentinels::pipeline()
            ->pipe(Sentinels::laravelPipeline([
                'simple_transformation_1',
                'simple_transformation_2',
            ])),
        falsePipeline: Sentinels::pipeline()
            ->pipe(new ComplexProcessingAgent())
            ->pipe(new AdvancedValidationAgent())
    )
    ->through($data);
```

## Performance Comparison

```php
// Benchmark Laravel Pipeline vs Sentinels for simple transformations
$simpleData = ['name' => 'john', 'email' => 'JOHN@EXAMPLE.COM'];

// Laravel Pipeline (faster for simple transformations)
$start = microtime(true);
$laravelResult = app(LaravelPipeline::class)
    ->send($simpleData)
    ->through([
        fn($data, $next) => $next(array_merge($data, ['name' => ucfirst($data['name'])])),
        fn($data, $next) => $next(array_merge($data, ['email' => strtolower($data['email'])])),
    ])
    ->thenReturn();
$laravelTime = microtime(true) - $start;

// Sentinels with Laravel Pipeline bridge
$start = microtime(true);
$sentinelsResult = Sentinels::pipeline()
    ->pipe(Sentinels::laravelPipeline([
        fn($data, $next) => $next(array_merge($data, ['name' => ucfirst($data['name'])])),
        fn($data, $next) => $next(array_merge($data, ['email' => strtolower($data['email'])])),
    ]))
    ->through($simpleData);
$sentinelsTime = microtime(true) - $start;

echo "Laravel Pipeline: {$laravelTime}s\n";
echo "Sentinels Pipeline: {$sentinelsTime}s\n";
echo "Overhead: " . round(($sentinelsTime - $laravelTime) * 1000, 2) . "ms\n";
```

## Migration Strategy

```php
// Step 1: Wrap existing Laravel Pipeline in Sentinels
class LegacyDataProcessor
{
    protected array $pipes = [
        ValidateDataPipe::class,
        TransformDataPipe::class,
        FormatDataPipe::class,
    ];

    public function process($data)
    {
        // Old Laravel Pipeline approach
        return app(LaravelPipeline::class)
            ->send($data)
            ->through($this->pipes)
            ->thenReturn();
    }
}

class NewDataProcessor
{
    public function process($data)
    {
        // Migrated to Sentinels with Laravel Pipeline bridge
        return Sentinels::pipeline()
            ->pipe(Sentinels::laravelPipeline([
                ValidateDataPipe::class,
                TransformDataPipe::class,
                FormatDataPipe::class,
            ]))
            ->through($data);
    }
}

// Step 2: Gradually replace Laravel pipes with Sentinels agents
class EvolutionDataProcessor
{
    public function process($data)
    {
        return Sentinels::pipeline()
            ->pipe(new ValidateDataAgent())           // Converted to agent
            ->pipe(Sentinels::laravelPipeline([       // Still using Laravel
                TransformDataPipe::class,
                FormatDataPipe::class,
            ]))
            ->pipe(new AuditLogAgent())               // New Sentinels features
            ->through($data);
    }
}

// Step 3: Full Sentinels implementation with rich features
class ModernDataProcessor
{
    public function process($data)
    {
        return Sentinels::pipeline()
            ->pipe(new ValidateDataAgent())
            ->pipe(new TransformDataAgent())
            ->pipe(new FormatDataAgent())
            ->pipe(new AuditLogAgent())
            ->mode('parallel')
            ->async()
            ->onError(new RetryWithBackoffPolicy())
            ->through($data);
    }
}
```

## Testing Both Approaches

```php
use PHPUnit\Framework\TestCase;

class PipelineIntegrationTest extends TestCase
{
    public function test_laravel_pipeline_agent_executes_successfully()
    {
        $agent = Sentinels::laravelPipeline([
            fn($data, $next) => $next(array_merge($data, ['processed' => true])),
        ]);
        
        $result = Sentinels::pipeline()
            ->pipe($agent)
            ->through(['name' => 'test']);
            
        $this->assertTrue($result['processed']);
        $this->assertEquals('test', $result['name']);
    }
    
    public function test_laravel_pipeline_errors_are_handled()
    {
        $context = Sentinels::pipeline()
            ->pipe(Sentinels::laravelPipeline([
                function ($data, $next) {
                    throw new \RuntimeException('Test error');
                }
            ]))
            ->process(Sentinels::context(['test' => 'data']));
            
        $this->assertTrue($context->hasErrors());
        $this->assertStringContains('Laravel Pipeline failed', $context->errors[0]);
    }
}
```

## Best Practices

### 1. Choose the Right Tool for the Job

```php
// Simple transformations: Use Laravel Pipeline directly
$simple = app(LaravelPipeline::class)
    ->send($data)
    ->through($simplePipes)
    ->thenReturn();

// Complex workflows: Use Sentinels
$complex = Sentinels::pipeline()
    ->pipe($complexAgents)
    ->mode('parallel')
    ->onError($errorHandler)
    ->through($data);

// Mixed complexity: Use both
$mixed = Sentinels::pipeline()
    ->pipe(Sentinels::laravelPipeline($simplePipes))  // Simple part
    ->pipe($complexAgents)                            // Complex part
    ->through($data);
```

### 2. Preserve Context Information

```php
// When using Laravel Pipeline bridge, metadata is preserved
$result = Sentinels::pipeline()
    ->pipe(new AddMetadataAgent())                    // Adds metadata
    ->pipe(Sentinels::laravelPipeline($pipes))        // Processes payload
    ->pipe(new UseMetadataAgent())                    // Can access metadata
    ->through($data);
```

### 3. Error Recovery Strategies

```php
// Implement fallback for Laravel Pipeline failures
$result = Sentinels::pipeline()
    ->pipe(Sentinels::laravelPipeline($primaryPipes))
    ->onError(function (Context $context, \Throwable $exception) {
        // Fallback to simpler processing
        return Sentinels::pipeline()
            ->pipe(Sentinels::laravelPipeline($fallbackPipes))
            ->process($context);
    })
    ->through($data);
```

This integration allows you to leverage the best of both worlds: Laravel's simple, fast Pipeline for straightforward transformations, and Sentinels' rich feature set for complex business workflows.