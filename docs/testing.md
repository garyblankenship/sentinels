# Testing Agents and Pipelines

Comprehensive testing is essential for reliable pipelines. This guide covers unit testing, integration testing, and performance testing strategies for Sentinels agents and pipelines.

## Testing Philosophy

Sentinels testing follows these principles:

- **Test agents in isolation** - Unit test individual agent logic
- **Test pipelines as workflows** - Integration test complete flows
- **Test error scenarios** - Verify error handling and recovery
- **Test performance** - Ensure pipelines meet performance requirements
- **Mock external dependencies** - Control external services in tests

## Setting Up Tests

### Base Test Classes

Create base test classes for consistent setup:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Vampires\Sentinels\Core\Context;
use Tests\CreatesApplication;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
    
    protected function createContext($payload = null, array $metadata = []): Context
    {
        return Context::create($payload ?? $this->createTestPayload())
            ->withMergedMetadata($metadata)
            ->withMetadata('test_run', true);
    }
    
    protected function createTestPayload(): array
    {
        return ['id' => 123, 'name' => 'Test Payload'];
    }
}

abstract class AgentTestCase extends TestCase
{
    protected function assertContextUpdated(Context $original, Context $result): void
    {
        $this->assertNotSame($original, $result, 'Context should be immutable');
        $this->assertEquals($original->correlationId, $result->correlationId, 'Correlation ID should be preserved');
    }
    
    protected function assertAgentExecuted(Context $result, string $agentName): void
    {
        $this->assertTrue($result->hasMetadata('agent_executed'));
        $this->assertEquals($agentName, $result->getMetadata('agent_executed'));
    }
}
```

### Test Configuration

Configure test environment in `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="./vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php">
    <testsuites>
        <testsuite name="Unit">
            <directory suffix="Test.php">./tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory suffix="Test.php">./tests/Feature</directory>
        </testsuite>
        <testsuite name="Agents">
            <directory suffix="Test.php">./tests/Agents</directory>
        </testsuite>
        <testsuite name="Pipelines">
            <directory suffix="Test.php">./tests/Pipelines</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="SENTINELS_OBSERVABILITY_ENABLED" value="false"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
    </php>
</phpunit>
```

## Unit Testing Agents

### Basic Agent Testing

Test individual agent behavior:

```php
<?php

namespace Tests\Unit\Agents;

use App\Agents\EmailNotificationAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\AgentTestCase;
use App\Mail\OrderProcessed;
use App\Models\Order;

class EmailNotificationAgentTest extends AgentTestCase
{
    use RefreshDatabase;
    
    public function test_sends_email_notification()
    {
        Mail::fake();
        
        $order = Order::factory()->create();
        $context = $this->createContext($order);
        $agent = new EmailNotificationAgent();
        
        $result = $agent($context);
        
        // Assert email was sent
        Mail::assertSent(OrderProcessed::class, function ($mail) use ($order) {
            return $mail->hasTo($order->customer->email);
        });
        
        // Assert context was updated correctly
        $this->assertContextUpdated($context, $result);
        $this->assertTrue($result->getMetadata('email_sent'));
        $this->assertTrue($result->hasTag('notification_sent'));
        $this->assertAgentExecuted($result, 'Email Notification Agent');
    }
    
    public function test_validates_required_data()
    {
        $invalidOrder = Order::factory()->create(['customer' => null]);
        $context = $this->createContext($invalidOrder);
        $agent = new EmailNotificationAgent();
        
        $result = $agent($context);
        
        // Assert validation failed
        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasMetadata('validation_failed'));
        $this->assertStringContains('email is required', implode(' ', $result->errors));
        
        // Assert no email was sent
        Mail::assertNothingSent();
    }
    
    public function test_handles_mail_sending_failure()
    {
        Mail::fake();
        Mail::shouldReceive('to')->andThrow(new \Exception('SMTP error'));
        
        $order = Order::factory()->create();
        $context = $this->createContext($order);
        $agent = new EmailNotificationAgent();
        
        $result = $agent($context);
        
        // Assert error was handled gracefully
        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasMetadata('agent_failed'));
        $this->assertStringContains('SMTP error', implode(' ', $result->errors));
    }
}
```

### Testing Agent Configuration

Test configurable agents:

```php
class DatabaseSyncAgentTest extends AgentTestCase
{
    use RefreshDatabase;
    
