# Agents Deep Dive

Agents are the core building blocks of Sentinels. They represent single units of work that can be composed into powerful workflows. This guide covers everything you need to know to create effective agents.

## Agent Lifecycle

Every agent follows a predictable lifecycle with hooks you can override:

```php
class ExampleAgent extends BaseAgent
{
    // 1. Validation (optional)
    protected function validatePayload(Context $context): ValidationResult
    {
        // Validate input before execution
    }
    
    // 2. Execution condition (optional)
    public function shouldExecute(Context $context): bool
    {
        // Decide if agent should run
    }
    
    // 3. Before execution hook (optional)
    protected function beforeExecute(Context $context): Context
    {
        // Setup, logging, preparation
    }
    
    // 4. Main execution (required)
    protected function handle(Context $context): Context
    {
        // Your business logic goes here
    }
    
    // 5. After execution hook (optional)
    protected function afterExecute(Context $originalContext, Context $result): Context
    {
        // Cleanup, logging, post-processing
    }
    
    // 6. Error handling hook (optional)
    protected function onError(Context $context, \Throwable $exception): Context
    {
        // Custom error handling
    }
}
```

## Creating Your First Agent

Generate a new agent:

```bash
php artisan make:agent EmailNotificationAgent
```

Implement the required methods:

```php
<?php

namespace App\Agents;

use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\ValidationResult;
use App\Mail\OrderProcessed;
use Illuminate\Support\Facades\Mail;

class EmailNotificationAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $order = $context->payload;
        
        // Send email notification
        Mail::to($order->customer->email)
            ->send(new OrderProcessed($order));
        
        return $context
            ->withMetadata('email_sent', true)
            ->withMetadata('email_sent_at', now()->toISOString())
            ->withTag('notification_sent');
    }
    
    public function getName(): string
    {
        return 'Email Notification Agent';
    }
    
    public function getDescription(): string
    {
        return 'Sends email notifications to customers';
    }
    
    protected function validatePayload(Context $context): ValidationResult
    {
        $order = $context->payload;
        
        if (!$order || !$order->customer || !$order->customer->email) {
            return ValidationResult::invalid([
                'customer_email' => ['Customer email is required']
            ]);
        }
        
        return ValidationResult::valid($order);
    }
}
```

## Agent Configuration

### Basic Metadata

Every agent should provide basic information:

```php
class ProcessPaymentAgent extends BaseAgent
{
    public function getName(): string
    {
        return 'Payment Processor';
    }
    
    public function getDescription(): string
    {
        return 'Processes credit card payments via Stripe';
    }
    
    public function getTags(): array
    {
        return ['payment', 'external_api', 'critical'];
    }
    
    public function getEstimatedExecutionTime(): int
    {
        return 2000; // milliseconds
    }
    
    public function getPriority(): int
    {
        return 10; // Higher numbers = higher priority
    }
}
```

### Input/Output Types

Specify expected data types for better pipeline composition:

```php
class DataTransformationAgent extends BaseAgent
{
    public function getInputType(): ?string
    {
        return 'array'; // Expects array input
    }
    
    public function getOutputType(): ?string
    {
        return 'object'; // Returns object
    }
    
    protected function handle(Context $context): Context
    {
        $data = $context->payload; // Array
        $object = (object) $data;  // Convert to object
        
        return $context->with($object);
    }
}
```

### Required Permissions

Define permissions needed for security-sensitive operations:

```php
class AdminOnlyAgent extends BaseAgent
{
    public function getRequiredPermissions(): array
    {
        return ['admin', 'manage_users'];
    }
    
    protected function handle(Context $context): Context
    {
        // Only executes if user has required permissions
        // Permission checking is handled by the pipeline
    }
}
```

## Advanced Agent Patterns

### Configurable Agents

Create agents that accept configuration:

```php
class DatabaseSyncAgent extends BaseAgent
{
    public function __construct(
        private string $tableName,
        private array $columns = ['*'],
        private bool $upsert = false
    ) {}
    
    protected function handle(Context $context): Context
    {
        $data = $context->payload;
        
        if ($this->upsert) {
            DB::table($this->tableName)->upsert($data, 'id');
        } else {
            DB::table($this->tableName)->insert($data);
        }
        
        return $context->withMetadata('synced_to', $this->tableName);
    }
    
    public function getName(): string
    {
        return "Database Sync Agent ({$this->tableName})";
    }
}

// Usage
$pipeline->pipe(new DatabaseSyncAgent('orders', ['id', 'status'], true));
```

### Conditional Execution

Agents that run based on context state:

```php
class ConditionalEmailAgent extends BaseAgent
{
    public function shouldExecute(Context $context): bool
    {
        $order = $context->payload;
        
        // Only send email for high-value orders
        return $order->total >= 1000;
    }
    
    protected function handle(Context $context): Context
    {
        // This only runs for orders >= $1000
        return $this->sendVipEmail($context);
    }
}
```

### Validation with Custom Rules

Implement complex validation logic:

```php
class OrderValidationAgent extends BaseAgent
{
    protected function validatePayload(Context $context): ValidationResult
    {
        $order = $context->payload;
        $errors = [];
        
        // Check order exists
        if (!$order || !$order->id) {
            return ValidationResult::requiredFieldMissing('order');
        }
        
        // Check inventory
        foreach ($order->items as $item) {
            if ($item->quantity > $item->product->stock) {
                $errors['inventory'][] = "Insufficient stock for {$item->product->name}";
            }
        }
        
        // Check business rules
        if ($order->total < 0) {
            $errors['total'][] = 'Order total cannot be negative';
        }
        
        if ($order->customer->blocked) {
            $errors['customer'][] = 'Customer account is blocked';
        }
        
        return empty($errors) 
            ? ValidationResult::valid($order)
            : ValidationResult::invalid($errors);
    }
    
    protected function handle(Context $context): Context
    {
        // Validation passed, mark as validated
        return $context->withTag('validated');
    }
}
```

