<?php

namespace Vampires\Sentinels\Tests\Integration;

use Illuminate\Pipeline\Pipeline as LaravelPipeline;
use Orchestra\Testbench\TestCase;
use Vampires\Sentinels\Agents\LaravelPipelineAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Facades\Sentinels;
use Vampires\Sentinels\SentinelsServiceProvider;

class LaravelPipelineIntegrationTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [SentinelsServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Sentinels' => Sentinels::class,
        ];
    }

    public function test_laravel_pipeline_bridge_works_end_to_end(): void
    {
        // Define some simple Laravel Pipeline pipes
        $pipes = [
            function ($data, $next) {
                $data['step1'] = 'completed';
                return $next($data);
            },
            function ($data, $next) {
                $data['step2'] = 'completed';
                $data['total_steps'] = 2;
                return $next($data);
            }
        ];

        // Use the bridge in a Sentinels pipeline
        $result = Sentinels::pipeline()
            ->pipe(new LaravelPipelineAgent($pipes))
            ->through(['initial' => 'data']);

        // Verify the result
        $this->assertEquals('data', $result['initial']);
        $this->assertEquals('completed', $result['step1']);
        $this->assertEquals('completed', $result['step2']);
        $this->assertEquals(2, $result['total_steps']);
    }

    public function test_facade_helpers_work(): void
    {
        $result = Sentinels::pipeline()
            ->pipe(Sentinels::laravelPipeline([
                function ($data, $next) {
                    $data['processed_by'] = 'facade_helper';
                    return $next($data);
                }
            ]))
            ->through(['test' => 'data']);

        $this->assertEquals('facade_helper', $result['processed_by']);
    }

    public function test_mixed_laravel_and_sentinels_agents(): void
    {
        // Create a simple Sentinels agent
        $sentinelsAgent = Sentinels::agent(function ($payload, $context) {
            $payload['sentinels_processed'] = true;
            return $payload;
        }, 'TestAgent');

        // Mix Laravel pipes with Sentinels agent
        $result = Sentinels::pipeline()
            ->pipe($sentinelsAgent)
            ->pipe(Sentinels::laravelPipeline([
                function ($data, $next) {
                    $data['laravel_processed'] = true;
                    return $next($data);
                }
            ]))
            ->through(['original' => 'data']);

        $this->assertTrue($result['sentinels_processed']);
        $this->assertTrue($result['laravel_processed']);
        $this->assertEquals('data', $result['original']);
    }

    public function test_error_handling_works_across_bridge(): void
    {
        $context = Sentinels::pipeline()
            ->pipe(Sentinels::laravelPipeline([
                function ($data, $next) {
                    throw new \RuntimeException('Laravel pipe error');
                }
            ]))
            ->onError(function (Context $context, \Throwable $exception) {
                return $context->addError('Handled: ' . $exception->getMessage());
            })
            ->process(Context::create(['test' => 'data']));

        $this->assertTrue($context->hasErrors());
        $this->assertStringContains('Laravel Pipeline failed', $context->errors[0]);
        $this->assertStringContains('Handled:', $context->errors[1]);
    }

    public function test_context_metadata_preserved_through_bridge(): void
    {
        $originalContext = Context::create(['test' => 'data'])
            ->withMetadata('tracking_id', 'ABC123')
            ->withTag('important');

        $result = Sentinels::pipeline()
            ->pipe(Sentinels::laravelPipeline([
                function ($data, $next) {
                    $data['modified'] = true;
                    return $next($data);
                }
            ]))
            ->process($originalContext);

        // Payload should be modified
        $this->assertTrue($result->payload['modified']);
        $this->assertEquals('data', $result->payload['test']);

        // Metadata should be preserved
        $this->assertEquals('ABC123', $result->getMetadata('tracking_id'));
        $this->assertTrue($result->hasTag('important'));
        
        // Bridge should add its own metadata
        $this->assertTrue($result->getMetadata('laravel_pipeline_executed'));
        $this->assertTrue($result->hasTag('laravel-pipeline'));
    }

    public function test_performance_comparison(): void
    {
        $data = ['name' => 'john', 'email' => 'JOHN@EXAMPLE.COM'];
        $iterations = 100;

        // Laravel Pipeline direct
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            app(LaravelPipeline::class)
                ->send($data)
                ->through([
                    fn($d, $next) => $next(array_merge($d, ['name' => ucfirst($d['name'])])),
                    fn($d, $next) => $next(array_merge($d, ['email' => strtolower($d['email'])])),
                ])
                ->thenReturn();
        }
        $laravelTime = microtime(true) - $start;

        // Sentinels with Laravel Pipeline bridge
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            Sentinels::pipeline()
                ->pipe(Sentinels::laravelPipeline([
                    fn($d, $next) => $next(array_merge($d, ['name' => ucfirst($d['name'])])),
                    fn($d, $next) => $next(array_merge($d, ['email' => strtolower($d['email'])])),
                ]))
                ->through($data);
        }
        $sentinelsTime = microtime(true) - $start;

        // Assert that Sentinels overhead is reasonable (less than 10x)
        $overhead = $sentinelsTime / $laravelTime;
        $this->assertLessThan(10, $overhead, 
            "Sentinels overhead too high: {$overhead}x (Laravel: {$laravelTime}s, Sentinels: {$sentinelsTime}s)"
        );
    }
}