# Getting Started with Sentinels

Welcome to Sentinels! This guide will get you up and running with agent-based task orchestration in Laravel in under 5 minutes.

## Installation

Install Sentinels via Composer:

```bash
composer require vampires/sentinels
```

The package will auto-register its service provider and publish configuration files automatically.

## Configuration

Optionally publish the configuration file:

```bash
php artisan vendor:publish --provider="Vampires\Sentinels\SentinelsServiceProvider"
```

This creates `config/sentinels.php` where you can customize observability, retry policies, and other settings.

## Your First Agent

Create your first agent using the Artisan command:

```bash
php artisan make:agent ProcessOrderAgent
```

This generates `app/Agents/ProcessOrderAgent.php`:

```php
<?php

namespace App\Agents;

use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;

class ProcessOrderAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        // Get the order from the context
        $order = $context->payload;
        
        // Process the order (example logic)
        $order->status = 'processed';
        $order->processed_at = now();
        $order->save();
        
        // Return updated context
        return $context->with($order)
            ->withMetadata('processed_by', $this->getName())
            ->withTag('processed');
    }
    
    public function getName(): string
    {
        return 'Process Order Agent';
    }
    
    public function getDescription(): string
    {
        return 'Processes an order and updates its status';
    }
}
```

## Your First Pipeline

Now create a simple pipeline to process orders:

```php
<?php

namespace App\Services;

use App\Agents\ProcessOrderAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Pipeline\Pipeline;

class OrderService
{
    public function processOrder($order)
    {
        $context = Context::create($order);
        
        $result = Pipeline::create()
            ->pipe(new ProcessOrderAgent())
            ->process($context);
            
        return $result->payload;
    }
}
```

Or use the facade for cleaner syntax:

```php
use Vampires\Sentinels\Facades\Sentinels;

class OrderService
{
    public function processOrder($order)
    {
        $result = Sentinels::pipeline()
            ->pipe(new ProcessOrderAgent())
            ->through($order);
            
        return $result;
    }
}
```

## Using in Controllers

Here's how to use your pipeline in a Laravel controller:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function process(Request $request, Order $order, OrderService $orderService)
    {
        try {
            $processedOrder = $orderService->processOrder($order);
            
            return response()->json([
                'success' => true,
                'order' => $processedOrder,
                'message' => 'Order processed successfully'
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process order: ' . $e->getMessage()
            ], 500);
        }
    }
}
```

## Multiple Agents Pipeline

Create a more complex workflow with multiple agents:

```bash
php artisan make:agent ValidateOrderAgent
php artisan make:agent CheckInventoryAgent
php artisan make:agent ProcessPaymentAgent
php artisan make:agent SendNotificationAgent
```

Then chain them together:

```php
class OrderService
{
    public function processOrder($order)
    {
        return Sentinels::pipeline()
            ->pipe(new ValidateOrderAgent())      // Validate order data
            ->pipe(new CheckInventoryAgent())     // Check product availability
            ->pipe(new ProcessPaymentAgent())     // Process payment
            ->pipe(new ProcessOrderAgent())       // Update order status
            ->pipe(new SendNotificationAgent())   // Send confirmation
            ->through($order);
    }
}
```

## Error Handling

Add error handling to your pipeline:

```php
class OrderService
{
    public function processOrder($order)
    {
        return Sentinels::pipeline()
            ->pipe(new ValidateOrderAgent())
            ->pipe(new CheckInventoryAgent())
            ->pipe(new ProcessPaymentAgent())
            ->onError(function (Context $context, \Throwable $exception) {
                // Log the error
                logger()->error('Order processing failed', [
                    'order_id' => $context->payload->id,
                    'error' => $exception->getMessage(),
                    'correlation_id' => $context->correlationId
                ]);
                
                // Return fallback value or re-throw
                return $context->addError($exception->getMessage());
            })
            ->through($order);
    }
}
```

## Observability

Sentinels automatically provides:

- **Correlation IDs**: Every context has a unique ID for tracing
- **Timing Metrics**: Execution time for each agent
- **Event Logging**: Laravel events fired for pipeline lifecycle
- **Error Tracking**: Detailed error context and stack traces

Access this data through the context:

```php
$result = Sentinels::pipeline()
    ->pipe(new ProcessOrderAgent())
    ->through($order);

// Access execution metadata
$correlationId = $result->correlationId;
$executionTime = $result->getElapsedTime();
$metadata = $result->metadata;
$tags = $result->tags;
```

## Next Steps

Now that you have the basics working:

1. **[Read about Agents](agents.md)** - Learn to create powerful, reusable agents
2. **[Explore Pipelines](pipelines.md)** - Discover advanced pipeline patterns
3. **[Understand Context](context.md)** - Master the immutable context pattern
4. **[Handle Errors](error-handling.md)** - Build robust error recovery
5. **[Write Tests](testing.md)** - Test your agents and pipelines effectively

## Quick Troubleshooting

**Pipeline not executing?**
- Check that agents extend `BaseAgent`
- Ensure `handle()` method returns a Context
- Verify no exceptions in agent constructors

**Context data missing?**
- Remember Context is immutable - always return modified context
- Use `$context->with($data)` to update payload
- Use `$context->withMetadata()` to add metadata

**Performance issues?**
- Consider parallel execution mode for independent agents
- Profile individual agents using context timing data
- Check for N+1 queries in database-heavy agents

**Need help?** Check the [examples](examples.md) for common patterns and solutions!