### Error Recovery

Handle errors gracefully:

```php
class ResilientApiAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $data = $context->payload;
        
        // This might throw an exception
        $response = $this->callExternalApi($data);
        
        return $context->with($response);
    }
    
    protected function onError(Context $context, \Throwable $exception): Context
    {
        // Log the error
        logger()->error('API call failed', [
            'agent' => $this->getName(),
            'error' => $exception->getMessage(),
            'correlation_id' => $context->correlationId
        ]);
        
        // Return fallback data
        return $context
            ->withMetadata('api_failed', true)
            ->withMetadata('fallback_used', true)
            ->with($this->getFallbackData($context));
    }
    
    public function getRetryPolicy(): ?RetryPolicy
    {
        return RetryPolicy::exponentialBackoff()
            ->maxAttempts(3)
            ->baseDelay(1000)
            ->maxDelay(10000);
    }
}
```

### Lifecycle Hooks

Use hooks for cross-cutting concerns:

```php
class AuditedAgent extends BaseAgent
{
    protected function beforeExecute(Context $context): Context
    {
        // Log start of execution
        AuditLog::create([
            'action' => 'agent_started',
            'agent' => $this->getName(),
            'correlation_id' => $context->correlationId,
            'payload_hash' => md5(serialize($context->payload)),
            'user_id' => auth()->id(),
        ]);
        
        return $context->withMetadata('audit_started', true);
    }
    
    protected function afterExecute(Context $originalContext, Context $result): Context
    {
        // Log successful completion
        AuditLog::create([
            'action' => 'agent_completed',
            'agent' => $this->getName(),
            'correlation_id' => $result->correlationId,
            'execution_time' => $result->getElapsedTime(),
            'user_id' => auth()->id(),
        ]);
        
        return $result->withMetadata('audit_completed', true);
    }
}
```

## Agent Testing

### Unit Testing

Test agents in isolation:

```php
class EmailNotificationAgentTest extends TestCase
{
    public function test_sends_email_notification()
    {
        Mail::fake();
        
        $order = Order::factory()->create();
        $context = Context::create($order);
        $agent = new EmailNotificationAgent();
        
        $result = $agent($context);
        
        // Assert email was sent
        Mail::assertSent(OrderProcessed::class, function ($mail) use ($order) {
            return $mail->hasTo($order->customer->email);
        });
        
        // Assert context was updated
        $this->assertTrue($result->getMetadata('email_sent'));
        $this->assertTrue($result->hasTag('notification_sent'));
    }
    
    public function test_validates_customer_email()
    {
        $order = Order::factory()->create(['customer' => null]);
        $context = Context::create($order);
        $agent = new EmailNotificationAgent();
        
        $result = $agent($context);
        
        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasMetadata('validation_failed'));
    }
}
```

### Integration Testing

Test agents within pipelines:

```php
public function test_agent_in_pipeline()
{
    $order = Order::factory()->create();
    
    $result = Sentinels::pipeline()
        ->pipe(new ValidateOrderAgent())
        ->pipe(new EmailNotificationAgent())
        ->through($order);
        
    $this->assertTrue($result->hasTag('validated'));
    $this->assertTrue($result->hasTag('notification_sent'));
}
```

## Best Practices

### 1. Single Responsibility
Each agent should do one thing well:

```php
// ❌ Bad - does too many things
class OrderProcessingAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $this->validateOrder($context->payload);
        $this->processPayment($context->payload);
        $this->updateInventory($context->payload);
        $this->sendEmail($context->payload);
        // Too many responsibilities!
    }
}

// ✅ Good - focused responsibility
class ValidateOrderAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        // Only validates orders
        return $context->withTag('validated');
    }
}
```

### 2. Immutable Context

Always return a new context, never modify the existing one:

```php
// ❌ Bad - modifying context directly
protected function handle(Context $context): Context
{
    $context->metadata['processed'] = true; // Don't do this!
    return $context;
}

// ✅ Good - returning new context
protected function handle(Context $context): Context
{
    return $context->withMetadata('processed', true);
}
```

### 3. Meaningful Names

Use descriptive names that explain what the agent does:

```php
// ❌ Bad
class DataAgent extends BaseAgent {}

// ✅ Good
class ConvertCsvToJsonAgent extends BaseAgent {}
```

### 4. Error Context

Provide useful error information:

```php
protected function onError(Context $context, \Throwable $exception): Context
{
    return $context
        ->addError("Payment processing failed: {$exception->getMessage()}")
        ->withMetadata('error_code', $this->getErrorCode($exception))
        ->withMetadata('retry_after', 30) // seconds
        ->withTag('payment_failed');
}
```

### 5. Performance Considerations

For expensive operations, provide time estimates:

```php
public function getEstimatedExecutionTime(): int
{
    return 5000; // 5 seconds for API calls
}

// Consider caching for repeated operations
protected function handle(Context $context): Context
{
    $cacheKey = "agent_result_{$this->getName()}_{$context->correlationId}";
    
    return Cache::remember($cacheKey, 300, function () use ($context) {
        return $this->performExpensiveOperation($context);
    });
}
```

## Next Steps

- **[Learn about Pipelines](pipelines.md)** - Compose agents into workflows
- **[Master Context](context.md)** - Work effectively with immutable context
- **[Handle Errors](error-handling.md)** - Build robust error recovery
- **[See Examples](examples.md)** - Real-world agent patterns