    public function test_syncs_data_to_specified_table()
    {
        $data = [
            ['id' => 1, 'name' => 'Test 1'],
            ['id' => 2, 'name' => 'Test 2'],
        ];
        $context = $this->createContext($data);
        
        $agent = new DatabaseSyncAgent('test_table', ['id', 'name']);
        $result = $agent($context);
        
        // Assert data was synced
        $this->assertDatabaseHas('test_table', ['id' => 1, 'name' => 'Test 1']);
        $this->assertDatabaseHas('test_table', ['id' => 2, 'name' => 'Test 2']);
        
        // Assert metadata was added
        $this->assertEquals('test_table', $result->getMetadata('synced_to'));
    }
    
    public function test_upsert_mode_updates_existing_records()
    {
        // Create existing record
        DB::table('test_table')->insert(['id' => 1, 'name' => 'Original']);
        
        $data = [['id' => 1, 'name' => 'Updated']];
        $context = $this->createContext($data);
        
        $agent = new DatabaseSyncAgent('test_table', ['id', 'name'], upsert: true);
        $result = $agent($context);
        
        // Assert record was updated, not duplicated
        $this->assertDatabaseHas('test_table', ['id' => 1, 'name' => 'Updated']);
        $this->assertDatabaseCount('test_table', 1);
    }
}
```

### Testing Agent Validation

Test agent validation logic:

```php
class OrderValidationAgentTest extends AgentTestCase
{
    public function test_validates_complete_order()
    {
        $order = Order::factory()->create([
            'total' => 100.00,
            'customer' => Customer::factory()->create(),
            'items' => OrderItem::factory()->count(2)->create()
        ]);
        
        $context = $this->createContext($order);
        $agent = new OrderValidationAgent();
        
        $result = $agent($context);
        
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->hasTag('validated'));
    }
    
    public function test_fails_validation_for_negative_total()
    {
        $order = Order::factory()->create(['total' => -10.00]);
        $context = $this->createContext($order);
        $agent = new OrderValidationAgent();
        
        $result = $agent($context);
        
        $this->assertTrue($result->hasErrors());
        $this->assertStringContains('negative', implode(' ', $result->errors));
    }
    
    public function test_fails_validation_for_blocked_customer()
    {
        $customer = Customer::factory()->create(['blocked' => true]);
        $order = Order::factory()->create(['customer' => $customer]);
        $context = $this->createContext($order);
        $agent = new OrderValidationAgent();
        
        $result = $agent($context);
        
        $this->assertTrue($result->hasErrors());
        $this->assertStringContains('blocked', implode(' ', $result->errors));
    }
}
```

### Testing Error Handling

Test agent error scenarios:

```php
class PaymentAgentTest extends AgentTestCase
{
    public function test_handles_payment_declined()
    {
        $order = Order::factory()->create();
        $context = $this->createContext($order);
        
        // Mock payment service
        $this->mock(PaymentGateway::class, function ($mock) {
            $mock->shouldReceive('processPayment')
                 ->andThrow(new PaymentDeclinedException('Card declined'));
        });
        
        $agent = new PaymentAgent();
        $result = $agent($context);
        
        $this->assertTrue($result->hasErrors());
        $this->assertEquals('declined', $result->getMetadata('payment_status'));
        $this->assertStringContains('Card declined', implode(' ', $result->errors));
    }
    
    public function test_retries_on_temporary_failure()
    {
        $order = Order::factory()->create();
        $context = $this->createContext($order);
        
        // Mock payment service to fail once then succeed
        $this->mock(PaymentGateway::class, function ($mock) {
            $mock->shouldReceive('processPayment')
                 ->andThrow(new TemporaryException('Service unavailable'))
                 ->once();
            $mock->shouldReceive('processPayment')
                 ->andReturn(['transaction_id' => '12345'])
                 ->once();
        });
        
        $agent = new PaymentAgent();
        $result = $agent($context);
        
        $this->assertFalse($result->hasErrors());
        $this->assertEquals('12345', $result->payload['transaction_id']);
    }
}
```

## Integration Testing Pipelines

### Basic Pipeline Testing

Test complete pipeline flows:

```php
<?php

namespace Tests\Feature\Pipelines;

use App\Agents\ValidateOrderAgent;
use App\Agents\ProcessPaymentAgent;
use App\Agents\SendEmailAgent;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Vampires\Sentinels\Facades\Sentinels;

class OrderProcessingPipelineTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_processes_complete_order_successfully()
    {
        Mail::fake();
        
        $order = Order::factory()->create();
        
        $result = Sentinels::pipeline()
            ->pipe(new ValidateOrderAgent())
            ->pipe(new ProcessPaymentAgent())
            ->pipe(new SendEmailAgent())
            ->through($order);
            
        // Assert pipeline completed successfully
        $this->assertEquals('processed', $result->status);
        $this->assertNotNull($result->payment_transaction_id);
        
        // Assert email was sent
        Mail::assertSent(OrderProcessed::class);
        
        // Assert database was updated
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'processed'
        ]);
    }
    
    public function test_handles_pipeline_failure_gracefully()
    {
        $invalidOrder = Order::factory()->invalid()->create();
        
        $result = Sentinels::pipeline()
            ->pipe(new ValidateOrderAgent())
            ->pipe(new ProcessPaymentAgent())
            ->onError(function ($context, $exception) {
                return $context->addError("Pipeline failed: {$exception->getMessage()}");
            })
            ->through($invalidOrder);
            
        $this->assertTrue($result->hasErrors());
        $this->assertNull($invalidOrder->fresh()->payment_transaction_id);
    }
}
```

### Testing Pipeline Modes

Test different execution modes:

```php
class PipelineModeTest extends TestCase
{
    public function test_sequential_mode_processes_in_order()
    {
        $data = collect([1, 2, 3, 4, 5]);
        $results = [];
        
        $pipeline = Sentinels::pipeline()
            ->mode('sequential')
            ->pipe(new class($results) extends BaseAgent {
                public function __construct(private array &$results) {}
                
                protected function handle(Context $context): Context
                {
                    $this->results[] = $context->payload;
                    return $context;
                }
            });
            
        $pipeline->through($data);
        
        // Assert sequential processing
        $this->assertEquals([1, 2, 3, 4, 5], $results);
    }
    
    public function test_parallel_mode_processes_simultaneously()
    {
        $data = collect([1, 2, 3]);
        $startTimes = [];
        
        $pipeline = Sentinels::pipeline()
            ->mode('parallel')
            ->pipe(new class($startTimes) extends BaseAgent {
                public function __construct(private array &$startTimes) {}
                
                protected function handle(Context $context): Context
                {
                    $this->startTimes[] = microtime(true);
                    sleep(1); // Simulate work
                    return $context;
                }
            });
            
        $start = microtime(true);
        $pipeline->through($data);
        $duration = microtime(true) - $start;
        
        // Parallel processing should take ~1 second, not ~3 seconds
        $this->assertLessThan(2.0, $duration);
        
        // All items should start processing at roughly the same time
        $maxTimeDiff = max($startTimes) - min($startTimes);
        $this->assertLessThan(0.1, $maxTimeDiff); // 100ms tolerance
    }
}
```

### Testing Error Boundaries

Test pipeline error isolation:

```php
class ErrorBoundaryTest extends TestCase
{
    public function test_error_boundary_isolates_failures()
    {
        $order = Order::factory()->create();
        $criticalExecuted = false;
        $finalExecuted = false;
        
        $result = Sentinels::pipeline()
            ->pipe(new class($criticalExecuted) extends BaseAgent {
                public function __construct(private bool &$executed) {}
                protected function handle(Context $context): Context
                {
                    $this->executed = true;
                    return $context->withTag('critical_completed');
                }
            })
            
            // Error boundary - failures don't stop main pipeline
            ->pipe(
                Sentinels::pipeline()
                    ->pipe(new class extends BaseAgent {
                        protected function handle(Context $context): Context
                        {
                            throw new \Exception('Optional processing failed');
                        }
                    })
                    ->onError(function (Context $context, \Throwable $e) {
                        return $context->addError("Optional failed: {$e->getMessage()}");
                    })
            )
            
            ->pipe(new class($finalExecuted) extends BaseAgent {
                public function __construct(private bool &$executed) {}
                protected function handle(Context $context): Context
                {
                    $this->executed = true;
                    return $context->withTag('final_completed');
                }
            })
            ->through($order);
            
        // Assert critical and final agents executed
        $this->assertTrue($criticalExecuted);
        $this->assertTrue($finalExecuted);
        $this->assertTrue($result->hasTag('critical_completed'));
        $this->assertTrue($result->hasTag('final_completed'));
        
        // Assert error was contained
        $this->assertTrue($result->hasErrors());
        $this->assertStringContains('Optional failed', implode(' ', $result->errors));
    }
}
```

## Testing External Dependencies

### Mocking HTTP Clients

Test agents that make HTTP calls:

```php
class ExternalApiAgentTest extends AgentTestCase
{
    public function test_processes_successful_api_response()
    {
        Http::fake([
            'api.example.com/*' => Http::response([
                'status' => 'success',
                'data' => ['processed' => true]
            ], 200)
        ]);
        
        $context = $this->createContext(['user_id' => 123]);
        $agent = new ExternalApiAgent();
        
        $result = $agent($context);
        
        $this->assertFalse($result->hasErrors());
        $this->assertEquals('success', $result->payload['status']);
        $this->assertTrue($result->getMetadata('api_call_successful'));
    }
    
