# Native Laravel: every README example, side by side

This guide pairs every fenced example in the [Sentinels README](../README.md), in its original order, with a recommended **Laravel 13** approach. The Sentinels blocks are copied verbatim, including duplicated installation examples, illustrative output, and directory trees. They show what the README says; they are not a promise that every displayed package API is implemented. Notes identify examples that are illustrative or should not be treated as executable contracts.

Laravel already provides the execution building blocks. Choose by the behavior you need:

| Need | Start with | Result and failure behavior |
|---|---|---|
| Transform one value through ordered steps now | `Pipeline` | Each step passes a value to the next; exceptions propagate. |
| Run ordered background work durably | `Bus::chain()` | Jobs execute in order and stop on failure; return values are not passed to the next job. |
| Track independent background jobs | `Bus::batch()` | Jobs may finish in any order; persist domain results and use batch progress/callbacks. |
| Run independent operations and collect their values now | `Concurrency::run()` | Temporary PHP processes return serialized results; this is not a durable queue workflow. |
| React independently to something that happened | Events and listeners | Listeners decide their own work; queued listeners require workers. |

## Conventions used below

The recommended examples use Laravel facades such as `Pipeline`, `Bus`, `Concurrency`, `Context`, `Log`, `Event`, `Http`, `Queue`, and `Notification` from `Illuminate\Support\Facades`. Import the facades you use. Laravel's `Context` facade carries diagnostic information; it is **not** an immutable domain payload object or an automatic error accumulator.

Business names such as `ValidateOrder`, `ChargePayment`, `OrderProcessed`, and `PaymentGateway` are application classes you implement. Pipeline steps use Laravel's middleware-shaped callable contract; ordinary actions use the application's own method signatures. These snippets illustrate composition, not a ready-made order processing application.

A complete minimal synchronous step looks like this:

```php
namespace App\Pipelines;

use Closure;

final class TrimName
{
    public function __invoke(array $data, Closure $next): mixed
    {
        $data['name'] = trim($data['name']);

        return $next($data);
    }
}

// From a controller or service:
$result = \Illuminate\Support\Facades\Pipeline::send(['name' => ' Ada '])
    ->through([TrimName::class])
    ->thenReturn(); // ['name' => 'Ada']
```

Laravel resolves pipeline class names through its container, so constructor injection works. A step may transform data, stop by returning without calling `$next`, or throw an exception. Define those choices deliberately.

A complete queue job shape, usable in a batch or chain, is:

```php
namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class RecordOrderAudit implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;
    public int $timeout = 30;

    public function __construct(public int $orderId) {}

    public function backoff(): array
    {
        return [1, 5]; // Seconds between attempts.
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        Log::info('Order audited', ['order_id' => $this->orderId]);
    }
}
```

Every application job used in a batch below needs `Batchable`; every background job needs `ShouldQueue`. The combined Laravel `Queueable` trait supplies dispatching and queue/model serialization behavior. Use IDs or small serializable values at process boundaries, reload domain records in jobs, and make side effects idempotent because retries can execute them again.

For durable examples, configure a non-`sync` queue connection. For the database driver, provision `jobs` and `job_batches` migrations if absent, then start workers:

```sh
# .env: QUEUE_CONNECTION=database
php artisan make:queue-table
php artisan make:queue-batches-table
php artisan migrate
php artisan queue:work database --queue=high-priority,default
```

Recent Laravel application skeletons may already contain those migrations. Multiple workers provide batch concurrency; one worker processes jobs one at a time. Keep job timeouts several seconds below `retry_after`; Laravel's worker timeout enforcement requires PCNTL. External HTTP/database clients need their own timeouts. Batch callbacks are serialized: use static closures, capture IDs instead of service objects, and resolve services inside callbacks. Queue chains share persisted state, not PHP return values.

`Concurrency::run()` uses temporary PHP processes by default and serializes closures and return values. Capture small values rather than service instances, resolve dependencies inside the closure, and use a durable queue when work must be tracked and retried independently of the initiating request. `fork` has separate CLI/dependency requirements; these examples use the default process driver.

