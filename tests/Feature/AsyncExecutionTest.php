<?php

namespace Vampires\Sentinels\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Vampires\Sentinels\Core\AsyncContext;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Pipeline\AsyncBatchCallback;
use Vampires\Sentinels\Pipeline\Pipeline;
use Vampires\Sentinels\Tests\Fixtures\TestAgent;
use Vampires\Sentinels\Tests\TestCase;

class AsyncExecutionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('queue.batching.database', 'testing');
        $app['config']->set('queue.batching.table', 'job_batches');
    }

    private function batch(string $id, array $jobIds = ['job_0'], bool $finished = true): Batch
    {
        return new Batch(
            $this->app->make(QueueFactory::class),
            $this->app->make(BatchRepository::class),
            $id,
            'Async regression',
            count($jobIds),
            $finished ? 0 : count($jobIds),
            0,
            [],
            ['sentinels_job_ids' => $jobIds],
            CarbonImmutable::now(),
            null,
            $finished ? CarbonImmutable::now() : null,
        );
    }

    private function asyncContext(Batch $batch): AsyncContext
    {
        return AsyncContext::createWithBatch('submitted', [], 'async-correlation', [], null, false, [], microtime(true), $batch);
    }

    public function test_completed_result_returns_actual_payload_and_metadata(): void
    {
        $batch = $this->batch('completed');
        $final = Context::create('processed')->withMetadata('processed', true);
        Cache::put('sentinels:batch:completed:final', $final, 300);

        $handle = $this->asyncContext($batch);
        $this->assertSame('submitted', $handle->payload);
        $this->assertSame($final, $handle->result(0));
        $this->assertTrue($handle->result()->metadata['processed']);
        $this->assertTrue($handle->isReady());
        $this->assertSame($final->metadata, $handle->with('replacement')->metadata);
    }

    public function test_wait_polls_without_reassigning_readonly_batch(): void
    {
        $pending = $this->batch('pending', finished: false);
        $completed = $this->batch('pending');
        $final = Context::create('resolved');
        Bus::shouldReceive('findBatch')->once()->with('pending')->ordered()->andReturn($pending);
        Bus::shouldReceive('findBatch')->once()->with('pending')->ordered()->andReturnUsing(static function () use ($completed, $final) {
            Cache::put('sentinels:batch:pending:final', $final, 300);

            return $completed;
        });

        $handle = $this->asyncContext($pending);
        $this->assertSame($final, $handle->wait(2));
        $this->assertSame($pending, $handle->batch);
    }

    public function test_finished_batch_without_cached_result_fails_explicitly(): void
    {
        $batch = $this->batch('missing');
        Bus::shouldReceive('findBatch')->once()->with('missing')->andReturn($batch);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('result unavailable');
        $this->asyncContext($batch)->result(0);
    }

    public function test_array_cache_collects_ordered_results_errors_and_cleans_known_keys(): void
    {
        $batch = $this->batch('aggregate', ['job_0', 'job_1', 'job_2']);
        $batch->failedJobs = 1;
        $batch->cancelledAt = CarbonImmutable::now();
        Cache::put('sentinels:batch:aggregate:result:job_0', Context::create('good')->withMetadata('source', 'agent'), 300);
        Cache::put('sentinels:batch:aggregate:error:job_1', ['agent_class' => 'BrokenAgent', 'message' => 'broken'], 300);
        Cache::put('sentinels:batch:aggregate:result:unrelated', 'preserved', 300);

        $this->app->make(Pipeline::class)->completeAsyncBatch($batch, Context::create('original'));

        $result = $this->asyncContext($batch)->result(0);
        $this->assertSame(['job_0' => 'good'], $result->payload);
        $this->assertSame('agent', $result->metadata['source']);
        $this->assertSame(1, $result->metadata['successful_jobs']);
        $this->assertSame(2, $result->metadata['failed_jobs']);
        $this->assertContains('Agent BrokenAgent failed: broken', $result->errors);
        $this->assertContains('Agent unknown failed: No result available for job_2', $result->errors);
        $this->assertTrue($result->cancelled);
        $this->assertNull(Cache::get('sentinels:batch:aggregate:result:job_0'));
        $this->assertNull(Cache::get('sentinels:batch:aggregate:error:job_1'));
        $this->assertSame('preserved', Cache::get('sentinels:batch:aggregate:result:unrelated'));
    }

    public function test_real_batch_repository_serializes_callbacks_and_executes_jobs(): void
    {
        Schema::create('job_batches', static function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->text('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        $handle = $this->app->make(Pipeline::class)
            ->mode('parallel')->async()
            ->pipe(TestAgent::class)->pipe(TestAgent::class)
            ->onSuccess(static function (Context $result): void {
                Cache::put('async-success-count', Cache::get('async-success-count', 0) + 1, 300);
            })
            ->through('hello');

        $this->assertInstanceOf(AsyncContext::class, $handle);
        $result = $handle->result(0);
        $this->assertSame(['job_0' => 'HELLO', 'job_1' => 'HELLO'], $result->payload);
        $this->assertArrayHasKey('job_0', $result->payload);
        $this->assertArrayHasKey('job_1', $result->payload);
        $this->assertFalse($result->hasErrors(), implode('; ', $result->errors));
        $this->assertSame(1, Cache::get('async-success-count'));
        $stored = Bus::findBatch($handle->getBatchId());
        $this->assertInstanceOf(AsyncBatchCallback::class, $stored->options['finally'][0]);
    }

    public function test_ordinary_closure_without_this_serializes_and_executes(): void
    {
        $callback = new AsyncBatchCallback(Context::create('input'), [], function (Context $result): void {
            Cache::put('ordinary-callback', $result->payload, 300);
        });
        $restored = unserialize(serialize($callback));
        $restored($this->batch('ordinary', []));
        $this->assertSame([], Cache::get('ordinary-callback'));
    }

    public function test_bound_user_callback_is_rejected_before_dispatch(): void
    {
        $this->expectException(\Vampires\Sentinels\Exceptions\PipelineException::class);
        $this->expectExceptionMessage('static closures');
        new AsyncBatchCallback(Context::create('input'), [], function (): void {
            $this->fail('A bound callback must never execute');
        });
    }
}
