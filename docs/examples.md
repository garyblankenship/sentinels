# Real-World Examples

This guide demonstrates practical implementations of Sentinels agents and pipelines solving common business problems. Each example includes complete working code, error handling, and testing strategies.

## E-Commerce Order Processing

A comprehensive order processing pipeline that validates orders, processes payments, manages inventory, and handles shipping.

### The Domain Models

First, let's set up our Eloquent models:

```php
// Order.php
class Order extends Model
{
    protected $fillable = ['customer_id', 'status', 'total', 'payment_method'];
    protected $casts = ['total' => 'decimal:2'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}

// Customer.php
class Customer extends Model
{
    protected $fillable = ['name', 'email', 'is_blocked'];
    protected $casts = ['is_blocked' => 'boolean'];
}

// Product.php  
class Product extends Model
{
    protected $fillable = ['name', 'price', 'stock_quantity'];
    protected $casts = ['price' => 'decimal:2', 'stock_quantity' => 'integer'];
}
```

### Validation Agent

```php
<?php

namespace App\Agents\Order;

use App\Models\Order;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\ValidationResult;

class ValidateOrderAgent extends BaseAgent
{
    protected function validatePayload(Context $context): ValidationResult
    {
        $order = $context->payload;
        $errors = [];

        // Check if order exists and is valid
        if (!$order instanceof Order) {
            return ValidationResult::invalid(['order' => ['Invalid order instance']]);
        }

        if (!$order->customer) {
            $errors['customer'][] = 'Order must have a customer';
        }

        if ($order->customer && $order->customer->is_blocked) {
            $errors['customer'][] = 'Customer account is blocked';
        }

        if ($order->total <= 0) {
            $errors['total'][] = 'Order total must be positive';
        }

        // Validate inventory availability
        foreach ($order->items as $item) {
            if ($item->quantity > $item->product->stock_quantity) {
                $errors['inventory'][] = "Insufficient stock for {$item->product->name}";
            }
        }

        // Check payment method
        $allowedMethods = ['credit_card', 'paypal', 'bank_transfer'];
        if (!in_array($order->payment_method, $allowedMethods)) {
            $errors['payment_method'][] = 'Invalid payment method';
        }

        return empty($errors) 
            ? ValidationResult::valid($order)
            : ValidationResult::invalid($errors);
    }

    protected function handle(Context $context): Context
    {
        $order = $context->payload;
        
        // Mark order as validated
        $order->update(['status' => 'validated']);
        
        return $context
            ->withTag('validated')
            ->withMetadata('validated_at', now()->toISOString());
    }

    public function getName(): string
    {
        return 'Order Validation';
    }

    public function getDescription(): string
    {
        return 'Validates order data, customer status, and inventory availability';
    }
}
```

### Payment Processing Agent

```php
<?php

namespace App\Agents\Order;

use App\Services\PaymentGateway;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\RetryPolicy;

class ProcessPaymentAgent extends BaseAgent
{
    public function __construct(
        private PaymentGateway $paymentGateway
    ) {}

    protected function handle(Context $context): Context
    {
        $order = $context->payload;
        
        // Process payment through gateway
        $paymentResult = $this->paymentGateway->charge(
            amount: $order->total,
            paymentMethod: $order->payment_method,
            customer: $order->customer,
            metadata: [
                'order_id' => $order->id,
                'correlation_id' => $context->correlationId
            ]
        );

        if ($paymentResult->successful()) {
            $order->update([
                'status' => 'paid',
                'payment_transaction_id' => $paymentResult->transactionId
            ]);

            return $context
                ->withTag('payment_successful')
                ->withMetadata('transaction_id', $paymentResult->transactionId)
                ->withMetadata('payment_processed_at', now()->toISOString());
        }

        throw new PaymentException(
            "Payment failed: {$paymentResult->error}"
        );
    }

    protected function onError(Context $context, \Throwable $exception): Context
    {
        $order = $context->payload;
        
        $order->update(['status' => 'payment_failed']);
        
        logger()->error('Payment processing failed', [
            'order_id' => $order->id,
            'correlation_id' => $context->correlationId,
            'error' => $exception->getMessage(),
            'customer_id' => $order->customer_id
        ]);

        return $context
            ->addError("Payment failed: {$exception->getMessage()}")
            ->withTag('payment_failed')
            ->withMetadata('payment_error', $exception->getMessage());
    }

    public function getRetryPolicy(): ?RetryPolicy
    {
        return RetryPolicy::exponentialBackoff()
            ->maxAttempts(3)
            ->baseDelay(1000)
            ->maxDelay(5000);
    }

    public function getName(): string
    {
        return 'Payment Processor';
    }

    public function getEstimatedExecutionTime(): int
    {
        return 3000; // 3 seconds for payment processing
    }
}
```

