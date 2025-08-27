# Sentinels: Powerful, Flexible, and Simple Agent-Based Task Execution for Laravel

[![Latest Version](https://img.shields.io/packagist/v/vampires/sentinels.svg?style=flat-square)](https://packagist.org/packages/vampires/sentinels)
[![Total Downloads](https://img.shields.io/packagist/dt/vampires/sentinels.svg?style=flat-square)](https://packagist.org/packages/vampires/sentinels)
[![License](https://img.shields.io/packagist/l/vampires/sentinels.svg?style=flat-square)](https://packagist.org/packages/vampires/sentinels)

Turn messy, monolithic Laravel services into clean, testable, observable pipelines. Sentinels transforms your 300-line service classes into focused agents that you can compose, test, and debug with surgical precision. When something breaks at 3 AM, you'll know exactly which step failed and why.

## ⚡ See the Difference Immediately

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

## 🎯 Why Laravel Developers Need Sentinels

**The Problem:** Your services are becoming monsters. A simple "process order" method somehow became 300 lines of tangled logic. Testing is a nightmare. Debugging production issues feels like archaeology. Sound familiar?

**The Solution:** Break complex workflows into focused agents that do one thing brilliantly. Get observability, error recovery, and conditional logic for free.

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

### What Makes It Different
- **Debug Like a Detective**: Every request gets a correlation ID that traces through your entire pipeline
- **Test with Confidence**: Each agent is isolated, focused, and easy to mock
- **Scale Intelligently**: Route tasks based on content, not just linear execution
- **Stay in Laravel**: Built specifically for Laravel's patterns and ecosystem

## 🚀 Get Running in 2 Minutes

### Installation
```bash
composer require vampires/sentinels
php artisan vendor:publish --tag=sentinels-config
```

### Your First Agent
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

### Use It Immediately
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

**Ready for more?** Let's dive into the problems this solves...

## 🔧 The 3 Core Problems Sentinels Eliminates

### 1. 😰 "The Service From Hell" Problem
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

### 2. 🕵️‍♀️ "The 3 AM Debugging Mystery" Problem
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

### 3. 🔄 "The Copy-Paste Workflow" Problem
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

These are the core pain points that drive developers to Sentinels. But what makes Sentinels different from other solutions?

## 🚀 Why Sentinels Will Transform Your Workflow

- **🕵️‍♀️ Debug Like a Detective**: Every request gets a correlation ID that follows it through your entire pipeline. When something breaks, you'll know exactly which agent failed, for which user, and why. No more mysterious exceptions!

- **🎯 Write Agents, Not Services**: Each agent does ONE thing brilliantly. 50 lines instead of 500. Test in isolation. Mock with confidence. Your code reviews will thank you.

- **🚦 Smart Routing That Actually Works**: Route based on content, not just sequence. Premium users get the VIP pipeline. Failed payments get the retry flow. Same codebase, intelligent decisions.

- **⚡ Async When You Need It**: Start synchronous, scale to queues without changing code. Same agents, different execution. Laravel queues work seamlessly.

- **📊 Production Insights for Free**: Built-in metrics, performance tracking, and error correlation. See which agents are slow, which fail most, and optimize based on real data.

- **🛠️ Laravel Developer Experience**: `make:agent`, `make:pipeline`, built-in testing helpers, facade support. Feels native because it is native.

- **🔀 Conditional Logic That Makes Sense**: Branch, merge, loop, and compose pipelines. Handle complex business logic without drowning in if-statements.

- **⚙️ Zero-Configuration Laravel Integration**: Works with your service container, events, middleware, and queues. Add it to existing projects in minutes.

Sounds interesting? Here are the types of problems Sentinels is designed to solve:

## 🎯 Real-World Use Cases

Here are the most compelling ways you could use Sentinels to transform your complex workflows:

### 🤖 AI/LLM Pipeline Processing
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

### 📧 Multi-Channel Notification System
```php
// Route notifications based on user preferences and message urgency
$pipeline = Sentinels::pipeline()
    ->pipe(new UserPreferenceAgent())         // Load user notification settings
    ->pipe(new UrgencyAnalysisAgent())        // Determine message priority
    ->pipe(new ChannelRoutingAgent())         // Route to email/SMS/push/slack
    ->pipe(new DeliveryTrackingAgent())       // Track delivery status
    ->pipe(new FailureRetryAgent());          // Handle delivery failures

$pipeline->through($notificationRequest);
```

### 📊 ETL Data Processing
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

### 🛒 E-commerce Order Processing
```php
// Handle complex order fulfillment with multiple validation steps
class OrderFulfillmentPipeline
{
    public static function build(): PipelineContract
    {
        return Sentinels::pipeline()
            ->pipe(new InventoryCheckAgent())
            ->pipe(new PaymentProcessingAgent())
            ->pipe(new FraudDetectionAgent())
            ->branch(
                fn($ctx) => $ctx->getMetadata('is_digital_product'),
                // Digital products pipeline
                Pipeline::create()
                    ->pipe(new LicenseGenerationAgent())
                    ->pipe(new DigitalDeliveryAgent()),
                // Physical products pipeline
                Pipeline::create()
                    ->pipe(new ShippingCalculationAgent())
                    ->pipe(new WarehouseAllocationAgent())
                    ->pipe(new PackingSlipAgent())
            )
            ->pipe(new CustomerNotificationAgent())
            ->pipe(new AnalyticsTrackingAgent());
    }
}
```

**Why These Patterns Work So Well:**
- **🔍 Pinpoint Failures**: When step 3 of 8 fails, you know immediately which agent and why
- **🧪 Test with Confidence**: Mock external APIs, test edge cases, verify each step independently  
- **🔀 Handle Complexity**: Premium customers? VIP pipeline. Failed payment? Retry flow. Same codebase.
- **⚡ Scale Intelligently**: Start sync, move to queues, add caching—without changing your agents

Ready to see the potential impact on your team? Here's the strategic value Sentinels is designed to deliver:

## 💡 Strategic Value for Teams

### 🎯 For Development Teams

#### **Improved Code Organization**
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

#### **Parallel Development**
- **Multiple developers** can work on different agents simultaneously without conflicts
- **Clear interfaces** between agents prevent integration issues
- **Independent testing** allows for confident refactoring

#### **Knowledge Transfer**
- New team members can **understand workflows visually** through pipeline definitions
- Each agent is **self-documenting** with its name and single responsibility
- **Correlation IDs** make it easy to trace issues in production

### 📈 For Business Stakeholders

#### **Measurable Performance**
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

#### **Risk Mitigation**
- **Granular error handling** prevents total system failures
- **Retry policies** reduce transient failure impact
- **Audit trails** provide compliance documentation

#### **Business Agility**
- **Add new steps** without rewriting entire workflows
- **A/B test** different pipeline configurations
- **Quick iterations** on business logic changes

### 🔧 For DevOps & Operations

#### **Production Observability**
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

#### **Deployment Safety**
- **Feature flags** can control individual agent behavior
- **Canary deployments** for specific pipeline branches
- **Rollback** is as simple as reverting agent logic

#### **Resource Optimization**
```php
// Different execution strategies for different needs
Sentinels::pipeline()
    ->mode('parallel')     // Use multiple CPU cores
    ->queue('high-priority') // Dedicated queue resources
    ->timeout(30)          // Prevent resource hogging
    ->pipe($agents)
    ->through($data);
```

### 🏆 For Technical Leads

#### **Enforced Best Practices**
- **Single Responsibility** enforced by agent design
- **Dependency Injection** through Laravel container
- **Interface Segregation** with focused contracts
- **Open/Closed Principle** - extend by adding agents

#### **Quality Assurance**
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

#### **Architecture Evolution**
- Start simple with **synchronous pipelines**
- Scale to **async processing** without code changes  
- Evolve to **distributed systems** using same patterns

### 📊 Expected Benefits

The agent-based architecture pattern is designed to deliver:
- **Faster debugging** through correlation tracing and isolated failures
- **Accelerated development** with focused, testable components
- **Higher test coverage** achieved through isolated units
- **Fewer production incidents** from better error isolation and recovery
- **Easier onboarding** with clear, single-purpose components

## 🛠 Installation

Install Sentinels via Composer:

```bash
composer require vampires/sentinels
```

Publish the configuration file (optional):

```bash
php artisan vendor:publish --tag=sentinels-config
```


## 🧠 Core Concepts

### Context-First Architecture

Everything in Sentinels revolves around the **Context** object - an immutable container that carries your data and metadata through the entire pipeline:

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

### Agent Lifecycle

Agents have a rich lifecycle with hooks for custom behavior:

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

### Pipeline Modes

Sentinels supports multiple execution modes:

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

## 📚 Advanced Usage

### Dynamic Routing

Route contexts to different agents based on content:

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

### Error Handling & Retries

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

### Pipeline Events & Observability

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

### Testing

Sentinels provides comprehensive testing utilities:

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

## 🎚️ Configuration

Configure Sentinels in `config/sentinels.php`:

```php
return [
    'default_mode' => PipelineMode::Sequential,
    
    'agents' => [
        'discovery' => [
            'enabled' => true,
            'paths' => ['app/Agents', 'app/Pipelines'],
        ],
        'execution' => [
            'timeout' => 30,
            'memory_limit' => '128M',
        ],
    ],
    
    'observability' => [
        'events' => ['enabled' => true],
        'metrics' => ['enabled' => true],
        'tracing' => ['enabled' => env('SENTINELS_TRACING', false)],
    ],
    
    'queue' => [
        'connection' => 'default',
        'queue' => 'sentinels',
    ],
];
```

## 🎨 Artisan Commands

Sentinels provides helpful Artisan commands:

```bash
# Generate agents and pipelines
php artisan make:agent ProcessPaymentAgent
php artisan make:pipeline OrderFulfillmentPipeline

# List agents and view statistics
php artisan sentinels:list
php artisan sentinels:list --stats
```

## 🔄 When to Use Sentinels vs Alternatives

### Feature Comparison Matrix

| Feature | Laravel Pipeline | Job Chains | Events/Listeners | **Sentinels** |
|---------|------------------|------------|------------------|---------------|
| **Purpose** | Data transformations | Async task chains | Event-driven actions | **Agent orchestration** |
| **Dynamic Routing** | ❌ | ❌ | ❌ | **✅ Content-based** |
| **Rich Context** | Basic payload | Job properties | Event data | **✅ Immutable with metadata** |
| **Observability** | None | Job status only | Scattered logs | **✅ Comprehensive tracing** |
| **Error Recovery** | Exception bubbling | Retry attempts | Manual handling | **✅ Policies & strategies** |
| **Conditional Logic** | ❌ | Linear only | Event-based | **✅ Branch & merge** |
| **Testing** | Manual mocks | Queue faking | Event faking | **✅ Built-in assertions** |
| **Debugging** | Stack traces | Queue logs | Event logs | **✅ Correlation IDs** |
| **Performance** | Synchronous only | Queue overhead | Event overhead | **✅ Sync/async unified** |
| **Complexity** | Simple | Medium | High (scattered) | **Medium (structured)** |

### Scenario-Based Decision Guide

| **Scenario** | **Use This** | **Why** | **Example** |
|-------------|-------------|---------|-------------|
| **Simple data transformation** | Laravel Pipeline | Minimal overhead, built-in | `$users->pipe(new FilterActive())->pipe(new FormatNames())` |
| **Background job processing** | Job Chains | Laravel native, good for simple async | `ProcessPayment::chain([SendEmail::class, UpdateStats::class])` |
| **System-wide event reactions** | Events/Listeners | Loose coupling, multiple handlers | `UserRegistered` → Email, Analytics, CRM sync |
| **Multi-step business workflows** | **Sentinels** | **Observability, error recovery** | **Order processing, content workflows** |
| **AI/ML processing pipelines** | **Sentinels** | **Context preservation, branching** | **LLM chains, image processing** |
| **Complex ETL operations** | **Sentinels** | **Conditional routing, monitoring** | **Data validation, enrichment, storage** |
| **API orchestration** | **Sentinels** | **Retry policies, correlation** | **Third-party integrations, webhooks** |
| **Content processing workflows** | **Sentinels** | **Dynamic routing, reusability** | **CMS publishing, media processing** |

### Choose Sentinels When You Need:

#### ✅ **Structured Multi-Step Processing**
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

#### ✅ **Rich Context & Correlation**
```php
// When you need to track data through complex flows:
$context = Context::create($orderData)
    ->withMetadata('user_id', $userId)
    ->withMetadata('request_id', $requestId)
    ->withTag('high-priority');

// Every agent can access and enrich this context
// Full traceability from start to finish
```

#### ✅ **Production-Grade Error Handling**
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

#### ✅ **Team Collaboration & Testing**
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

### **Don't Use Sentinels When:**

❌ **Simple, one-step transformations** → Use Laravel Pipeline  
❌ **Fire-and-forget background jobs** → Use Job Chains  
❌ **System events with multiple listeners** → Use Events/Listeners  
❌ **Rapid prototyping** → Use simple Service classes  
❌ **Performance is critical over maintainability** → Use optimized custom solutions

## 🌟 Laravel Philosophy Alignment

Sentinels embraces Laravel's core philosophy while extending its capabilities for complex workflows:

### **"Developer Happiness First"**
Just like Laravel makes common tasks enjoyable, Sentinels makes complex workflows manageable:
```php
// Laravel's expressive syntax
User::where('active', true)->get();

// Sentinels' equally expressive pipelines
Sentinels::pipeline()
    ->pipe(new ValidateUserAgent())
    ->pipe(new ProcessUserAgent())
    ->through($userData);
```

### **"Convention Over Configuration"**
Sentinels follows Laravel's patterns, so everything feels familiar:
```php
// Generate an agent just like any Laravel component
php artisan make:agent ProcessOrderAgent

// Agents follow Laravel's naming conventions
app/Agents/ProcessOrderAgent.php     // Just like Controllers, Models, etc.
tests/Unit/ProcessOrderAgentTest.php // Standard test location
```

### **"Batteries Included"**
Like Laravel provides everything you need for web apps, Sentinels provides everything for workflows:
- **Artisan commands** for code generation
- **Testing utilities** that feel like Laravel's
- **Event system** integration out of the box  
- **Queue support** with zero configuration

### **"Progressive Disclosure"**
Start simple, add complexity only when needed:
```php
// Level 1: Simple agent execution
Sentinels::process($data, new MyAgent());

// Level 2: Basic pipeline
Sentinels::pipeline()
    ->pipe(new Agent1())
    ->pipe(new Agent2())
    ->through($data);

// Level 3: Advanced features when you need them
Sentinels::pipeline()
    ->mode('parallel')
    ->withRetries(3)
    ->onQueue('priority')
    ->pipe(new ComplexAgent())
    ->branch($condition, $truePipeline, $falsePipeline)
    ->through($data);
```

### **"Testability as a First-Class Citizen"**
Testing Sentinels feels just like testing Laravel:
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

### **"Eloquent Relationships for Workflows"**
Just as Eloquent makes database relationships intuitive, Sentinels makes workflow relationships clear:
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

### **"The Laravel Way" Extended**
Sentinels doesn't replace Laravel patterns—it enhances them:

| Laravel Pattern | Sentinels Enhancement |
|----------------|----------------------|
| Service Providers | Agent discovery and registration |
| Facades | `Sentinels::` facade for clean syntax |
| Middleware | Agent middleware for cross-cutting concerns |
| Events | Pipeline events for observability |
| Jobs | Async agent execution |
| Validation | Context validation in agents |
| Container | Dependency injection in agents |

### **"Community-Driven Innovation"**
Like Laravel, Sentinels is designed to evolve with community needs:
- **Open architecture** for custom agent types
- **Extensible routing** strategies
- **Pluggable metrics** providers
- **Future community agent packages** support

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

**Sentinels is Laravel for workflows** - bringing the same joy, productivity, and elegance to complex processing that Laravel brings to web development.

## 🤝 Contributing

We welcome contributions! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## 🔒 Security

If you discover any security-related issues, please email security@example.com instead of using the issue tracker.

## 📄 License

Sentinels is open-sourced software licensed under the [MIT license](LICENSE).

---

**Built with ❤️ for the Laravel community**

*Sentinels v0.1.0 - Agent-first task orchestration for Laravel*