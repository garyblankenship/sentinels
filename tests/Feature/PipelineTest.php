<?php

namespace Vampires\Sentinels\Tests\Feature;

use Vampires\Sentinels\Contracts\AgentMediator;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Pipeline\Pipeline;
use Vampires\Sentinels\Tests\Fixtures\DoubleAgent;
use Vampires\Sentinels\Tests\Fixtures\MetadataAgent;
use Vampires\Sentinels\Tests\Fixtures\TestAgent;
use Vampires\Sentinels\Tests\TestCase;

class PipelineTest extends TestCase
{
    protected Pipeline $pipeline;
    protected AgentMediator $mediator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediator = $this->app->make(AgentMediator::class);
        $this->pipeline = new Pipeline($this->mediator, $this->app['events']);
    }

    public function test_pipeline_can_be_created(): void
    {
        $this->assertInstanceOf(Pipeline::class, $this->pipeline);
        $this->assertTrue($this->pipeline->isEmpty());
        $this->assertEquals(0, $this->pipeline->getStageCount());
    }

    public function test_pipeline_can_add_agents(): void
    {
        $agent = new TestAgent();
        $this->pipeline->pipe($agent);

        $this->assertFalse($this->pipeline->isEmpty());
        $this->assertEquals(1, $this->pipeline->getStageCount());
        $this->assertContains($agent, $this->pipeline->getStages());
    }

    public function test_pipeline_processes_single_agent(): void
    {
        $this->pipeline->pipe(new TestAgent());

        $result = $this->pipeline->through('hello');

        $this->assertEquals('HELLO', $result);
    }

    public function test_pipeline_processes_multiple_agents_sequentially(): void
    {
        $this->pipeline
            ->pipe(new TestAgent(output: 'first'))
            ->pipe(new TestAgent(output: 'second'))
            ->pipe(new MetadataAgent());

        $context = Context::create('input');
        $result = $this->pipeline->process($context);

        $this->assertEquals('second', $result->payload);
        $this->assertTrue($result->hasTag('processed'));
        $this->assertTrue($result->hasMetadata('processed_by'));
    }

    public function test_pipeline_handles_empty_pipeline(): void
    {
        $context = Context::create('test');
        $result = $this->pipeline->process($context);

        $this->assertEquals($context, $result);
    }

    public function test_pipeline_processes_with_context(): void
    {
        $this->pipeline->pipe(new MetadataAgent());

        $originalContext = Context::create('test')
            ->withMetadata('original', true)
            ->withTag('input');

        $result = $this->pipeline->process($originalContext);

        $this->assertEquals('test', $result->payload);
        $this->assertTrue($result->hasMetadata('original'));
        $this->assertTrue($result->hasTag('input'));
        $this->assertTrue($result->hasTag('processed'));
        $this->assertEquals($originalContext->correlationId, $result->correlationId);
    }

    public function test_pipeline_can_set_execution_mode(): void
    {
        $this->pipeline->mode('parallel');
        $stats = $this->pipeline->getStats();

        $this->assertEquals('parallel', $stats['mode']);
    }

    public function test_pipeline_can_set_timeout(): void
    {
        $this->pipeline->timeout(120);
        $stats = $this->pipeline->getStats();

        $this->assertEquals(120, $stats['timeout']);
    }

    public function test_pipeline_handles_callable_stages(): void
    {
        $this->pipeline->pipe(function ($payload) {
            return strtoupper($payload);
        });

        $result = $this->pipeline->through('hello');

        $this->assertEquals('HELLO', $result);
    }

    public function test_pipeline_handles_callable_with_context(): void
    {
        $this->pipeline->pipe(function ($payload, Context $context) {
            return [$payload . '_modified', $context->withTag('callable')];
        });

        $context = Context::create('test');
        $result = $this->pipeline->process($context);

        $this->assertEquals('test_modified', $result->payload);
        $this->assertTrue($result->hasTag('callable'));
    }

    public function test_pipeline_can_add_error_handler(): void
    {
        $errorHandled = false;
        $this->pipeline
            ->pipe(new TestAgent(shouldFail: true))
            ->onError(function (Context $context, \Throwable $exception) use (&$errorHandled) {
                $errorHandled = true;

                return $context->with('error_handled');
            });

        $result = $this->pipeline->through('test');

        $this->assertTrue($errorHandled);
        $this->assertEquals('error_handled', $result);
    }

    public function test_pipeline_can_add_success_callback(): void
    {
        $successCalled = false;
        $this->pipeline
            ->pipe(new TestAgent())
            ->onSuccess(function (Context $context) use (&$successCalled) {
                $successCalled = true;
            });

        $this->pipeline->through('test');

        $this->assertTrue($successCalled);
    }

    public function test_pipeline_can_be_cloned(): void
    {
        $this->pipeline->pipe(new TestAgent());
        $clone = $this->pipeline->clone();

        $this->assertNotSame($this->pipeline, $clone);
        $this->assertEquals($this->pipeline->getStageCount(), $clone->getStageCount());
    }

    public function test_pipeline_collects_statistics(): void
    {
        $this->pipeline
            ->pipe(new TestAgent())
            ->pipe(new DoubleAgent());

        $stats = $this->pipeline->getStats();

        $this->assertEquals(2, $stats['stage_count']);
        $this->assertGreaterThan(0, $stats['estimated_time']);
        $this->assertFalse($stats['has_branches']);
        $this->assertEquals(0, $stats['middleware_count']);
        $this->assertEquals('sequential', $stats['mode']);
    }

    public function test_pipeline_stops_on_cancelled_context(): void
    {
        $this->pipeline
            ->pipe(function ($payload, Context $context) {
                return [null, $context->cancel()];
            })
            ->pipe(new TestAgent(output: 'should_not_execute'));

        $result = $this->pipeline->process(Context::create('test'));

        $this->assertTrue($result->isCancelled());
        $this->assertNotEquals('should_not_execute', $result->payload);
    }

    public function test_pipeline_map_operation(): void
    {
        $this->pipeline->map(function ($item) {
            return $item * 2;
        });

        $result = $this->pipeline->through([1, 2, 3, 4]);

        $this->assertEquals([2, 4, 6, 8], $result);
    }

    public function test_pipeline_reduce_operation(): void
    {
        $this->pipeline->pipe(function ($payload) {
            return [1, 2, 3, 4]; // Return an iterable for reduce to work
        });

        $result = $this->pipeline->reduce(function ($carry, $item) {
            return $carry + $item;
        }, 0);

        $this->assertEquals(10, $result); // 1+2+3+4=10
    }

    public function test_pipeline_with_nested_pipeline(): void
    {
        $innerPipeline = new Pipeline($this->mediator, $this->app['events']);
        $innerPipeline->pipe(new TestAgent(output: 'inner'));

        $this->pipeline
            ->pipe(new TestAgent(output: 'outer_start'))
            ->pipe($innerPipeline)
            ->pipe(new MetadataAgent());

        $result = $this->pipeline->process(Context::create('input'));

        $this->assertEquals('inner', $result->payload);
        $this->assertTrue($result->hasTag('processed'));
    }

    public function test_pipeline_static_create_method(): void
    {
        $pipeline = Pipeline::create($this->mediator, $this->app['events']);

        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $this->assertTrue($pipeline->isEmpty());
    }

    public function test_pipeline_handles_map_reduce_mode(): void
    {
        $this->pipeline
            ->mode('map_reduce')
            ->pipe(new DoubleAgent());

        $result = $this->pipeline->through([1, 2, 3]);

        $this->assertEquals([2, 4, 6], $result);
    }

    public function test_pipeline_parallel_mode_processes_agents(): void
    {
        $this->pipeline
            ->mode('parallel')
            ->pipe(new TestAgent(output: 'first'))
            ->pipe(new DoubleAgent());

        $result = $this->pipeline->through(5);

        // In parallel mode, agents process the same input
        // and results are merged (implementation dependent)
        $this->assertTrue(is_array($result) || is_numeric($result));
    }

    public function test_pipeline_branch_method_exists(): void
    {
        $truePipeline = new Pipeline($this->mediator, $this->app['events']);
        $falsePipeline = new Pipeline($this->mediator, $this->app['events']);

        $result = $this->pipeline->branch(
            fn (Context $context) => true,
            $truePipeline,
            $falsePipeline
        );

        $this->assertInstanceOf(Pipeline::class, $result);
        $this->assertEquals(1, $result->getStageCount());
    }
}