### Inventory Management Agent

```php
<?php

namespace App\Agents\Order;

use Illuminate\Support\Facades\DB;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;

class UpdateInventoryAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $order = $context->payload;
        $updatedProducts = [];

        DB::transaction(function () use ($order, &$updatedProducts) {
            foreach ($order->items as $item) {
                // Lock the product row for atomic update
                $product = $item->product()->lockForUpdate()->first();
                
                if ($product->stock_quantity < $item->quantity) {
                    throw new InsufficientInventoryException(
                        "Not enough stock for {$product->name}"
                    );
                }

                $product->decrement('stock_quantity', $item->quantity);
                $updatedProducts[] = [
                    'product_id' => $product->id,
                    'previous_stock' => $product->stock_quantity + $item->quantity,
                    'new_stock' => $product->stock_quantity,
                    'quantity_reserved' => $item->quantity
                ];
            }
        });

        $order->update(['status' => 'inventory_updated']);

        return $context
            ->withTag('inventory_updated')
            ->withMetadata('inventory_changes', $updatedProducts)
            ->withMetadata('inventory_updated_at', now()->toISOString());
    }

    protected function onError(Context $context, \Throwable $exception): Context
    {
        // If inventory update fails after payment, we need compensation
        if ($context->hasTag('payment_successful')) {
            // Trigger payment refund process
            return $context
                ->addError($exception->getMessage())
                ->withTag('requires_refund')
                ->withMetadata('compensation_needed', 'payment_refund');
        }

        return parent::onError($context, $exception);
    }

    public function getName(): string
    {
        return 'Inventory Manager';
    }
}
```

### Email Notification Agent

```php
<?php

namespace App\Agents\Order;

use App\Mail\OrderConfirmation;
use App\Mail\OrderFailed;
use Illuminate\Support\Facades\Mail;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;

class SendOrderEmailAgent extends BaseAgent
{
    public function shouldExecute(Context $context): bool
    {
        $order = $context->payload;
        
        // Only send email if customer has valid email
        return $order->customer && 
               filter_var($order->customer->email, FILTER_VALIDATE_EMAIL);
    }

    protected function handle(Context $context): Context
    {
        $order = $context->payload;
        
        if ($context->hasErrors()) {
            // Send failure notification
            Mail::to($order->customer->email)
                ->send(new OrderFailed($order, $context->errors));
                
            return $context
                ->withTag('failure_email_sent')
                ->withMetadata('email_type', 'failure');
        }
        
        // Send success confirmation
        Mail::to($order->customer->email)
            ->send(new OrderConfirmation($order));
            
        return $context
            ->withTag('confirmation_email_sent')
            ->withMetadata('email_type', 'confirmation')
            ->withMetadata('email_sent_at', now()->toISOString());
    }

    public function getName(): string
    {
        return 'Order Email Notification';
    }

    public function getTags(): array
    {
        return ['email', 'notification', 'customer_communication'];
    }
}
```

### Complete Order Processing Pipeline

```php
<?php

namespace App\Pipelines;

use App\Agents\Order\ProcessPaymentAgent;
use App\Agents\Order\SendOrderEmailAgent;
use App\Agents\Order\UpdateInventoryAgent;
use App\Agents\Order\ValidateOrderAgent;
use App\Models\Order;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Facades\Sentinels;

class OrderProcessingPipeline
{
    public function process(Order $order): Context
    {
        return Sentinels::pipeline()
            ->timeout(30) // 30 second timeout
            ->pipe(new ValidateOrderAgent())
            ->pipe(new ProcessPaymentAgent(app(PaymentGateway::class)))
            ->pipe(new UpdateInventoryAgent())
            ->pipe(new SendOrderEmailAgent())
            ->onError(function (Context $context, \Throwable $exception) {
                // Global error handler
                logger()->error('Order processing pipeline failed', [
                    'order_id' => $context->payload->id,
                    'correlation_id' => $context->correlationId,
                    'error' => $exception->getMessage(),
                    'stage' => $this->determineFailedStage($context)
                ]);

                // Trigger cleanup pipeline for compensation
                if ($context->hasTag('payment_successful')) {
                    app(CompensationPipeline::class)->process($context);
                }

                return $context->addError($exception->getMessage());
            })
            ->onSuccess(function (Context $context) {
                logger()->info('Order processed successfully', [
                    'order_id' => $context->payload->id,
                    'correlation_id' => $context->correlationId,
                    'execution_time' => $context->getElapsedTime()
                ]);
            })
            ->through($order);
    }

    private function determineFailedStage(Context $context): string
    {
        if ($context->hasTag('payment_failed')) return 'payment';
        if ($context->hasTag('validated')) return 'inventory';
        return 'validation';
    }
}
```