Official references: [Pipeline](https://laravel.com/docs/13.x/helpers#pipeline), [queues, chains, and batches](https://laravel.com/docs/13.x/queues), [Concurrency](https://laravel.com/docs/13.x/concurrency), [Context](https://laravel.com/docs/13.x/context), [events](https://laravel.com/docs/13.x/events), [HTTP client](https://laravel.com/docs/13.x/http-client), and [mocking](https://laravel.com/docs/13.x/mocking). These recommendations target Laravel 13, not every version supported by Sentinels.

## README examples

### 1. Cache serialization

**Sentinels README example**

```php
'serializable_classes' => [
    \Vampires\Sentinels\Core\Context::class,
],
```

**Recommended Laravel 13 approach**

Prefer plain arrays or scalar values for cached workflow results, so there is no package Context class to allowlist:

```php
Cache::put("orders:{$orderId}:validation", [
    'valid' => true,
    'checked_at' => now()->toIso8601String(),
], now()->addMinutes(10));
```

Laravel 13's cache object unserialization restrictions still apply to any application objects you choose to cache. A database result record is often a better durable source of truth than an expiring cache entry. See the [Laravel 13 upgrade guide](https://laravel.com/docs/13.x/upgrade).

### 2. Replace a monolithic order service

**Sentinels README example**

```php
// 😰 Before: One massive, untestable method
class OrderService {
    public function processOrder($order) {
        // 200+ lines of mixed concerns that all live in your head
        $this->validateInventory($order);    // What if this fails?
        $this->processPayment($order);       // Where's the logging?
        $this->updateInventory($order);      // How do you test this?
        $this->sendNotifications($order);    // Why did this break?
        $this->updateAnalytics($order);      // Good luck debugging!
        // When it breaks, where do you even start looking?
    }
}

// 🚀 After: Clean, testable, traceable pipeline
$result = Sentinels::pipeline()
    ->pipe(new ValidateInventoryAgent())    // ✅ Single responsibility
    ->pipe(new ProcessPaymentAgent())       // ✅ Individual error handling  
    ->pipe(new UpdateInventoryAgent())      // ✅ Easy to test in isolation
    ->pipe(new SendNotificationAgent())     // ✅ Automatic correlation IDs
    ->pipe(new UpdateAnalyticsAgent())      // ✅ Built-in performance metrics
    ->through($order);
// Every step is logged, timed, and traceable. Debug like a detective. 🕵️‍♂️
```

**Recommended Laravel 13 approach**

Keep focused application steps and use the native synchronous pipeline:

```php
$result = Pipeline::send($order)
    ->through([
        ValidateInventory::class,
        ProcessPayment::class,
        UpdateInventory::class,
        SendNotification::class,
        UpdateAnalytics::class,
    ])
    ->thenReturn();
```

Each class follows the callable shape shown above. This composition does not automatically add per-step logging, metrics, or compensation; add those explicitly where they are needed.

### 3. Queued async work and progress

**Sentinels README example**

```php
$pending = Sentinels::pipeline()
    ->mode('parallel')
    ->async()
    ->pipe(new ApiCallAgent())
    ->pipe(new DatabaseAgent())
    ->pipe(new EmailServiceAgent())
    ->through($data);

echo "Progress: " . $pending->getProgress() . "%";
$result = $pending->result(timeout: 60); // wait() also returns the completed Context
$payload = $result->payload;
```

**Recommended Laravel 13 approach**

Use a batch for independent jobs and persist each result under a workflow ID:

```php
$batch = Bus::batch([
    new FetchApiData($workflowId),
    new QueryDatabase($workflowId),
    new SendWorkflowEmail($workflowId),
])->name('Workflow '.$workflowId)->dispatch();

// In a later status request:
$batch = Bus::findBatch($batchId);
if ($batch === null) {
    abort(404);
}

return [
    'progress' => $batch->progress(),
    'finished' => $batch->finished(),
    'failed_jobs' => $batch->failedJobs,
];
```

Each job stores its own result, for example in a `workflow_results` table keyed by workflow ID and operation. A batch has no Sentinels-style `result()` payload; fetch the persisted results separately. Do not batch steps that depend on each other's output.

### 4. A second order-processing pipeline

**Sentinels README example**

```php
// Instead of this monolithic service...
class OrderProcessor 
{
    public function process($order) {
        $this->validateOrder($order);
        $this->checkInventory($order);  
        $this->processPayment($order);
        $this->sendNotification($order);
        // 200+ lines of tightly coupled logic...
    }
}

// You get this clean, testable pipeline...
$result = Sentinels::pipeline()
    ->pipe(new ValidateOrderAgent())
    ->pipe(new CheckInventoryAgent())
    ->pipe(new ProcessPaymentAgent())
    ->pipe(new SendNotificationAgent())
    ->through($order);
```

**Recommended Laravel 13 approach**

The same native contract works for validation, inventory, payment, and notification:

```php
$result = Pipeline::send($order)
    ->through([
        ValidateOrder::class,
        CheckInventory::class,
        ProcessPayment::class,
        SendNotification::class,
    ])
    ->thenReturn();
```

Use an application service or a chain instead if these steps must survive a request or process restart. Neither option makes payment and inventory updates atomic across external services.

### 5. Install and configure

**Sentinels README example**

```bash
composer require vampires/sentinels
php artisan vendor:publish --tag=sentinels-config
```

**Recommended Laravel 13 approach**

Pipeline, jobs, events, and concurrency ship with a Laravel 13 application. There is no replacement orchestration package to install or publish. Generate the application classes you need:

```sh
php artisan make:class Pipelines/ValidateOrder
php artisan make:job ProcessOrder
```

Queue setup is an application deployment concern; use the prerequisites above when choosing queued execution.

### 6. Generate the first processing class

**Sentinels README example**

```bash
# Generate your agent
php artisan make:agent ProcessOrderAgent

# It creates this focused class
class ProcessOrderAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        $order = $context->payload;
        
        // Do your processing here
        $processedOrder = $this->processOrder($order);
        
        return $context->with($processedOrder);
    }
}
```

**Recommended Laravel 13 approach**

Generate an ordinary class, then implement the pipeline callable contract. Keep shell commands and PHP in separate files:

```sh
php artisan make:class Pipelines/ProcessOrder
```

```php
namespace App\Pipelines;

use App\Services\OrderProcessor;
use Closure;

final class ProcessOrder
{
    public function __construct(private OrderProcessor $processor) {}

    public function __invoke(mixed $order, Closure $next): mixed
    {
        return $next($this->processor->process($order));
    }
}
```

`OrderProcessor` is your business service. The README's original fence mixes a shell command and PHP; it is reproduced as source material rather than an executable shell script.

### 7. Call an action or compose a pipeline

**Sentinels README example**

```php
// Simple processing
$result = Sentinels::process($order, new ProcessOrderAgent());

// Or build a pipeline  
$result = Sentinels::pipeline()
    ->pipe(new ValidateOrderAgent())
    ->pipe(new ProcessOrderAgent()) 
    ->pipe(new SendEmailAgent())
    ->through($order);

// That's it! Every step is now logged, timed, and traceable. 🎉
```

**Recommended Laravel 13 approach**

Call a single action directly; use a pipeline when ordering several reusable steps helps:

```php
$result = app(ProcessOrderAction::class)->handle($order);

$result = Pipeline::send($order)
    ->through([ValidateOrder::class, ProcessOrder::class, SendEmail::class])
    ->thenReturn();
```

The action's `handle()` is your own API; the pipeline classes use `__invoke($value, $next)`. Native Laravel does not add automatic telemetry to arbitrary actions.

### 8. Split the service from hell

**Sentinels README example**

```php
// ❌ The 500-line service method that haunts your dreams
class OrderService {
    public function processOrder($order) {
        // 🔥 This method has EVERYTHING:
        $this->validateStuff($order);      // 50 lines of validation
        $this->chargePayment($order);      // 75 lines of payment logic  
        $this->doShipping($order);         // 40 lines of shipping
        $this->sendEmails($order);         // 60 lines of notifications
        $this->updateAnalytics($order);    // 35 lines of tracking
        // Testing this? Good luck. Debugging? Therapy time. 😵‍💫
    }
}

// ✅ Clean, focused, debuggable pipeline
$result = Sentinels::pipeline()
    ->pipe(new ValidateOrderAgent())       // ✅ 25 lines, one job
    ->pipe(new ChargePaymentAgent())       // ✅ Testable in isolation  
    ->pipe(new HandleShippingAgent())      // ✅ Mock external APIs easily
    ->pipe(new SendNotificationAgent())    // ✅ Individual error handling
    ->pipe(new TrackAnalyticsAgent())      // ✅ Single responsibility
    ->through($order);
// Each step is focused, testable, and debuggable. Your future self will thank you. 🙏
```

**Recommended Laravel 13 approach**

Extract narrowly named classes without adopting a framework-specific base class:

```php
$result = Pipeline::send($order)
    ->through([
        ValidateOrder::class,
        ChargePayment::class,
        HandleShipping::class,
        SendNotification::class,
        TrackAnalytics::class,
    ])
    ->thenReturn();
```

Use constructor injection for gateways and repositories. Test each business operation and then the small amount of orchestration that matters.

### 9. Correlate diagnostic logs

**Sentinels README example**

```php
// ❌ Production breaks at 3 AM. The log says:
Log::error("Something broke in order processing");

// 😰 Which of the 15 steps failed? What data caused it? 
// Where do you even start looking? Time to wake up the entire team...

// ✅ Sentinels gives you DETECTIVE-LEVEL debugging:
// Every request gets a correlation ID that traces through EVERYTHING:

[2024-01-15 03:07:43] INFO: Agent starting
  • agent: ValidateOrderAgent  
  • correlation_id: req_789abc123
  • user_id: 12345
  • input_state: {...}

[2024-01-15 03:07:44] ERROR: Agent failed  
  • agent: ProcessPaymentAgent ⬅️ FOUND THE CULPRIT!
  • correlation_id: req_789abc123
  • error: "Card declined: insufficient funds"
  • execution_time: 1.2s

// Now you know EXACTLY what broke, for which user, and why. 
// Fix it in 5 minutes instead of 2 hours. 😎
```

**Recommended Laravel 13 approach**

Use Laravel Context for diagnostic data and write useful logs at the operation boundary:

```php
Context::add('correlation_id', (string) \Illuminate\Support\Str::uuid());
Context::add('user_id', $userId);
$started = hrtime(true);

try {
    $result = app(ProcessPaymentAction::class)->handle($orderId);
} catch (\Throwable $error) {
    Log::error('Payment failed', [
        'order_id' => $orderId,
        'exception' => $error,
        'elapsed_ms' => (hrtime(true) - $started) / 1_000_000,
    ]);
    throw $error;
}
```

Laravel includes Context data in logs and supports transferring it to queued jobs. Avoid logging payment credentials or entire sensitive payloads. The copied original block contains illustrative log output, not executable PHP.

### 10. Reuse content processing across domains

**Sentinels README example**

```php
// ❌ You have similar workflows everywhere, but they're all different:
class BlogProcessor {
    public function process($post) {
        $this->validateContent($post);    // 30 lines of validation
        $this->optimizeImages($post);     // 45 lines of image processing  
        $this->generateSEO($post);        // 25 lines of SEO logic
    }
}

class ProductProcessor {  
    public function process($product) {
        $this->validateContent($product);  // Copy-pasted (but slightly different)
        $this->optimizeImages($product);   // Copy-pasted (with bugs)
        $this->generateSEO($product);      // Copy-pasted (missing features)
    }
}
// Result: 6 different validation methods. When you fix a bug, you fix it in 6 places. 😭

// ✅ Write once, reuse everywhere:
$contentPipeline = [
    new ValidateContentAgent(),    // Works for ANY content
    new OptimizeImagesAgent(),     // Handles all image types
    new GenerateSEOAgent(),        // SEO for everything
];

// Same agents, different contexts
$blogResult = Sentinels::pipeline()->pipes($contentPipeline)->through($blogPost);
$productResult = Sentinels::pipeline()->pipes($contentPipeline)->through($product);
$pageResult = Sentinels::pipeline()->pipes($contentPipeline)->through($landingPage);

// One bug fix updates EVERYWHERE. One improvement benefits ALL workflows. 🎉
```

**Recommended Laravel 13 approach**

Reuse a list of container-resolved pipeline classes:

```php
$steps = [ValidateContent::class, OptimizeImages::class, GenerateSeo::class];

$blogResult = Pipeline::send($blogPost)->through($steps)->thenReturn();
$productResult = Pipeline::send($product)->through($steps)->thenReturn();
$pageResult = Pipeline::send($landingPage)->through($steps)->thenReturn();
```

Agree on a common payload contract or adapt each domain model into an application DTO. Sharing classes is useful only when the business behavior is actually shared.

### 11. AI and language processing

**Sentinels README example**

```php
// Process user input through multiple AI analysis steps
$result = Sentinels::pipeline()
    ->pipe(new ContentModerationAgent())      // Check for harmful content
    ->pipe(new LanguageDetectionAgent())      // Detect input language  
    ->pipe(new IntentClassificationAgent())   // Classify user intent
    ->pipe(new ResponseGenerationAgent())     // Generate appropriate response
    ->pipe(new QualityAssuranceAgent())       // Validate response quality
    ->through($userMessage);
```

**Recommended Laravel 13 approach**

Keep dependent operations sequential and implement model calls behind application services:

```php
$result = Pipeline::send($userMessage)
    ->through([
        ModerateContent::class,
        DetectLanguage::class,
        ClassifyIntent::class,
        GenerateResponse::class,
        CheckResponseQuality::class,
    ])
    ->thenReturn();
```

Add HTTP timeouts and retries inside the step that calls the provider. Laravel's pipeline coordinates your code; it does not supply the moderation or model behavior.

### 12. Multi-channel notifications

**Sentinels README example**

```php
// Send notifications to multiple channels in parallel
$result = Sentinels::pipeline()
    ->mode('parallel')
    ->async()                                 // 👈 True parallel delivery!
    ->pipe(new EmailNotificationAgent())     // Send email
    ->pipe(new SMSNotificationAgent())       // Send SMS  
    ->pipe(new PushNotificationAgent())      // Send push notification
    ->pipe(new SlackNotificationAgent())     // Send to Slack
    ->through($notificationRequest);

// Wait for all channels and retrieve the aggregated Context
$result = $result->result();
echo "Notifications sent: " . count($result->payload);
echo "Delivery rate: " . $result->getSuccessRate() . "%";
```

**Recommended Laravel 13 approach**

For standard Laravel notification channels, implement an application notification with `ShouldQueue` and let Laravel queue its deliveries:

```php
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

final class OrderReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    // Implement toMail() and toDatabase() for the application.
}

$user->notify(new OrderReady());
```

This is a notification shape, with channel message methods still to implement. Additional channels may require their own adapters. If a single aggregate status is essential, use explicit batch jobs that persist per-channel delivery outcomes; queue completion alone does not prove delivery to a recipient. The original `getSuccessRate()` call is illustrative and should not be assumed to be a supported Context API. See [notifications](https://laravel.com/docs/13.x/notifications).

### 13. ETL transformations

**Sentinels README example**

```php
// Transform data through validation, enrichment, and normalization
Sentinels::pipeline()
    ->pipe(new DataValidationAgent())         // Validate incoming data structure
    ->pipe(new DuplicationDetectionAgent())   // Remove or flag duplicates
    ->pipe(new DataEnrichmentAgent())         // Add external data (geo, demographics)
    ->pipe(new NormalizationAgent())          // Standardize formats and units
    ->pipe(new QualityScoreAgent())           // Assign data quality scores
    ->pipe(new DatabaseInsertAgent())         // Persist to appropriate tables
    ->through($rawDataBatch);
```

**Recommended Laravel 13 approach**

Use a synchronous pipeline for one bounded batch of rows:

```php
$result = Pipeline::send($rawDataBatch)
    ->through([
        ValidateData::class,
        DetectDuplicates::class,
        EnrichData::class,
        NormalizeData::class,
        ScoreDataQuality::class,
        InsertData::class,
    ])
    ->thenReturn();
```

For large imports, read chunks and queue a job per chunk rather than serializing the entire dataset into each job. Make inserts idempotent and define how a failed chunk is resumed.

### 14. Parallel checks followed by fulfillment

**Sentinels README example**

```php
// Process order validation steps in parallel for faster checkout
$orderResult = Sentinels::pipeline()
    ->mode('parallel')
    ->async()                                 // 👈 Parallel validation
    ->pipe(new InventoryCheckAgent())        // Check stock availability
    ->pipe(new PaymentValidationAgent())     // Validate payment method
    ->pipe(new FraudDetectionAgent())        // Run fraud analysis
    ->pipe(new TaxCalculationAgent())        // Calculate taxes
    ->through($orderData);

// Retrieve completed validation results before checking errors
$orderResult = $orderResult->result();
if ($orderResult->hasErrors()) {
    return $orderResult->errors;             // Show validation failures
}

// Continue with fulfillment after validation passes
$fulfillmentResult = Sentinels::pipeline()
    ->pipe(new PaymentProcessingAgent())     // Process payment
    ->pipe(new InventoryAllocationAgent())   // Reserve inventory
    ->pipe(new ShippingLabelAgent())         // Generate shipping
    ->through($orderResult->payload);

echo "Order processed in " . $orderResult->getElapsedTime() . " seconds";
```

**Recommended Laravel 13 approach**

For independent short-lived checks, collect explicit results with concurrency, then execute dependent fulfillment sequentially:

```php
$orderId = $order->getKey();
[$stock, $payment, $fraud, $tax] = Concurrency::run([
    static fn () => app(InventoryCheck::class)->handle($orderId),
    static fn () => app(PaymentValidation::class)->handle($orderId),
    static fn () => app(FraudDetection::class)->handle($orderId),
    static fn () => app(TaxCalculation::class)->handle($orderId),
]);

// These application actions return ['valid' => bool, ...] arrays.
if (! $stock['valid'] || ! $payment['valid'] || ! $fraud['valid'] || ! $tax['valid']) {
    return ['stock' => $stock, 'payment' => $payment, 'fraud' => $fraud, 'tax' => $tax];
}

$result = Pipeline::send(['order_id' => $orderId, 'tax' => $tax])
    ->through([ProcessPayment::class, AllocateInventory::class, CreateShippingLabel::class])
    ->thenReturn();
```

An exception from a concurrent operation propagates instead of becoming an error bag. For durable checks, use a batch with persisted results and dispatch fulfillment from its success path. Inventory may change after checking; reservations and payment idempotency remain business requirements.

### 15. Organize application files

**Sentinels README example**

```php
// Before: Monolithic, hard-to-navigate codebase
app/
├── Services/
│   └── OrderService.php (2,000+ lines mixing everything)

// After: Clear, domain-focused agents
app/
├── Agents/
│   ├── Order/
│   │   ├── ValidateInventoryAgent.php (50 lines, single purpose)
│   │   ├── CalculatePricingAgent.php (75 lines, focused)
│   │   └── ProcessPaymentAgent.php (60 lines, testable)
│   └── Shared/
│       ├── NotificationAgent.php (reusable across domains)
│       └── AuditLogAgent.php (shared infrastructure)
```

**Recommended Laravel 13 approach**

Use ordinary application folders for synchronous steps, background jobs, and services:

```text
app/
├── Pipelines/Order/
│   ├── ValidateInventory.php
│   └── CalculatePricing.php
├── Jobs/Order/
│   └── ProcessPayment.php
└── Services/
    ├── NotificationService.php
    └── AuditLogger.php
```

Folder names are an application convention. Small focused classes do not require a package base class.

### 16. Business completion metrics

**Sentinels README example**

```php
// Built-in metrics provide business insights
Event::listen(PipelineCompleted::class, function ($event) {
    // Track conversion funnel metrics
    Metrics::increment('orders.processed');
    Metrics::timing('orders.processing_time', $event->getExecutionTime());
    
    // Identify bottlenecks in real-time
    if ($event->getExecutionTime() > 5000) {
        Alert::send('Slow order processing detected');
    }
});
```

**Recommended Laravel 13 approach**

Define an application event and log a measurable duration. Laravel provides events and logging, not the README's application-specific `Metrics` or `Alert` facades:

```php
final readonly class OrderProcessed
{
    public function __construct(public int $orderId, public float $elapsedMs) {}
}

Event::listen(OrderProcessed::class, static function (OrderProcessed $event): void {
    Log::info('Order processed', [
        'order_id' => $event->orderId,
        'elapsed_ms' => $event->elapsedMs,
        'slow' => $event->elapsedMs > 5000,
    ]);
});

// After successful processing:
event(new OrderProcessed($orderId, $elapsedMs));
```

A metrics exporter or alert transport is a separate integration. See [events](https://laravel.com/docs/13.x/events).

### 17. Production observability

**Sentinels README example**

```php
// Rich telemetry out of the box
$pipeline = Sentinels::pipeline()
    ->withMetrics()      // Automatic performance metrics
    ->withTracing()      // Distributed tracing support
    ->withLogging()      // Structured logging
    ->pipe(new ProcessOrderAgent())
    ->through($order);

// Integrates with monitoring tools
// - Prometheus metrics
// - OpenTelemetry traces  
// - ELK stack logs
```

**Recommended Laravel 13 approach**

Instrument the application operation explicitly with Context and structured logs:

```php
Context::add('workflow', 'process-order');
Context::add('order_id', $orderId);
$started = hrtime(true);

try {
    $result = app(ProcessOrderAction::class)->handle($orderId);
} finally {
    Log::info('Order processing attempt finished', [
        'elapsed_ms' => (hrtime(true) - $started) / 1_000_000,
    ]);
}
```

Laravel's native logging is not an OpenTelemetry or Prometheus exporter. Add an exporter only when required. The original fluent `withMetrics()`, `withTracing()`, and `withLogging()` methods are illustrative and are not established package APIs.

### 18. Route background work to a dedicated queue

**Sentinels README example**

```php
// Different execution strategies for different needs
config(['sentinels.async.queue' => 'high-priority']);

Sentinels::pipeline()
    ->mode('parallel')
    ->async()             // Run agents through configured queue workers
    ->pipe($agents)
    ->through($data);
```

**Recommended Laravel 13 approach**

Select the queue on the batch, rather than expecting individual batched job settings to determine it:

```php
$batch = Bus::batch([
    new FetchInventory($orderId),
    new FetchPricing($orderId),
])->onQueue('high-priority')->dispatch();
```

Run workers that listen on `high-priority`. All jobs in a batch must use the same connection and queue; `onConnection()` can select a configured queue connection. Independent jobs may run concurrently when workers are available.

### 19. Quality assurance for focused classes

**Sentinels README example**

```php
class AgentQualityTest extends TestCase
{
    public function test_all_agents_have_tests()
    {
        $agents = glob('app/Agents/**/*Agent.php');
        
        foreach ($agents as $agentFile) {
            $testFile = str_replace('app/', 'tests/Unit/', $agentFile);
            $testFile = str_replace('.php', 'Test.php', $testFile);
            
            $this->assertFileExists($testFile, 
                "Missing test for {$agentFile}");
        }
    }
}
```

**Recommended Laravel 13 approach**

Prefer a behavior test over checking only that a test filename exists:

```php
use App\Pipelines\TrimName;
use PHPUnit\Framework\TestCase;

final class TrimNameTest extends TestCase
{
    public function test_it_trims_a_name(): void
    {
        $result = (new TrimName())(['name' => ' Ada '], fn ($data) => $data);

        $this->assertSame(['name' => 'Ada'], $result);
    }
}
```

This tests the small step defined at the start of this guide. A filesystem convention cannot establish that a class's behavior is covered.

### 20. Repeated installation example

**Sentinels README example**

```bash
composer require vampires/sentinels
```

**Recommended Laravel 13 approach**

An existing Laravel 13 application already includes these primitives. Generate a class instead of adding Sentinels:

```sh
php artisan make:class Pipelines/ProcessOrder
```

For a new application, use Laravel's normal application creation instructions; this guide assumes the Laravel project already exists.

### 21. Repeated configuration publication example

**Sentinels README example**

```bash
php artisan vendor:publish --tag=sentinels-config
```

**Recommended Laravel 13 approach**

There is no native workflow-package config file to publish. Configure the Laravel subsystem actually being used:

```dotenv
QUEUE_CONNECTION=database
```

Use `config/queue.php`, `config/logging.php`, and application-specific config files as needed. Provision queue storage and start workers before choosing background execution.

### 22. Immutable payload and diagnostic context

**Sentinels README example**

```php
// Context is immutable - each operation returns a new instance
$original = Context::create('data');
$modified = $original->with('new data');
$withMeta = $modified->withMetadata('key', 'value');

// Rich metadata support
$context = $context
    ->withTag('processed')
    ->withCorrelationId('req-123')
    ->withTraceId('trace-456');
```

**Recommended Laravel 13 approach**

Separate domain data from diagnostic context. A small PHP readonly DTO preserves immutable payload conventions if your application benefits from them:

```php
final readonly class WorkflowData
{
    public function __construct(public mixed $payload, public array $metadata = []) {}

    public function withPayload(mixed $payload): self
    {
        return new self($payload, $this->metadata);
    }
}

$original = new WorkflowData('data', ['key' => 'value']);
$modified = $original->withPayload('new data');
Context::add('correlation_id', 'req-123');
Context::add('trace_id', 'trace-456');
```

Readonly is shallow: objects stored inside the payload can still be mutable. Define copy methods or tags only when the domain needs them. Laravel Context is mutable diagnostic state; it does not replace this DTO. The original `withTraceId()` call should be treated as illustrative, not a verified Sentinels API.

### 23. Before, after, and error hooks

**Sentinels README example**

```php
class MyAgent extends BaseAgent
{
    protected function beforeExecute(Context $context): Context
    {
        // Setup, logging, authentication
        return $context->withMetadata('started_at', now());
    }

    protected function handle(Context $context): Context
    {
        // Your main logic here
        return $context->with($processedData);
    }

    protected function afterExecute(Context $original, Context $result): Context
    {
        // Cleanup, metrics, post-processing
        return $result->withMetadata('completed_at', now());
    }

    protected function onError(Context $context, \Throwable $exception): Context
    {
        // Error handling and recovery
        return $context->addError($exception->getMessage());
    }
}
```

**Recommended Laravel 13 approach**

Wrap a pipeline step with normal `try`, `catch`, and `finally` control flow:

```php
use Closure;

final class ProcessWithLogging
{
    public function __invoke(mixed $value, Closure $next): mixed
    {
        $started = hrtime(true);
        Log::info('Step starting');

        try {
            $processed = app(ProcessDataAction::class)->handle($value);
            return $next($processed);
        } catch (\Throwable $error) {
            report($error);
            throw $error;
        } finally {
            Log::info('Step finished', ['elapsed_ms' => (hrtime(true) - $started) / 1_000_000]);
        }
    }
}
```

Because `$next()` runs inside the `try`, this wrapper also observes downstream failures. Move the call outside the `try` if only this operation should be measured. Recovery must be an explicit business choice, not silently swallowing every exception.

### 24. Sequential, parallel, map, and branch behavior

**Sentinels README example**

```php
// Sequential (default)
$pipeline->mode('sequential')->pipe($agent1)->pipe($agent2);

// Parallel execution
$pipeline->mode('parallel')->pipe($agent1)->pipe($agent2);

// Map/Reduce for collections
$pipeline->mode('map_reduce')->pipe($transformAgent);

// Conditional branching
$pipeline->branch(
    fn($ctx) => $ctx->hasTag('special'),
    $specialPipeline,
    $normalPipeline
);
```

**Recommended Laravel 13 approach**

Use a separate native tool for each behavior:

```php
$sequential = Pipeline::send($data)->through([StepOne::class, StepTwo::class])->thenReturn();

[$first, $second] = Concurrency::run([
    static fn () => app(FirstAction::class)->handle($id),
    static fn () => app(SecondAction::class)->handle($id),
]);

$total = collect($rows)->map(fn ($row) => app(TransformRow::class)->handle($row))->sum('amount');

$steps = $isSpecial ? [SpecialStep::class] : [NormalStep::class];
$branched = Pipeline::send($data)->through($steps)->thenReturn();
```

`map()` here is sequential; `Concurrency` runs independent operations in processes. The current Sentinels synchronous parallel and map/reduce paths loop sequentially; add `async()` for its queued path. Branch callables in the copied README are illustrative and require checking the actual package signature.

### 25. Content-based routing

**Sentinels README example**

```php
use Vampires\Sentinels\Contracts\RouterContract;

$router = app(RouterContract::class);

// Route based on payload type
$router->addTypeRoute('string', new StringProcessor());
$router->addTypeRoute('array', new ArrayProcessor());

// Route based on content patterns
$router->addPatternRoute('/^email:/', new EmailAgent());
$router->addPatternRoute('/^sms:/', new SmsAgent());

// Route based on metadata
$router->addMetadataRoute('type', 'urgent', new UrgentProcessor());
```

**Recommended Laravel 13 approach**

Use explicit application decisions and container resolution:

```php
$processor = match (true) {
    ($metadata['type'] ?? null) === 'urgent' => UrgentProcessor::class,
    is_string($payload) && str_starts_with($payload, 'email:') => EmailProcessor::class,
    is_string($payload) && str_starts_with($payload, 'sms:') => SmsProcessor::class,
    is_array($payload) => ArrayProcessor::class,
    is_string($payload) => StringProcessor::class,
    default => throw new \InvalidArgumentException('Unsupported payload'),
};

$result = app($processor)->handle($payload);
```

The order of rules defines precedence; test it. Add an interface or dedicated router only when multiple call sites need the same policy. The original router registration methods are not established as supported APIs by this comparison.

### 26. Retry an external request

**Sentinels README example**

```php
use Vampires\Sentinels\Core\RetryPolicy;

class ResilientAgent extends BaseAgent
{
    public function getRetryPolicy(): RetryPolicy
    {
        return RetryPolicy::exponential(
            attempts: 3,
            baseDelay: 1000,
            maxDelay: 10000
        );
    }
    
    protected function handle(Context $context): Context
    {
        // This will retry with exponential backoff on failure
        return $context->with($this->callExternalAPI());
    }
}
```

**Recommended Laravel 13 approach**

Retry the failure-prone operation at its boundary. Laravel's HTTP client provides bounded attempts, delays, and timeouts:

```php
$response = Http::connectTimeout(3)
    ->timeout(10)
    ->retry([1000, 2000])
    ->get($endpoint)
    ->throw();

$data = $response->json();
```

The delay array is in milliseconds and schedules retries after the initial attempt. Choose which failures should be retried, especially for non-idempotent requests. For background jobs use `$tries`, `backoff()`, and `$timeout` as shown above; job delays are in seconds. Do not accidentally multiply HTTP retries by job retries without considering the total attempts.

### 27. Observe starts and completions

**Sentinels README example**

```php
use Vampires\Sentinels\Events\AgentStarted;
use Vampires\Sentinels\Events\PipelineCompleted;

// Listen to agent execution events
Event::listen(AgentStarted::class, function ($event) {
    Log::info('Agent started', [
        'agent' => $event->agentName,
        'correlation_id' => $event->getCorrelationId(),
        'trace_id' => $event->getTraceId(),
    ]);
});

// Monitor pipeline completion
Event::listen(PipelineCompleted::class, function ($event) {
    Metrics::timing('pipeline.duration', $event->getExecutionTime());
});
```

**Recommended Laravel 13 approach**

Use application events and explicit diagnostic context:

```php
Event::listen(OrderProcessingStarted::class, static function (OrderProcessingStarted $event): void {
    Context::add('correlation_id', $event->correlationId);
    Log::info('Order processing started', ['order_id' => $event->orderId]);
});

Event::listen(OrderProcessed::class, static function (OrderProcessed $event): void {
    Log::info('Order processing completed', ['elapsed_ms' => $event->elapsedMs]);
});
```

Define and dispatch `OrderProcessingStarted` in your application; `OrderProcessed` was illustrated earlier. Laravel queue lifecycle events are another option when observing queue attempts rather than domain completion. Metrics backends remain separate integrations.

### 28. Unit tests and dispatch fakes

**Sentinels README example**

```php
use Vampires\Sentinels\Facades\Sentinels;

class AgentTest extends TestCase
{
    public function test_agent_processes_correctly()
    {
        // Use test helpers
        $context = $this->createTestContext('input');
        $agent = new MyAgent();
        
        $result = $agent($context);
        
        $this->assertEquals('expected', $result->payload);
        $this->assertTrue($result->hasMetadata('processed'));
    }
    
    public function test_with_fake_agents()
    {
        Sentinels::fake();
        
        Sentinels::process('input', MyAgent::class);
        
        Sentinels::assertAgentRan(MyAgent::class);
    }
}
```

**Recommended Laravel 13 approach**

Test transformations directly and use a queue fake to assert dispatch separately:

```php
public function test_it_transforms_a_name(): void
{
    $result = (new \App\Pipelines\TrimName())(['name' => ' Ada '], fn ($data) => $data);
    $this->assertSame('Ada', $result['name']);
}

public function test_it_dispatches_order_processing(): void
{
    Queue::fake();
    $orderId = Order::factory()->create()->getKey();

    ProcessOrder::dispatch($orderId);

    Queue::assertPushed(ProcessOrder::class, fn ($job) => $job->orderId === $orderId);
}
```

The dispatch test belongs in Laravel's application TestCase, uses an `Order` model factory and test database, and assumes an application `ProcessOrder` queue job. A fake proves dispatch, not that the worker executed the job. Test its `handle()` behavior separately. The README's helper/fake APIs are retained as examples, not independently verified here.

### 29. Application configuration

**Sentinels README example**

```php
return [
    'default_mode' => PipelineMode::Sequential,
    
    'agents' => [
        'discovery' => [
            'enabled' => true,
            'paths' => ['app/Agents', 'app/Pipelines'],
        ],
        'execution' => [
            'memory_limit' => '128M',
        ],
    ],
    
    'observability' => [
        'events' => ['enabled' => true],
        'metrics' => ['enabled' => true],
        'tracing' => ['enabled' => env('SENTINELS_TRACING', false)],
    ],
    
    // Uses the application's default Laravel queue connection.
    'async' => [
        'queue' => 'sentinels',
    ],
];
```

**Recommended Laravel 13 approach**

Configure actual Laravel subsystems and keep workflow-specific values in an application file:

```php
// config/workflows.php
return [
    'orders' => [
        'queue' => env('ORDER_QUEUE', 'high-priority'),
        'job_timeout' => 30,
        'job_tries' => 3,
    ],
];

// When dispatching:
ProcessOrder::dispatch($orderId)->onQueue(config('workflows.orders.queue'));
```

A config key only has an effect when application code reads it. Set job properties from config in the job constructor if desired; an arbitrary `agents.execution.memory_limit` key does not enforce memory usage. Worker resource limits belong in worker/process configuration. The copied package snippet should not be assumed to make every shown key functional.

### 30. Generate and inspect application components

**Sentinels README example**

```bash
# Generate agents and pipelines
php artisan make:agent ProcessPaymentAgent
php artisan make:pipeline OrderFulfillmentPipeline

# List agents and view statistics
php artisan sentinels:list
php artisan sentinels:list --stats
```

**Recommended Laravel 13 approach**

Use Laravel's built-in generators for the kinds of classes actually needed:

```sh
php artisan make:class Pipelines/ValidateOrder
php artisan make:job ProcessOrder
php artisan make:event OrderProcessed
php artisan make:listener LogOrderProcessed --event=OrderProcessed
php artisan event:list
php artisan queue:work
```

Laravel has no native agent registry or `sentinels:list` equivalent. A pipeline is usually a short application service method, so it does not require its own generator.

### 31. Conditional processing with a final audit step

**Sentinels README example**

```php
// When you have workflows like this:
$result = Sentinels::pipeline()
    ->pipe(new ValidateDataAgent())           // Step 1: Validate
    ->branch(                                 // Step 2: Conditional logic
        fn($ctx) => $ctx->hasTag('premium'),
        $premiumPipeline,                     // Premium user flow
        $standardPipeline                     // Standard user flow  
    )
    ->pipe(new AuditLogAgent())              // Step 3: Always audit
    ->through($userRequest);
```

**Recommended Laravel 13 approach**

Build the list using ordinary PHP decisions:

```php
$steps = [ValidateData::class];
$steps = array_merge($steps, $isPremium ? $premiumSteps : $standardSteps);
$steps[] = AuditLog::class;

$result = Pipeline::send($userRequest)->through($steps)->thenReturn();
```

The audit step runs only if earlier steps reach it successfully. If every attempt must be audited, including failures, write the audit in `finally` around the entire pipeline. The copied README's “Always audit” comment is stronger than ordinary sequential pipeline semantics guarantee.

### 32. Rich context and correlation IDs

**Sentinels README example**

```php
// When you need to track data through complex flows:
$context = Context::create($orderData)
    ->withMetadata('user_id', $userId)
    ->withMetadata('request_id', $requestId)
    ->withTag('high-priority');

// Every agent can access and enrich this context
// Full traceability from start to finish
```

**Recommended Laravel 13 approach**

Keep domain metadata in an explicit payload and add correlation information to Laravel Context:

```php
Context::add('request_id', $requestId);
Context::add('user_id', $userId);

$input = [
    'order' => $orderData,
    'priority' => 'high',
    'requested_by' => $userId,
];

$result = Pipeline::send($input)->through($steps)->thenReturn();
```

Use a DTO instead of an array when stronger types help. A priority tag is domain data; it does not select a queue unless dispatch code explicitly uses it.

### 33. Recoverable failures and exponential backoff

**Sentinels README example**

```php
// When failures must be graceful and recoverable:
$agent = new ExternalAPIAgent();
$agent->setRetryPolicy(RetryPolicy::exponential(
    attempts: 3,
    baseDelay: 1000,
    maxDelay: 30000
));

// Automatic retries with backoff
// Context preserved across retries
// Detailed error reporting
```

**Recommended Laravel 13 approach**

Use native job retry settings for a durable external API operation:

```php
final class CallExternalApi implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 3;
    public int $timeout = 20;

    public function __construct(public int $requestId) {}

    public function backoff(): array
    {
        return [1, 2];
    }

    public function handle(ExternalApiClient $client): void
    {
        $client->sendRequest($this->requestId);
    }

    public function failed(?\Throwable $error): void
    {
        Log::error('External API request exhausted attempts', ['request_id' => $this->requestId]);
    }
}
```

`ExternalApiClient` is application code and should use an idempotency key where supported. A retry does not undo an earlier remote side effect. The job fails when `handle()` throws; returning an error value alone does not trigger Laravel retries.

### 34. Collaborative workflow tests

**Sentinels README example**

```php
// When multiple developers work on the same workflow:
class PaymentProcessingTest extends TestCase 
{
    public function test_payment_flow() 
    {
        Sentinels::fake();
        
        // Test just your agent in isolation
        $result = PaymentPipeline::process($orderContext);
        
        Sentinels::assertAgentRan(ValidateCardAgent::class);
        Sentinels::assertAgentRan(ChargePaymentAgent::class);
    }
}
```

**Recommended Laravel 13 approach**

Assert the orchestration contract with a bus fake:

```php
Bus::fake();

Bus::chain([
    new ValidateCard($orderId),
    new ChargePayment($orderId),
])->dispatch();

Bus::assertChained([
    ValidateCard::class,
    ChargePayment::class,
]);
```

In an application test, invoke the real controller/service that dispatches the chain rather than duplicating its definition in the assertion test. Test each job's business behavior independently. The fake does not execute the chain.

### 35. Expressive query and processing code

**Sentinels README example**

```php
// Laravel's expressive syntax
User::where('active', true)->get();

// Sentinels' equally expressive pipelines
Sentinels::pipeline()
    ->pipe(new ValidateUserAgent())
    ->pipe(new ProcessUserAgent())
    ->through($userData);
```

**Recommended Laravel 13 approach**

Keep Eloquent for queries and the native Pipeline facade for transformations:

```php
$users = User::where('active', true)->get();

$result = Pipeline::send($userData)
    ->through([ValidateUser::class, ProcessUser::class])
    ->thenReturn();
```

Readability comes from clear domain class names and contracts; a custom fluent facade is optional.

### 36. Generation and naming conventions

**Sentinels README example**

```php
// Generate an agent just like any Laravel component
php artisan make:agent ProcessOrderAgent

// Agents follow Laravel's naming conventions
app/Agents/ProcessOrderAgent.php     // Just like Controllers, Models, etc.
tests/Unit/ProcessOrderAgentTest.php // Standard test location
```

**Recommended Laravel 13 approach**

Choose an application convention using the native class generator:

```sh
php artisan make:class Pipelines/ProcessOrder
```

```text
app/Pipelines/ProcessOrder.php
tests/Unit/Pipelines/ProcessOrderTest.php
```

Laravel does not prescribe a `Pipelines` folder; the namespace and test structure are your convention. The original PHP fence includes shell commands and filenames for illustration.

### 37. Progress from direct calls to durable work

**Sentinels README example**

```php
// Level 1: Simple agent execution
Sentinels::process($data, new MyAgent());

// Level 2: Basic pipeline
Sentinels::pipeline()
    ->pipe(new Agent1())
    ->pipe(new Agent2())
    ->through($data);

// Level 3: Advanced features when you need them
config(['sentinels.async.queue' => 'priority']);

Sentinels::pipeline()
    ->mode('parallel')
    ->async()
    ->pipe(new ComplexAgent())
    ->through($data);
```

**Recommended Laravel 13 approach**

Add complexity only when the execution requirement changes:

```php
// One operation now:
$result = app(ProcessDataAction::class)->handle($data);

// Several dependent transformations now:
$result = Pipeline::send($data)
    ->through([StepOne::class, StepTwo::class])
    ->thenReturn();

// Independent tracked background jobs:
$batch = Bus::batch([
    new ProcessPart($workflowId, 1),
    new ProcessPart($workflowId, 2),
])->onQueue('high-priority')->dispatch();
```

Changing to queues is an explicit boundary: serialize input, persist output, define retry/idempotency behavior, and return a workflow or batch ID. It is not just a fluent mode change.

### 38. End-to-end order workflow testing

**Sentinels README example**

```php
class OrderPipelineTest extends TestCase
{
    public function test_order_processing()
    {
        // Familiar Laravel testing patterns
        Sentinels::fake();
        
        // Process with confidence
        $result = OrderPipeline::process($order);
        
        // Laravel-style assertions
        Sentinels::assertAgentRan(ValidateOrderAgent::class);
        $this->assertTrue($result->isSuccessful());
    }
}
```

**Recommended Laravel 13 approach**

Use real application behavior for the outcome test and fake external boundaries:

```php
Http::fake([
    'payments.example.test/*' => Http::response(['approved' => true], 200),
]);

$order = Order::factory()->create(['status' => 'pending']);
app(ProcessOrderAction::class)->handle($order->getKey());

$this->assertDatabaseHas('orders', [
    'id' => $order->getKey(),
    'status' => 'processed',
]);
```

The endpoint and response match your application's payment adapter. For queued workflows, test dispatch with Bus/Queue fakes separately from a worker integration test. Avoid faking the very processing whose result the test claims to prove.

### 39. Eloquent relations and item workflows

**Sentinels README example**

```php
// Laravel: Define model relationships
class Order extends Model {
    public function items() {
        return $this->hasMany(OrderItem::class);
    }
}

// Sentinels: Define workflow relationships
class OrderPipeline {
    public static function build() {
        return Pipeline::create()
            ->pipe(new ValidateOrderAgent())
            ->hasMany(ItemPipeline::class)  // Process each item
            ->pipe(new FinalizeOrderAgent());
    }
}
```

**Recommended Laravel 13 approach**

Keep Eloquent relationships for data access, then process items explicitly:

```php
$processed = Pipeline::send($order)
    ->through([ValidateOrder::class])
    ->thenReturn();

$processed->items()->each(function (OrderItem $item): void {
    app(ProcessOrderItemAction::class)->handle($item->getKey());
});

app(FinalizeOrderAction::class)->handle($processed->getKey());
```

This version is synchronous, so finalization follows completed item calls. For durable parallel item work, dispatch item jobs in a batch and finalize from the success callback after checking persisted domain results. Eloquent's `hasMany()` defines a database relationship; it is not a workflow primitive. The copied `Pipeline::create()->hasMany()` workflow API is illustrative and is not established as supported.

### 40. Extending an application with reusable classes

**Sentinels README example**

```php
// Vision: Future community agents could work like Laravel packages
// composer require community/sentinels-ai-agents
// composer require community/sentinels-payment-agents

// The architecture supports this pattern:
Sentinels::pipeline()
    ->pipe(new YourCustomAgent())
    ->pipe(new AnotherCustomAgent())
    ->through($data);
```

**Recommended Laravel 13 approach**

Reuse application-owned classes through the container and an explicit contract:

```php
$result = Pipeline::send($data)
    ->through([NormalizeInput::class, ApplyBusinessRules::class])
    ->thenReturn();
```

When several applications genuinely share behavior, extract those classes into a normal Laravel-compatible package with a service provider and clear contracts. There is no need to invent a community package name or install an orchestration framework first. The README marks its ecosystem example as a future vision; it is not an available package recommendation.

## Choosing what to keep

Native Laravel covers the execution primitives, but a migration still needs application decisions about payload types, error accumulation, routing policy, observability, result storage, and compensation. Keep a small context DTO or reusable domain actions when they provide value. Replace orchestration incrementally around tested workflow boundaries rather than assuming a line-for-line API substitution.

The examples above explain recommended designs. They have not been run as a complete application, and the domain classes, database schema, external adapters, and application events must be supplied by the consuming project.
