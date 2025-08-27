# Error Handling and Recovery

Robust error handling is crucial for production pipelines. Sentinels provides multiple layers of error handling, from individual agent recovery to pipeline-wide error strategies. This guide covers all approaches to building resilient workflows.

## Error Handling Layers

Sentinels provides error handling at multiple levels:

1. **Agent Level** - Individual agent error handling
2. **Pipeline Level** - Pipeline-wide error handling
3. **Context Level** - Error accumulation and propagation
4. **System Level** - Global error handling and reporting

## Agent-Level Error Handling

### Basic Error Recovery

Handle errors within individual agents:

```php
class PaymentAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $order = $context->payload;
        
        try {
            $payment = $this->processPayment($order);
            return $context->with($payment);
            
        } catch (PaymentDeclinedException $e) {
            // Handle specific payment errors
            logger()->warning('Payment declined', [
                'order_id' => $order->id,
                'reason' => $e->getMessage(),
                'correlation_id' => $context->correlationId
            ]);
            
            return $context
                ->addError("Payment declined: {$e->getMessage()}")
                ->withMetadata('payment_status', 'declined')
                ->withMetadata('decline_reason', $e->getDeclineReason());
                
        } catch (PaymentGatewayException $e) {
            // Handle gateway errors - might be temporary
            logger()->error('Payment gateway error', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'correlation_id' => $context->correlationId
            ]);
            
            // Don't add error to context - let retry policy handle it
            throw $e;
        }
    }
    
    protected function onError(Context $context, \Throwable $exception): Context
    {
        // This runs when an unhandled exception occurs
        return $context
            ->addError("Payment processing failed: {$exception->getMessage()}")
            ->withMetadata('payment_failed', true)
            ->withMetadata('error_type', get_class($exception));
    }
}
```

### Retry Policies

Configure automatic retry for transient failures:

```php
class ResilientApiAgent extends BaseAgent
{
    public function getRetryPolicy(): ?RetryPolicy
    {
        return RetryPolicy::exponentialBackoff()
            ->maxAttempts(3)                    // Retry up to 3 times
            ->baseDelay(1000)                   // Start with 1 second delay
            ->maxDelay(30000)                   // Max 30 seconds between retries
            ->backoffMultiplier(2.0)            // Double delay each attempt
            ->retryOn([                         // Only retry these exceptions
                ConnectionException::class,
                TimeoutException::class,
                ApiRateLimitException::class,
            ]);
    }
    
    protected function handle(Context $context): Context
    {
        // This will automatically retry on matching exceptions
        $response = $this->callExternalApi($context->payload);
        return $context->with($response);
    }
}
```

### Custom Retry Logic

Implement custom retry logic with conditions:

```php
class ConditionalRetryAgent extends BaseAgent
{
    public function getRetryPolicy(): ?RetryPolicy
    {
        return RetryPolicy::custom()
            ->maxAttempts(5)
            ->shouldRetry(function (\Throwable $exception, int $attempt, Context $context) {
                // Custom retry logic
                if ($exception instanceof RateLimitException) {
                    // Wait longer for rate limits
                    sleep($exception->getRetryAfter());
                    return true;
                }
                
                if ($exception instanceof TemporaryException) {
                    // Only retry first 3 attempts for temporary errors
                    return $attempt <= 3;
                }
                
                return false;
            })
            ->onRetry(function (\Throwable $exception, int $attempt, Context $context) {
                logger()->info('Retrying agent execution', [
                    'agent' => $this->getName(),
                    'attempt' => $attempt,
                    'error' => $exception->getMessage(),
                    'correlation_id' => $context->correlationId
                ]);
            });
    }
}
```

## Pipeline-Level Error Handling

### Error Handlers

Define pipeline-wide error handling:

```php
$result = Sentinels::pipeline()
    ->pipe(new ValidateOrderAgent())
    ->pipe(new ProcessPaymentAgent())
    ->pipe(new SendEmailAgent())
    ->onError(function (Context $context, \Throwable $exception) {
        // Handle any pipeline errors
        logger()->error('Pipeline execution failed', [
            'correlation_id' => $context->correlationId,
            'error' => $exception->getMessage(),
            'stage' => $this->getFailedStage($context),
            'payload_type' => get_class($context->payload)
        ]);
        
        // Return error context or throw to propagate
        return $context
            ->addError("Pipeline failed: {$exception->getMessage()}")
            ->withMetadata('pipeline_failed', true);
    })
    ->through($order);
```

### Multiple Error Handlers

Chain multiple error handlers for different scenarios:

```php
class OrderProcessingPipeline
{
    public function process(Order $order): Context
    {
        return Sentinels::pipeline()
            ->pipe(new ValidateOrderAgent())
            ->pipe(new ProcessPaymentAgent())
            ->pipe(new UpdateInventoryAgent())
            ->pipe(new SendEmailAgent())
            
            // Handle validation errors
            ->onError(ValidationException::class, function (Context $context, ValidationException $e) {
                return $context
                    ->addError("Validation failed: {$e->getMessage()}")
                    ->withMetadata('validation_errors', $e->getErrors());
            })
            
            // Handle payment errors
            ->onError(PaymentException::class, function (Context $context, PaymentException $e) {
                // Initiate refund process
                $this->initiateRefund($context->payload);
                
                return $context
                    ->addError("Payment failed: {$e->getMessage()}")
                    ->withMetadata('refund_initiated', true);
            })
            
            // Handle all other errors
            ->onError(function (Context $context, \Throwable $e) {
                // Generic error handling
                $this->notifySupport($context, $e);
                
                return $context->addError("Unexpected error: {$e->getMessage()}");
            })
            
            ->through($order);
    }
}
```

### Error Boundaries

Create error boundaries to isolate failures:

```php
$result = Sentinels::pipeline()
    ->pipe(new CriticalValidationAgent())     // Must succeed
    
    // Optional processing - failures don't stop pipeline
    ->pipe(
        Sentinels::pipeline()
            ->pipe(new OptionalAnalyticsAgent())
            ->pipe(new OptionalRecommendationAgent())
            ->onError(function (Context $context, \Throwable $e) {
                // Log but don't fail the main pipeline
                logger()->info('Optional processing failed', [
                    'error' => $e->getMessage(),
                    'correlation_id' => $context->correlationId
                ]);
                
                return $context
                    ->withMetadata('analytics_failed', true)
                    ->addError("Optional processing failed: {$e->getMessage()}");
            })
    )
    
    ->pipe(new RequiredFinalAgent())          // Must succeed
    ->through($order);
```

## Error Recovery Patterns

### Circuit Breaker

Prevent cascade failures with circuit breaker pattern:

```php
class CircuitBreakerAgent extends BaseAgent
{
    private static array $circuitStates = [];
    private int $failureThreshold = 5;
    private int $recoveryTimeout = 60; // seconds
    
    protected function handle(Context $context): Context
    {
        $circuitKey = $this->getCircuitKey();
        
        // Check circuit state
        if ($this->isCircuitOpen($circuitKey)) {
            throw new CircuitOpenException('Circuit breaker is open');
        }
        
        try {
            $result = $this->performOperation($context);
            $this->recordSuccess($circuitKey);
            return $result;
            
        } catch (\Throwable $e) {
            $this->recordFailure($circuitKey);
            throw $e;
        }
    }
    
    private function isCircuitOpen(string $circuitKey): bool
    {
        $state = self::$circuitStates[$circuitKey] ?? ['failures' => 0, 'last_failure' => null];
        
        if ($state['failures'] >= $this->failureThreshold) {
            $timeSinceLastFailure = time() - $state['last_failure'];
            return $timeSinceLastFailure < $this->recoveryTimeout;
        }
        
        return false;
    }
    
    private function recordFailure(string $circuitKey): void
    {
        self::$circuitStates[$circuitKey] = [
            'failures' => (self::$circuitStates[$circuitKey]['failures'] ?? 0) + 1,
            'last_failure' => time()
        ];
    }
    
    private function recordSuccess(string $circuitKey): void
    {
        self::$circuitStates[$circuitKey] = ['failures' => 0, 'last_failure' => null];
    }
}
```