### Controller Integration

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Pipelines\OrderProcessingPipeline;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function process(Request $request, Order $order)
    {
        $pipeline = new OrderProcessingPipeline();
        $result = $pipeline->process($order);

        if ($result->hasErrors()) {
            return response()->json([
                'success' => false,
                'message' => 'Order processing failed',
                'errors' => $result->errors,
                'correlation_id' => $result->correlationId
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order processed successfully',
            'order' => $order->fresh(),
            'correlation_id' => $result->correlationId
        ]);
    }
}
```

## Data ETL Pipeline

Process CSV files through validation, transformation, and database storage.

### CSV Import Agent

```php
<?php

namespace App\Agents\Import;

use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;

class ReadCsvAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $filePath = $context->payload;
        
        if (!Storage::exists($filePath)) {
            throw new FileNotFoundException("CSV file not found: {$filePath}");
        }

        $csv = Reader::createFromPath(Storage::path($filePath), 'r');
        $csv->setHeaderOffset(0);
        
        $records = iterator_to_array($csv->getRecords());
        
        return $context
            ->with($records)
            ->withMetadata('original_file', $filePath)
            ->withMetadata('record_count', count($records))
            ->withMetadata('columns', $csv->getHeader())
            ->withTag('csv_parsed');
    }

    public function getName(): string
    {
        return 'CSV Reader';
    }
}
```

### Data Validation Agent

```php
<?php

namespace App\Agents\Import;

use Illuminate\Support\Facades\Validator;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;

class ValidateRecordsAgent extends BaseAgent
{
    private array $validationRules;

    public function __construct(array $validationRules)
    {
        $this->validationRules = $validationRules;
    }

    protected function handle(Context $context): Context
    {
        $records = $context->payload;
        $validRecords = [];
        $invalidRecords = [];
        
        foreach ($records as $index => $record) {
            $validator = Validator::make($record, $this->validationRules);
            
            if ($validator->passes()) {
                $validRecords[] = $record;
            } else {
                $invalidRecords[] = [
                    'row' => $index + 2, // +2 for header and 0-based index
                    'data' => $record,
                    'errors' => $validator->errors()->toArray()
                ];
            }
        }

        if (count($invalidRecords) > count($records) * 0.5) {
            throw new ValidationException('Too many invalid records (>50%)');
        }

        return $context
            ->with($validRecords)
            ->withMetadata('valid_record_count', count($validRecords))
            ->withMetadata('invalid_record_count', count($invalidRecords))
            ->withMetadata('invalid_records', $invalidRecords)
            ->withTag('records_validated');
    }

    public function getName(): string
    {
        return 'Record Validator';
    }
}
```

### Data Transformation Agent

```php
<?php

namespace App\Agents\Import;

use Carbon\Carbon;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;

class TransformRecordsAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $records = $context->payload;
        $transformedRecords = [];
        
        foreach ($records as $record) {
            $transformedRecords[] = [
                'name' => ucwords(strtolower(trim($record['name']))),
                'email' => strtolower(trim($record['email'])),
                'phone' => preg_replace('/[^\d]/', '', $record['phone']),
                'birth_date' => Carbon::createFromFormat('m/d/Y', $record['birth_date'])->format('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now(),
                'imported_at' => now(),
                'import_correlation_id' => $context->correlationId
            ];
        }

        return $context
            ->with($transformedRecords)
            ->withTag('records_transformed');
    }

    public function getName(): string
    {
        return 'Record Transformer';
    }
}
```

### Database Storage Agent

```php
<?php

namespace App\Agents\Import;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;

class StoreRecordsAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $records = $context->payload;
        $batchSize = 1000;
        $insertedCount = 0;
        
        DB::transaction(function () use ($records, $batchSize, &$insertedCount) {
            $batches = array_chunk($records, $batchSize);
            
            foreach ($batches as $batch) {
                Customer::insert($batch);
                $insertedCount += count($batch);
            }
        });

        return $context
            ->with($insertedCount)
            ->withMetadata('records_inserted', $insertedCount)
            ->withMetadata('batch_size', $batchSize)
            ->withMetadata('batch_count', ceil(count($records) / $batchSize))
            ->withTag('records_stored');
    }

    public function getName(): string
    {
        return 'Database Storage';
    }

    public function getEstimatedExecutionTime(): int
    {
        return 10000; // 10 seconds for large batches
    }
}
```

### ETL Pipeline

```php
<?php

