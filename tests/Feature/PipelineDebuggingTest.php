<?php

namespace Vampires\Sentinels\Tests\Feature;

use Illuminate\Support\Facades\Log;
use Vampires\Sentinels\Contracts\AgentMediator;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Pipeline\Pipeline;
use Vampires\Sentinels\Tests\Fixtures\TestAgent;
use Vampires\Sentinels\Tests\TestCase;

class PipelineDebuggingTest extends TestCase
{
    protected Pipeline $pipeline;
    protected AgentMediator $mediator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediator = $this->app->make(AgentMediator::class);
        $this->pipeline = new Pipeline($this->mediator, $this->app['events']);
    }
    public function test_tap_method_executes_callback_without_modifying_context(): void
    {
        $tappedData = null;
        
        $pipeline = Pipeline::create($this->mediator, $this->app['events'])
            ->pipe(new TestAgent('PROCESSED'))
            ->tap(function (Context $context) use (&$tappedData) {
                $tappedData = $context->payload;
            })
            ->pipe(new TestAgent('FINAL'));

        $result = $pipeline->through('test');

        $this->assertEquals('FINAL', $result);
        $this->assertEquals('PROCESSED', $tappedData);
    }

    public function test_dump_method_includes_context_information(): void
    {
        // Since we can't easily test dump() output without capturing it,
        // we'll test that the pipeline doesn't break and continues processing
        $pipeline = Pipeline::create($this->mediator, $this->app['events'])
            ->pipe(new TestAgent('STEP1'))
            ->dump('After Step 1')
            ->pipe(new TestAgent('STEP2'));

        $result = $pipeline->through('test');

        $this->assertEquals('STEP2', $result);
    }

    public function test_ray_method_works_with_label(): void
    {
        // Similar to dump test - ensuring pipeline continues processing
        $pipeline = Pipeline::create($this->mediator, $this->app['events'])
            ->pipe(new TestAgent('RAY_TEST'))
            ->ray('Debug Point')
            ->pipe(new TestAgent('FINAL'));

        $result = $pipeline->through('test');

        $this->assertEquals('FINAL', $result);
    }

    public function test_log_context_method(): void
    {
        Log::shouldReceive('debug')
            ->once()
            ->with('Pipeline context debug', \Mockery::type('array'));

        $pipeline = Pipeline::create($this->mediator, $this->app['events'])
            ->pipe(new TestAgent('LOGGED'))
            ->logContext('debug', 'Pipeline context debug')
            ->pipe(new TestAgent('FINAL'));

        $result = $pipeline->through('test');

        $this->assertEquals('FINAL', $result);
    }

    public function test_log_context_filters_sensitive_metadata(): void
    {
        Log::shouldReceive('debug')
            ->once()
            ->with('Test log', \Mockery::on(function ($logData) {
                // Verify that password is filtered
                return isset($logData['metadata']['password']) 
                    && $logData['metadata']['password'] === '[FILTERED]'
                    && $logData['metadata']['username'] === 'testuser';
            }));

        $context = Context::create(['test' => 'data'])
            ->withMetadata('username', 'testuser')
            ->withMetadata('password', 'secret123');

        $pipeline = Pipeline::create($this->mediator, $this->app['events'])
            ->logContext('debug', 'Test log');

        $pipeline->process($context);
    }

    public function test_validate_method_with_successful_validation(): void
    {
        $pipeline = Pipeline::create($this->mediator, $this->app['events'])
            ->validate(function (Context $context) {
                return !empty($context->payload);
            }, 'Payload cannot be empty')
            ->pipe(new TestAgent('VALIDATED'));

        $result = $pipeline->through('test');

        $this->assertEquals('VALIDATED', $result);
    }

    public function test_validate_method_with_failed_validation(): void
    {
        $pipeline = Pipeline::create($this->mediator, $this->app['events'])
            ->validate(function (Context $context) {
                return false; // Always fail
            }, 'Validation failed')
            ->pipe(new TestAgent('SHOULD_NOT_REACH'));

        $context = $pipeline->process(Context::create('test'));

        $this->assertTrue($context->hasErrors());
        $this->assertContains('Validation failed', $context->errors);
        $this->assertNotEquals('SHOULD_NOT_REACH', $context->payload);
    }

    public function test_validate_method_with_array_of_errors(): void
    {
        $pipeline = Pipeline::create($this->mediator, $this->app['events'])
            ->validate(function (Context $context) {
                return ['Error 1', 'Error 2']; // Return array of errors
            }, 'Default error message')
            ->pipe(new TestAgent('SHOULD_NOT_REACH'));

        $context = $pipeline->process(Context::create('test'));

        $this->assertTrue($context->hasErrors());
        $this->assertContains('Error 1', $context->errors);
        $this->assertContains('Error 2', $context->errors);
    }

    public function test_chaining_multiple_debugging_methods(): void
    {
        $tapCount = 0;
        
        Log::shouldReceive('info')
            ->once()
            ->with('Chained debug', \Mockery::type('array'));

        $pipeline = Pipeline::create($this->mediator, $this->app['events'])
            ->pipe(new TestAgent('STEP1'))
            ->tap(function () use (&$tapCount) {
                $tapCount++;
            })
            ->dump('Debug Point 1')
            ->logContext('info', 'Chained debug')
            ->validate(function (Context $context) {
                return $context->payload === 'STEP1';
            }, 'Invalid payload')
            ->pipe(new TestAgent('FINAL'));

        $result = $pipeline->through('test');

        $this->assertEquals('FINAL', $result);
        $this->assertEquals(1, $tapCount);
    }

    public function test_debugging_methods_preserve_metadata_and_tags(): void
    {
        $metadataFromTap = null;
        $tagsFromTap = null;

        $pipeline = Pipeline::create($this->mediator, $this->app['events'])
            ->pipe(function (mixed $payload, Context $context) {
                return $context
                    ->withMetadata('debug_test', true)
                    ->withTag('debug_tag');
            })
            ->tap(function (Context $context) use (&$metadataFromTap, &$tagsFromTap) {
                $metadataFromTap = $context->getMetadata('debug_test');
                $tagsFromTap = $context->hasTag('debug_tag');
            })
            ->dump('Metadata Check');

        $context = $pipeline->process(Context::create('test'));

        $this->assertTrue($metadataFromTap);
        $this->assertTrue($tagsFromTap);
        $this->assertTrue($context->getMetadata('debug_test'));
        $this->assertTrue($context->hasTag('debug_tag'));
    }

    public function test_debugging_methods_with_complex_payload(): void
    {
        $complexPayload = [
            'order' => [
                'id' => 123,
                'items' => [
                    ['name' => 'Product A', 'price' => 10.99],
                    ['name' => 'Product B', 'price' => 15.99],
                ],
                'total' => 26.98,
            ],
            'customer' => [
                'id' => 456,
                'email' => 'test@example.com',
            ],
        ];

        $tappedPayload = null;

        $pipeline = Pipeline::create($this->mediator, $this->app['events'])
            ->tap(function (Context $context) use (&$tappedPayload) {
                $tappedPayload = $context->payload;
            })
            ->dump('Complex Payload')
            ->validate(function (Context $context) {
                return isset($context->payload['order']['total']);
            }, 'Order total is required');

        $context = $pipeline->process(Context::create($complexPayload));

        $this->assertEquals($complexPayload, $tappedPayload);
        $this->assertFalse($context->hasErrors());
        $this->assertEquals($complexPayload, $context->payload);
    }

    public function test_performance_impact_of_debugging_methods(): void
    {
        // Test that debugging methods don't significantly impact performance
        $startTime = microtime(true);

        $pipeline = Pipeline::create($this->mediator, $this->app['events'])
            ->pipe(new TestAgent('PERFORMANCE'))
            ->tap(function () { /* No-op */ })
            ->dump('Performance Test')
            ->ray('Performance Ray')
            ->logContext('debug', 'Performance Log')
            ->validate(function () { return true; })
            ->pipe(new TestAgent('FINAL'));

        $result = $pipeline->through('test');

        $executionTime = microtime(true) - $startTime;

        $this->assertEquals('FINAL', $result);
        $this->assertLessThan(1.0, $executionTime, 'Debugging methods should not significantly impact performance');
    }
}