### Fallback Data

Provide fallback data when primary operations fail:

```php
class FallbackDataAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        try {
            $data = $this->fetchPrimaryData($context->payload);
            return $context->with($data);
            
        } catch (\Throwable $e) {
            logger()->warning('Primary data source failed, using fallback', [
                'error' => $e->getMessage(),
                'correlation_id' => $context->correlationId
            ]);
            
            $fallbackData = $this->getFallbackData($context->payload);
            
            return $context
                ->with($fallbackData)
                ->withMetadata('data_source', 'fallback')
                ->withMetadata('primary_failure', $e->getMessage());
        }
    }
    
    private function getFallbackData($payload): array
    {
        // Return cached data, default values, or simplified data
        return Cache::remember(
            "fallback_data_{$payload->id}",
            3600,
            fn() => $this->buildFallbackData($payload)
        );
    }
}
```

### Compensating Actions

Implement compensating transactions for rollbacks:

```php
class TransactionalPipeline
{
    private array $compensatingActions = [];
    
    public function process(Order $order): Context
    {
        $context = Context::create($order);
        
        try {
            // Execute main pipeline with compensation tracking
            $result = Sentinels::pipeline()
                ->pipe(new ReserveInventoryAgent($this->compensatingActions))
                ->pipe(new ProcessPaymentAgent($this->compensatingActions))
                ->pipe(new CreateShipmentAgent($this->compensatingActions))
                ->onError(function (Context $context, \Throwable $e) {
                    // Execute compensation actions in reverse order
                    $this->executeCompensation($context, $e);
                    throw $e;
                })
                ->process($context);
                
            return $result;
            
        } catch (\Throwable $e) {
            // Compensation already executed in error handler
            logger()->error('Transaction failed and compensated', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'compensations_executed' => count($this->compensatingActions)
            ]);
            
            throw $e;
        }
    }
    
    private function executeCompensation(Context $context, \Throwable $originalError): void
    {
        foreach (array_reverse($this->compensatingActions) as $action) {
            try {
                $action($context);
            } catch (\Throwable $e) {
                logger()->error('Compensation action failed', [
                    'compensation_error' => $e->getMessage(),
                    'original_error' => $originalError->getMessage(),
                    'correlation_id' => $context->correlationId
                ]);
            }
        }
    }
}

class ReserveInventoryAgent extends BaseAgent
{
    public function __construct(private array &$compensatingActions) {}
    
    protected function handle(Context $context): Context
    {
        $order = $context->payload;
        
        // Reserve inventory
        $reservation = InventoryService::reserve($order->items);
        
        // Add compensation action
        $this->compensatingActions[] = function () use ($reservation) {
            InventoryService::release($reservation);
        };
        
        return $context->withMetadata('inventory_reserved', $reservation->id);
    }
}
```

## Error Reporting and Monitoring

### Structured Error Logging

Implement comprehensive error logging:

```php
class ErrorLoggingAgent extends BaseAgent
{
    protected function onError(Context $context, \Throwable $exception): Context
    {
        $errorData = [
            // Context information
            'correlation_id' => $context->correlationId,
            'trace_id' => $context->traceId,
            'agent' => $this->getName(),
            'payload_type' => get_class($context->payload),
            'payload_id' => $context->payload->id ?? null,
            
            // Error details
            'error_type' => get_class($exception),
            'error_message' => $exception->getMessage(),
            'error_code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            
            // Stack trace
            'stack_trace' => $exception->getTraceAsString(),
            
            // System context
            'memory_usage' => memory_get_usage(true),
            'execution_time' => $context->getElapsedTime(),
            'timestamp' => now()->toISOString(),
            
            // User context
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];
        
        // Log to appropriate channel based on severity
        if ($this->isCriticalError($exception)) {
            logger()->critical('Critical agent failure', $errorData);
            
            // Send immediate alerts
            $this->sendCriticalAlert($errorData);
        } else {
            logger()->error('Agent execution failed', $errorData);
        }
        
        return $context->addError($exception->getMessage());
    }
    
    private function isCriticalError(\Throwable $exception): bool
    {
        return $exception instanceof DatabaseException
            || $exception instanceof SecurityException
            || $exception instanceof SystemException;
    }
}
```

### Error Metrics

Collect error metrics for monitoring:

```php
class ErrorMetricsAgent extends BaseAgent
{
    protected function onError(Context $context, \Throwable $exception): Context
    {
        // Increment error counters
        Metrics::increment('agents.errors.total', [
            'agent' => $this->getName(),
            'error_type' => get_class($exception),
            'error_code' => $exception->getCode(),
        ]);
        
        // Track error rates
        Metrics::increment('agents.executions.total', [
            'agent' => $this->getName(),
            'status' => 'error',
        ]);
        
        // Measure error handling time
        $errorHandlingStart = microtime(true);
        $result = parent::onError($context, $exception);
        
        Metrics::timing('agents.error_handling.duration', 
            (microtime(true) - $errorHandlingStart) * 1000, [
            'agent' => $this->getName(),
        ]);
        
        return $result;
    }
}
```

### Health Checks

Implement health checks for pipeline components:

```php
class HealthCheckAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $healthChecks = [
            'database' => $this->checkDatabase(),
            'external_api' => $this->checkExternalApi(),
            'queue' => $this->checkQueue(),
            'cache' => $this->checkCache(),
        ];
        
        $failedChecks = array_filter($healthChecks, fn($healthy) => !$healthy);
        
        if (!empty($failedChecks)) {
            $failedServices = implode(', ', array_keys($failedChecks));
            throw new HealthCheckException("Health checks failed: {$failedServices}");
        }
        
        return $context->withMetadata('health_checks', $healthChecks);
    }
    
    private function checkDatabase(): bool
    {
        try {
            DB::select('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            logger()->error('Database health check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    private function checkExternalApi(): bool
    {
        try {
            $response = Http::timeout(5)->get('https://api.example.com/health');
            return $response->successful();
        } catch (\Throwable $e) {
            logger()->error('External API health check failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
```

## Testing Error Scenarios

### Unit Testing Errors

Test agent error handling:

```php
class PaymentAgentTest extends TestCase
{
    public function test_handles_payment_declined()
    {
        $order = Order::factory()->create();
        $context = Context::create($order);
        
        // Mock payment service to throw decline exception
        $this->mockPaymentService()
            ->shouldReceive('processPayment')
            ->andThrow(new PaymentDeclinedException('Insufficient funds'));
        
        $agent = new PaymentAgent();
        $result = $agent($context);
        
        // Assert error was handled gracefully
        $this->assertTrue($result->hasErrors());
        $this->assertStringContains('Payment declined', implode(' ', $result->errors));
        $this->assertEquals('declined', $result->getMetadata('payment_status'));
    }
    
    public function test_retries_on_gateway_timeout()
    {
        $order = Order::factory()->create();
        $context = Context::create($order);
        
        // Mock payment service to timeout then succeed
        $this->mockPaymentService()
            ->shouldReceive('processPayment')
            ->andThrow(new TimeoutException())
            ->once()
            ->andReturn(['transaction_id' => '12345'])
            ->once();
        
        $agent = new PaymentAgent();
        $result = $agent($context);
        
        // Assert retry succeeded
        $this->assertFalse($result->hasErrors());
        $this->assertEquals('12345', $result->payload['transaction_id']);
    }
}
```