namespace App\Pipelines;

use App\Agents\Import\ReadCsvAgent;
use App\Agents\Import\StoreRecordsAgent;
use App\Agents\Import\TransformRecordsAgent;
use App\Agents\Import\ValidateRecordsAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Facades\Sentinels;

class CsvImportPipeline
{
    public function import(string $filePath, array $validationRules = []): Context
    {
        $defaultRules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:customers,email',
            'phone' => 'required|string|max:20',
            'birth_date' => 'required|date_format:m/d/Y'
        ];
        
        $rules = array_merge($defaultRules, $validationRules);

        return Sentinels::pipeline()
            ->timeout(300) // 5 minute timeout
            ->pipe(new ReadCsvAgent())
            ->pipe(new ValidateRecordsAgent($rules))
            ->pipe(new TransformRecordsAgent())
            ->pipe(new StoreRecordsAgent())
            ->onSuccess(function (Context $context) {
                logger()->info('CSV import completed successfully', [
                    'file' => $context->getMetadata('original_file'),
                    'total_records' => $context->getMetadata('record_count'),
                    'valid_records' => $context->getMetadata('valid_record_count'),
                    'inserted_records' => $context->getMetadata('records_inserted'),
                    'correlation_id' => $context->correlationId
                ]);
            })
            ->through($filePath);
    }
}
```

## API Integration with Circuit Breaker

Handle external API calls with resilience patterns.

### API Client Agent

```php
<?php

namespace App\Agents\Integration;

use App\Services\CircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\RetryPolicy;

class ExternalApiAgent extends BaseAgent
{
    public function __construct(
        private string $endpoint,
        private CircuitBreaker $circuitBreaker,
        private int $timeoutSeconds = 10
    ) {}

    protected function handle(Context $context): Context
    {
        $requestData = $context->payload;
        
        // Check circuit breaker
        if ($this->circuitBreaker->isOpen()) {
            throw new CircuitBreakerOpenException('Circuit breaker is open');
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders([
                    'X-Correlation-ID' => $context->correlationId,
                    'X-Request-ID' => \Str::uuid()
                ])
                ->post($this->endpoint, $requestData);

            if ($response->successful()) {
                $this->circuitBreaker->recordSuccess();
                
                return $context
                    ->with($response->json())
                    ->withMetadata('api_response_status', $response->status())
                    ->withMetadata('api_response_time', $response->handlerStats()['total_time'] ?? 0)
                    ->withTag('api_success');
            }

            throw new ApiException("API returned {$response->status()}: {$response->body()}");
            
        } catch (ConnectionException $e) {
            $this->circuitBreaker->recordFailure();
            throw new ApiException("Connection failed: {$e->getMessage()}");
        }
    }

    protected function onError(Context $context, \Throwable $exception): Context
    {
        // Record failure in circuit breaker
        $this->circuitBreaker->recordFailure();
        
        // Check if we have fallback data
        if ($fallbackData = $this->getFallbackData($context)) {
            return $context
                ->with($fallbackData)
                ->withTag('fallback_used')
                ->withMetadata('fallback_reason', $exception->getMessage());
        }

        return parent::onError($context, $exception);
    }

    private function getFallbackData(Context $context): ?array
    {
        // Try to get cached data as fallback
        return cache()->get("api_fallback_{$this->endpoint}_" . md5(serialize($context->payload)));
    }

    public function getRetryPolicy(): ?RetryPolicy
    {
        return RetryPolicy::exponentialBackoff()
            ->maxAttempts(3)
            ->baseDelay(1000)
            ->maxDelay(8000);
    }

    public function getName(): string
    {
        return "External API ({$this->endpoint})";
    }
}
```

## Background Job Processing

Integrate with Laravel queues for asynchronous processing.

### Queue Job Agent

```php
<?php

namespace App\Agents\Queue;

use App\Jobs\ProcessLargeDataset;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;

class DispatchJobAgent extends BaseAgent
{
    public function __construct(
        private string $queue = 'default',
        private int $delay = 0
    ) {}

    protected function handle(Context $context): Context
    {
        $data = $context->payload;
        
        // Dispatch job to queue
        ProcessLargeDataset::dispatch($data, $context->correlationId)
            ->onQueue($this->queue)
            ->delay(now()->addSeconds($this->delay));

        return $context
            ->withTag('job_dispatched')
            ->withMetadata('queue_name', $this->queue)
            ->withMetadata('dispatch_delay', $this->delay)
            ->withMetadata('dispatched_at', now()->toISOString());
    }

