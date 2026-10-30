<?php

namespace Vampires\Sentinels\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vampires\Sentinels\Core\AsyncContext;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Pipeline\Pipeline;
use Vampires\Sentinels\Tests\Fixtures\TestAgent;
use Vampires\Sentinels\Tests\TestCase;

class QueueWorkerProcessTest extends TestCase
{
    private const QUEUE = 'sentinels-process-test';

    private ?string $temporaryDirectory = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $directory = getenv('SENTINELS_WORKER_TEST_DIRECTORY');
        if ($directory === false) {
            $directory = sys_get_temp_dir() . '/sentinels-worker-' . bin2hex(random_bytes(8));
            mkdir($directory, 0700);
            touch($directory . '/queue.sqlite');
            $this->temporaryDirectory = $directory;
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => $directory . '/queue.sqlite',
            'prefix' => '',
            'busy_timeout' => 5000,
        ]);
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ]);
        $app['config']->set('queue.batching.database', 'testing');
        $app['config']->set('queue.batching.table', 'job_batches');
        $app['config']->set('queue.failed.driver', null);
        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file', [
            'driver' => 'file',
            'path' => $directory . '/cache',
        ]);
        $app['config']->set('cache.serializable_classes', [Context::class]);
        $app['config']->set('sentinels.async.queue', self::QUEUE);
        $app['config']->set('sentinels.async.job_timeout', 25);
        $app['config']->set('sentinels.async.job_tries', 7);
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->temporaryDirectory !== null) {
                (new Filesystem())->deleteDirectory($this->temporaryDirectory);
            }
        }
    }

    public function test_database_queue_worker_resolves_pipeline_across_processes(): void
    {
        if (getenv('SENTINELS_WORKER_TEST_DIRECTORY') !== false) {
            $exitCode = Artisan::call('queue:work', [
                'connection' => 'database',
                '--queue' => self::QUEUE,
                '--stop-when-empty' => true,
                '--sleep' => 0,
                '--timeout' => 10,
            ]);
            $this->assertSame(0, $exitCode, Artisan::output());

            return;
        }

        if (!function_exists('proc_open')) {
            $this->markTestSkipped('The cross-process worker check requires proc_open.');
        }

        Schema::create('jobs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('job_batches', static function (Blueprint $table): void {
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

        $pending = $this->app->make(Pipeline::class)
            ->mode('parallel')->async()
            ->pipe(TestAgent::class)->pipe(TestAgent::class)
            ->onSuccess(static function (Context $context): void {
                Cache::put('worker-process-callback', [
                    'pid' => getmypid(),
                    'payload' => $context->payload,
                ], 300);
            })
            ->through('hello');

        $this->assertInstanceOf(AsyncContext::class, $pending);
        $this->assertFalse($pending->isReady());
        $jobs = DB::table('jobs')->get();
        $this->assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $this->assertSame(self::QUEUE, $job->queue);
            $payload = json_decode($job->payload, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(25, $payload['timeout']);
            $this->assertSame(7, $payload['maxTries']);
        }

        $this->runWorkerProcess();

        $result = $pending->result(0);
        $this->assertSame(['job_0' => 'HELLO', 'job_1' => 'HELLO'], $result->payload);
        $this->assertFalse($result->hasErrors(), implode('; ', $result->errors));
        $this->assertTrue($pending->isReady());
        $this->assertSame('hello', $pending->payload);
        $callback = Cache::get('worker-process-callback');
        $this->assertIsArray($callback);
        $this->assertSame($result->payload, $callback['payload']);
        $this->assertNotSame(getmypid(), $callback['pid']);
        $this->assertTrue(Bus::findBatch($pending->getBatchId())->finished());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    private function runWorkerProcess(): void
    {
        $root = dirname(__DIR__, 2);
        $environment = getenv();
        $environment['SENTINELS_WORKER_TEST_DIRECTORY'] = $this->temporaryDirectory;
        $process = proc_open([
            PHP_BINARY,
            $root . '/vendor/bin/phpunit',
            '--do-not-cache-result',
            '--filter',
            'test_database_queue_worker_resolves_pipeline_across_processes',
            __FILE__,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        $this->assertIsResource($process, 'Unable to start the isolated queue worker.');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 30;

        try {
            do {
                $output .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $this->assertSame(0, $status['exitcode'], $output);

                    return;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);

            $this->fail('The isolated queue worker exceeded 30 seconds. ' . $output);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