    public function test_handles_api_timeout()
    {
        Http::fake([
            'api.example.com/*' => Http::response('', 500)
        ]);
        
        $context = $this->createContext(['user_id' => 123]);
        $agent = new ExternalApiAgent();
        
        $result = $agent($context);
        
        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasMetadata('api_failed'));
        $this->assertNotNull($result->getMetadata('fallback_used'));
    }
    
    public function test_retries_on_temporary_failure()
    {
        // Fail first two attempts, succeed on third
        Http::fake([
            'api.example.com/*' => Http::sequence()
                ->push('', 500)
                ->push('', 500)
                ->push(['status' => 'success'], 200)
        ]);
        
        $context = $this->createContext(['user_id' => 123]);
        $agent = new ExternalApiAgent();
        
        $result = $agent($context);
        
        $this->assertFalse($result->hasErrors());
        $this->assertEquals('success', $result->payload['status']);
        $this->assertEquals(3, $result->getMetadata('retry_attempts'));
    }
}
```

### Database Testing

Test database-dependent agents:

```php
class InventoryAgentTest extends AgentTestCase
{
    use RefreshDatabase;
    
    public function test_reduces_inventory_successfully()
    {
        $product = Product::factory()->create(['stock' => 10]);
        $orderItem = OrderItem::factory()->create([
            'product_id' => $product->id,
            'quantity' => 3
        ]);
        $order = Order::factory()->create(['items' => [$orderItem]]);
        
        $context = $this->createContext($order);
        $agent = new UpdateInventoryAgent();
        
        $result = $agent($context);
        
        $this->assertFalse($result->hasErrors());
        $this->assertEquals(7, $product->fresh()->stock);
        $this->assertTrue($result->hasTag('inventory_updated'));
    }
    
    public function test_fails_when_insufficient_stock()
    {
        $product = Product::factory()->create(['stock' => 2]);
        $orderItem = OrderItem::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5
        ]);
        $order = Order::factory()->create(['items' => [$orderItem]]);
        
        $context = $this->createContext($order);
        $agent = new UpdateInventoryAgent();
        
        $result = $agent($context);
        
        $this->assertTrue($result->hasErrors());
        $this->assertEquals(2, $product->fresh()->stock); // Unchanged
        $this->assertStringContains('insufficient stock', implode(' ', $result->errors));
    }
}
```

## Performance Testing

### Load Testing

Test pipeline performance under load:

```php
/**
 * @group performance
 */
class PipelinePerformanceTest extends TestCase
{
    use RefreshDatabase;
    
    public function test_pipeline_handles_high_volume()
    {
        $orders = Order::factory()->count(100)->create();
        
        $startTime = microtime(true);
        $results = [];
        
        foreach ($orders as $order) {
            $result = Sentinels::pipeline()
                ->pipe(new ValidateOrderAgent())
                ->pipe(new ProcessPaymentAgent())
                ->through($order);
                
            $results[] = $result;
        }
        
        $duration = microtime(true) - $startTime;
        
        // Assert performance requirements
        $this->assertLessThan(10.0, $duration, 'Pipeline should process 100 orders in under 10 seconds');
        $this->assertCount(100, $results);
        
        // Assert all orders were processed successfully
        $successfulOrders = collect($results)->filter(fn($r) => !$r->hasErrors());
        $this->assertGreaterThan(95, $successfulOrders->count(), 'At least 95% success rate required');
    }
    