### Integration Testing Failures

Test complete pipeline error scenarios:

```php
class OrderPipelineErrorTest extends TestCase
{
    public function test_pipeline_handles_inventory_shortage()
    {
        // Setup order that will fail inventory check
        $product = Product::factory()->create(['stock' => 0]);
        $order = Order::factory()->create([
            'items' => [['product_id' => $product->id, 'quantity' => 1]]
        ]);
        
        $result = Sentinels::pipeline()
            ->pipe(new ValidateOrderAgent())
            ->pipe(new CheckInventoryAgent())
            ->pipe(new ProcessPaymentAgent())
            ->onError(function (Context $context, \Throwable $e) {
                return $context->addError("Pipeline failed: {$e->getMessage()}");
            })
            ->through($order);
            
        $this->assertTrue($result->hasErrors());
        $this->assertStringContains('insufficient stock', implode(' ', $result->errors));
        
        // Verify no payment was attempted
        $this->assertNull($order->fresh()->payment_transaction_id);
    }
}
```

## Best Practices

### 1. Error Classification

Classify errors by type and severity:

```php
abstract class BaseException extends Exception
{
    abstract public function getSeverity(): string;
    abstract public function isRetryable(): bool;
    abstract public function getErrorCode(): string;
}

class ValidationException extends BaseException
{
    public function getSeverity(): string { return 'warning'; }
    public function isRetryable(): bool { return false; }
    public function getErrorCode(): string { return 'VALIDATION_FAILED'; }
}

class ExternalServiceException extends BaseException
{
    public function getSeverity(): string { return 'error'; }
    public function isRetryable(): bool { return true; }
    public function getErrorCode(): string { return 'EXTERNAL_SERVICE_ERROR'; }
}
```

### 2. Contextual Error Information

Provide rich error context:

```php
protected function onError(Context $context, \Throwable $exception): Context
{
    $errorContext = [
        'agent' => $this->getName(),
        'payload_type' => get_class($context->payload),
        'metadata_snapshot' => $context->metadata,
        'error_timestamp' => now()->toISOString(),
        'system_load' => sys_getloadavg()[0],
        'memory_usage' => memory_get_usage(true),
    ];
    
    return $context
        ->addError($exception->getMessage())
        ->withMetadata('error_context', $errorContext);
}
```

### 3. Gradual Degradation

Design for graceful degradation:

```php
class RecommendationAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        try {
            $recommendations = $this->getPersonalizedRecommendations($context->payload);
            return $context->with($recommendations);
            
        } catch (RecommendationServiceException $e) {
            // Fall back to popular items
            logger()->info('Recommendation service unavailable, using fallback');
            
            $fallbackRecommendations = $this->getPopularItems();
            
            return $context
                ->with($fallbackRecommendations)
                ->withMetadata('recommendations_fallback', true);
        }
    }
}
```

### 4. Error Budgets

Implement error budgets for SLA management:

```php
class ErrorBudgetTracker
{
    public function checkErrorBudget(string $service): bool
    {
        $period = now()->startOfHour();
        $errors = Cache::get("error_count_{$service}_{$period}", 0);
        $total = Cache::get("request_count_{$service}_{$period}", 0);
        
        $errorRate = $total > 0 ? $errors / $total : 0;
        $errorBudget = config("error_budgets.{$service}", 0.01); // 1% default
        
        return $errorRate <= $errorBudget;
    }
    
    public function recordError(string $service): void
    {
        $period = now()->startOfHour();
        Cache::increment("error_count_{$service}_{$period}");
        Cache::increment("request_count_{$service}_{$period}");
    }
}
```

## Next Steps

- **[Write Tests](testing.md)** - Test error scenarios effectively
- **[See Examples](examples.md)** - Real-world error handling patterns
- **[API Reference](api-reference.md)** - Complete error handling API