    public function getName(): string
    {
        return 'Queue Job Dispatcher';
    }
}
```

### Job Status Tracker

```php
<?php

namespace App\Agents\Queue;

use App\Models\JobStatus;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;

class TrackJobStatusAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $correlationId = $context->correlationId;
        
        // Create or update job status
        JobStatus::updateOrCreate(
            ['correlation_id' => $correlationId],
            [
                'status' => 'processing',
                'started_at' => now(),
                'metadata' => $context->metadata,
                'tags' => $context->tags
            ]
        );

        return $context->withTag('status_tracked');
    }

    public function getName(): string
    {
        return 'Job Status Tracker';
    }
}
```

## Testing Examples

### Unit Testing Agents

```php
<?php

namespace Tests\Unit\Agents;

use App\Agents\Order\ValidateOrderAgent;
use App\Models\Customer;
use App\Models\Order;
use Tests\TestCase;
use Vampires\Sentinels\Core\Context;

class ValidateOrderAgentTest extends TestCase
{
    public function test_validates_valid_order()
    {
        $customer = Customer::factory()->create(['is_blocked' => false]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'total' => 100.00,
            'payment_method' => 'credit_card'
        ]);
        
        $context = Context::create($order);
        $agent = new ValidateOrderAgent();
        
        $result = $agent($context);
        
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->hasTag('validated'));
        $this->assertEquals('validated', $order->fresh()->status);
    }

    public function test_rejects_blocked_customer()
    {
        $customer = Customer::factory()->create(['is_blocked' => true]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        
        $context = Context::create($order);
        $agent = new ValidateOrderAgent();
        
        $result = $agent($context);
        
        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasMetadata('validation_failed'));
    }
}
```

### Integration Testing Pipelines

```php
<?php

namespace Tests\Integration;

use App\Models\Order;
use App\Pipelines\OrderProcessingPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderProcessingPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_complete_order_successfully()
    {
        // Create test data
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => $product->price
        ]);

        $pipeline = new OrderProcessingPipeline();
        $result = $pipeline->process($order);

        // Assert successful processing
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->hasTag('payment_successful'));
        $this->assertTrue($result->hasTag('inventory_updated'));
        $this->assertTrue($result->hasTag('confirmation_email_sent'));
        
        // Assert database changes
        $this->assertEquals('paid', $order->fresh()->status);
        $this->assertEquals(8, $product->fresh()->stock_quantity);
    }
}
```

### Performance Testing

```php
<?php

namespace Tests\Performance;

use App\Models\Order;
use App\Pipelines\OrderProcessingPipeline;
use Tests\TestCase;

class PipelinePerformanceTest extends TestCase
{
    public function test_pipeline_processes_orders_within_sla()
    {
        $orders = Order::factory()->count(100)->create();
        $pipeline = new OrderProcessingPipeline();
        
        $startTime = microtime(true);
        
        foreach ($orders as $order) {
            $pipeline->process($order);
        }
        
        $executionTime = microtime(true) - $startTime;
        
        // Should process 100 orders in under 30 seconds (300ms per order)
        $this->assertLessThan(30, $executionTime);
        
        // Log performance metrics
        logger()->info('Performance test completed', [
            'orders_processed' => count($orders),
            'total_time' => $executionTime,
            'average_time_per_order' => $executionTime / count($orders)
        ]);
    }
}
```

## Best Practices Summary

### 1. Agent Design Patterns

- **Single Responsibility**: Each agent handles one specific task
- **Stateless Operations**: Agents don't maintain state between invocations
- **Error Recovery**: Implement compensation logic for critical operations
- **Resource Management**: Clean up resources in `afterExecute` hooks

### 2. Pipeline Composition

- **Logical Grouping**: Group related operations into focused pipelines
- **Error Boundaries**: Use nested pipelines to isolate failures
- **Performance Optimization**: Choose appropriate execution modes
- **Monitoring**: Add comprehensive logging and metrics

### 3. Testing Strategy

- **Unit Tests**: Test individual agents in isolation
- **Integration Tests**: Test complete pipelines with real dependencies
- **Performance Tests**: Validate SLA requirements under load
- **Error Scenario Tests**: Verify error handling and recovery

### 4. Production Deployment

- **Configuration Management**: Externalize timeouts, retry policies, and endpoints
- **Monitoring**: Implement health checks and alerting
- **Graceful Degradation**: Provide fallback mechanisms for external dependencies
- **Documentation**: Maintain runbooks for operational procedures

These examples demonstrate real-world applications of Sentinels that handle complex business processes with proper error handling, testing, and production considerations. Each pattern can be adapted to your specific domain requirements while maintaining the core principles of agent-based processing.