    public function test_parallel_processing_improves_performance()
    {
        $data = range(1, 20);
        
        // Test sequential processing
        $sequentialStart = microtime(true);
        Sentinels::pipeline()
            ->mode('sequential')
            ->pipe(new SlowProcessingAgent()) // Takes 100ms per item
            ->through($data);
        $sequentialDuration = microtime(true) - $sequentialStart;
        
        // Test parallel processing
        $parallelStart = microtime(true);
        Sentinels::pipeline()
            ->mode('parallel')
            ->pipe(new SlowProcessingAgent())
            ->through($data);
        $parallelDuration = microtime(true) - $parallelStart;
        
        // Parallel should be significantly faster
        $this->assertLessThan($sequentialDuration * 0.3, $parallelDuration,
            'Parallel processing should be at least 70% faster');
    }
}
```

### Memory Testing

Test memory usage in pipelines:

```php
class MemoryUsageTest extends TestCase
{
    public function test_pipeline_manages_memory_efficiently()
    {
        $initialMemory = memory_get_usage(true);
        
        // Process large dataset
        $largeDataset = range(1, 10000);
        
        Sentinels::pipeline()
            ->pipe(new MemoryEfficientAgent())
            ->through($largeDataset);
            
        $finalMemory = memory_get_usage(true);
        $memoryIncrease = $finalMemory - $initialMemory;
        
        // Memory increase should be reasonable (< 50MB)
        $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease,
            'Memory usage should not increase by more than 50MB');
    }
    
    public function test_context_immutability_performance()
    {
        $context = $this->createContext(['large_data' => range(1, 1000)]);
        $startTime = microtime(true);
        
        // Create many context mutations
        for ($i = 0; $i < 1000; $i++) {
            $context = $context->withMetadata("key_{$i}", $i);
        }
        
        $duration = microtime(true) - $startTime;
        
        // Context mutations should be fast
        $this->assertLessThan(1.0, $duration,
            '1000 context mutations should complete in under 1 second');
    }
}
```

## Testing Utilities

### Custom Assertions

Create custom assertions for common patterns:

```php
trait SentinelsTestAssertions
{
    protected function assertPipelineSucceeded($result): void
    {
        $this->assertFalse($result->hasErrors(), 
            'Pipeline should succeed without errors. Errors: ' . implode(', ', $result->errors));
    }
    
    protected function assertPipelineFailed($result, string $expectedError = null): void
    {
        $this->assertTrue($result->hasErrors(), 'Pipeline should have failed with errors');
        
        if ($expectedError) {
            $this->assertStringContains($expectedError, implode(' ', $result->errors));
        }
    }
    
    protected function assertAgentSkipped($result, string $reason = null): void
    {
        $this->assertTrue($result->hasMetadata('agent_skipped') || $result->hasMetadata('agent_skipped_condition'));
        
        if ($reason) {
            $skipReason = $result->getMetadata('skip_reason', '');
            $this->assertStringContains($reason, $skipReason);
        }
    }
    
    protected function assertContextPreserved(Context $original, Context $result): void
    {
        $this->assertEquals($original->correlationId, $result->correlationId);
        $this->assertEquals($original->traceId, $result->traceId);
        $this->assertGreaterThanOrEqual($original->startTime, $result->startTime);
    }
}
```

### Test Data Builders

Create builders for complex test data:

```php
class OrderTestBuilder
{
    private array $attributes = [];
    private array $items = [];
    private ?Customer $customer = null;
    
    public static function create(): self
    {
        return new self();
    }
    
    public function withCustomer(Customer $customer): self
    {
        $this->customer = $customer;
        return $this;
    }
    
    public function withTotal(float $total): self
    {
        $this->attributes['total'] = $total;
        return $this;
    }
    
    public function withItem(Product $product, int $quantity): self
    {
        $this->items[] = [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->price
        ];
        return $this;
    }
    
    public function invalid(): self
    {
        $this->attributes['total'] = -100;
        $this->customer = Customer::factory()->blocked()->create();
        return $this;
    }
    
    public function build(): Order
    {
        return Order::factory()
            ->for($this->customer ?? Customer::factory())
            ->hasItems($this->items)
            ->create($this->attributes);
    }
}

// Usage
$order = OrderTestBuilder::create()
    ->withCustomer($vipCustomer)
    ->withTotal(1500.00)
    ->withItem($product1, 2)
    ->withItem($product2, 1)
    ->build();
```

### Mock Factories

Create reusable mocks:

```php
class MockFactory
{
    public static function paymentGateway(): MockInterface
    {
        return Mockery::mock(PaymentGateway::class);
    }
    
    public static function successfulPaymentGateway(string $transactionId = '12345'): MockInterface
    {
        $mock = self::paymentGateway();
        $mock->shouldReceive('processPayment')
             ->andReturn(['transaction_id' => $transactionId, 'status' => 'completed']);
        return $mock;
    }
    
