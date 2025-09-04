<?php

namespace Vampires\Sentinels\Tests\Unit\Agents;

use Illuminate\Pipeline\Pipeline as LaravelPipeline;
use PHPUnit\Framework\TestCase;
use Vampires\Sentinels\Agents\LaravelPipelineAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Facades\Sentinels;

class LaravelPipelineAgentTest extends TestCase
{
    public function test_can_create_agent_with_pipes(): void
    {
        $pipes = ['pipe1', 'pipe2'];
        $agent = new LaravelPipelineAgent($pipes);

        $this->assertEquals($pipes, $agent->getPipes());
        $this->assertEquals('handle', $agent->getMethod());
    }

    public function test_can_create_agent_with_custom_method(): void
    {
        $agent = new LaravelPipelineAgent([], 'process');

        $this->assertEquals('process', $agent->getMethod());
    }

    public function test_can_add_pipes_fluently(): void
    {
        $agent = new LaravelPipelineAgent();
        $agent->pipe('pipe1')->pipe('pipe2');

        $this->assertEquals(['pipe1', 'pipe2'], $agent->getPipes());
    }

    public function test_can_add_multiple_pipes(): void
    {
        $agent = new LaravelPipelineAgent(['initial']);
        $agent->pipes(['pipe1', 'pipe2']);

        $this->assertEquals(['initial', 'pipe1', 'pipe2'], $agent->getPipes());
    }

    public function test_executes_laravel_pipeline_on_context_payload(): void
    {
        // Mock Laravel Pipeline behavior
        $agent = new LaravelPipelineAgent([
            function ($data, $next) {
                $data['processed'] = true;
                return $next($data);
            },
            function ($data, $next) {
                $data['step'] = 2;
                return $next($data);
            }
        ]);

        $context = Context::create(['initial' => 'data']);
        $result = $agent($context);

        $this->assertInstanceOf(Context::class, $result);
        $this->assertTrue($result->payload['processed']);
        $this->assertEquals(2, $result->payload['step']);
        $this->assertEquals('data', $result->payload['initial']);
    }

    public function test_adds_metadata_about_execution(): void
    {
        $agent = new LaravelPipelineAgent([
            function ($data, $next) {
                return $next($data);
            }
        ]);

        $context = Context::create(['test' => 'data']);
        $result = $agent($context);

        $this->assertTrue($result->getMetadata('laravel_pipeline_executed'));
        $this->assertEquals(1, $result->getMetadata('pipe_count'));
        $this->assertTrue($result->hasTag('laravel-pipeline'));
    }

    public function test_handles_laravel_pipeline_exceptions(): void
    {
        $agent = new LaravelPipelineAgent([
            function ($data, $next) {
                throw new \RuntimeException('Test pipeline error');
            }
        ]);

        $context = Context::create(['test' => 'data']);
        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContains('Laravel Pipeline failed', $result->errors[0]);
        $this->assertStringContains('Test pipeline error', $result->errors[0]);
    }

    public function test_preserves_original_context_properties(): void
    {
        $agent = new LaravelPipelineAgent([
            function ($data, $next) {
                return $next(array_merge($data, ['new' => 'field']));
            }
        ]);

        $originalContext = Context::create(['original' => 'data'])
            ->withMetadata('test_key', 'test_value')
            ->withTag('test-tag')
            ->withCorrelationId('test-correlation');

        $result = $agent($originalContext);

        // Payload should be updated
        $this->assertEquals('field', $result->payload['new']);
        $this->assertEquals('data', $result->payload['original']);

        // Original context properties should be preserved
        $this->assertEquals('test_value', $result->getMetadata('test_key'));
        $this->assertTrue($result->hasTag('test-tag'));
        $this->assertEquals('test-correlation', $result->correlationId);
    }

    public function test_static_factory_methods(): void
    {
        $agent1 = LaravelPipelineAgent::create(['pipe1'], 'process');
        $this->assertEquals(['pipe1'], $agent1->getPipes());
        $this->assertEquals('process', $agent1->getMethod());

        $agent2 = LaravelPipelineAgent::through('pipe1', 'pipe2');
        $this->assertEquals(['pipe1', 'pipe2'], $agent2->getPipes());
        $this->assertEquals('handle', $agent2->getMethod());
    }

    public function test_provides_descriptive_name(): void
    {
        $agent = new LaravelPipelineAgent([
            'TestPipe',
            function () {},
        ]);

        $name = $agent->getName();
        $this->assertStringContains('LaravelPipelineAgent', $name);
        $this->assertStringContains('TestPipe', $name);
        $this->assertStringContains('Closure', $name);
    }

    public function test_estimates_execution_time_based_on_pipe_count(): void
    {
        $emptyAgent = new LaravelPipelineAgent();
        $this->assertEquals(0, $emptyAgent->getEstimatedExecutionTime());

        $agentWithPipes = new LaravelPipelineAgent(['pipe1', 'pipe2', 'pipe3']);
        $this->assertEquals(30, $agentWithPipes->getEstimatedExecutionTime()); // 3 * 10ms
    }

    public function test_works_with_sentinels_facade(): void
    {
        // This test verifies that the bridge can be used through the facade
        // Note: This might require mocking in a real test environment
        $pipes = [
            function ($data, $next) {
                $data['facade_test'] = true;
                return $next($data);
            }
        ];

        $agent = Sentinels::laravelPipeline($pipes);
        $this->assertInstanceOf(LaravelPipelineAgent::class, $agent);
        $this->assertEquals($pipes, $agent->getPipes());

        $fluentAgent = Sentinels::throughLaravelPipes(...$pipes);
        $this->assertInstanceOf(LaravelPipelineAgent::class, $fluentAgent);
        $this->assertEquals($pipes, $fluentAgent->getPipes());
    }

    public function test_integrates_with_sentinels_pipeline(): void
    {
        $testData = ['name' => 'john'];

        // Create a simple pipeline that uses Laravel Pipeline bridge
        $result = Sentinels::pipeline()
            ->pipe(Sentinels::laravelPipeline([
                function ($data, $next) {
                    $data['processed_by'] = 'laravel_pipeline';
                    return $next($data);
                }
            ]))
            ->through($testData);

        $this->assertEquals('john', $result['name']);
        $this->assertEquals('laravel_pipeline', $result['processed_by']);
    }

    public function test_error_handling_in_sentinels_pipeline(): void
    {
        $context = Sentinels::pipeline()
            ->pipe(Sentinels::laravelPipeline([
                function ($data, $next) {
                    throw new \RuntimeException('Simulated error');
                }
            ]))
            ->onError(function (Context $context, \Throwable $exception) {
                return $context->addError('Pipeline failed: ' . $exception->getMessage());
            })
            ->process(Context::create(['test' => 'data']));

        $this->assertTrue($context->hasErrors());
        $this->assertCount(2, $context->errors); // One from agent, one from error handler
    }
}