    public static function failingPaymentGateway(\Throwable $exception): MockInterface
    {
        $mock = self::paymentGateway();
        $mock->shouldReceive('processPayment')->andThrow($exception);
        return $mock;
    }
    
    public static function retryingPaymentGateway(int $failureCount, string $transactionId = '12345'): MockInterface
    {
        $mock = self::paymentGateway();
        
        for ($i = 0; $i < $failureCount; $i++) {
            $mock->shouldReceive('processPayment')
                 ->andThrow(new TemporaryException('Service unavailable'))
                 ->once();
        }
        
        $mock->shouldReceive('processPayment')
             ->andReturn(['transaction_id' => $transactionId])
             ->once();
             
        return $mock;
    }
}
```

## Best Practices

### 1. Test Structure

Follow AAA pattern (Arrange, Act, Assert):

```php
public function test_processes_order_successfully()
{
    // Arrange
    $order = Order::factory()->create();
    $context = $this->createContext($order);
    $agent = new ProcessOrderAgent();
    
    // Act
    $result = $agent($context);
    
    // Assert
    $this->assertFalse($result->hasErrors());
    $this->assertEquals('processed', $result->payload->status);
}
```

### 2. Descriptive Test Names

Use descriptive test method names:

```php
// ❌ Poor naming
public function test_agent() {}
public function test_error() {}

// ✅ Good naming
public function test_sends_email_notification_to_customer() {}
public function test_handles_payment_declined_gracefully() {}
public function test_retries_on_temporary_network_failure() {}
```

### 3. Test Data Isolation

Keep test data isolated and predictable:

```php
public function test_processes_high_value_order()
{
    // Create specific test data
    $product = Product::factory()->create(['price' => 500.00]);
    $customer = Customer::factory()->vip()->create();
    $order = Order::factory()
        ->for($customer)
        ->hasItems([
            'product_id' => $product->id,
            'quantity' => 3,
            'price' => $product->price
        ])
        ->create(['total' => 1500.00]);
        
    // Test with known data
    $result = $this->processOrder($order);
    
    $this->assertTrue($result->hasTag('high_value'));
}
```

### 4. Error Testing

Always test error scenarios:

```php
public function test_handles_database_connection_failure()
{
    // Simulate database failure
    DB::shouldReceive('transaction')->andThrow(new \PDOException('Connection lost'));
    
    $agent = new DatabaseSyncAgent();
    $result = $agent($this->createContext());
    
    $this->assertTrue($result->hasErrors());
    $this->assertStringContains('database', implode(' ', $result->errors));
}
```

### 5. Performance Benchmarks

Include performance expectations:

```php
public function test_processes_orders_within_time_limit()
{
    $orders = Order::factory()->count(50)->create();
    
    $start = microtime(true);
    
    foreach ($orders as $order) {
        $this->processOrder($order);
    }
    
    $duration = microtime(true) - $start;
    
    $this->assertLessThan(5.0, $duration, 'Processing 50 orders should take less than 5 seconds');
}
```

## Running Tests

### Command Line Options

```bash
# Run all tests
php artisan test

# Run specific test suites
php artisan test --testsuite=Unit
php artisan test --testsuite=Agents

# Run tests with coverage
php artisan test --coverage

# Run performance tests
php artisan test --group=performance

# Run tests in parallel
php artisan test --parallel

# Run tests with detailed output
php artisan test --verbose
```

### Continuous Integration

Example GitHub Actions configuration:

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: password
          MYSQL_DATABASE: testing
        ports:
          - 3306:3306
        options: --health-cmd="mysqladmin ping" --health-interval=10s --health-timeout=5s --health-retries=3
    
    steps:
    - uses: actions/checkout@v3
    
    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: 8.1
        extensions: dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite, bcmath, soap, intl, gd, exif, iconv
        coverage: xdebug
    
    - name: Install dependencies
      run: composer install --no-interaction --prefer-dist --optimize-autoloader
    
    - name: Run tests
      env:
        DB_CONNECTION: mysql
        DB_HOST: 127.0.0.1
        DB_PORT: 3306
        DB_DATABASE: testing
        DB_USERNAME: root
        DB_PASSWORD: password
      run: php artisan test --coverage --min=80
```

## Next Steps

- **[See Examples](examples.md)** - Real-world testing patterns
- **[API Reference](api-reference.md)** - Complete testing API
- **[Getting Started](getting-started.md)** - Return to basics