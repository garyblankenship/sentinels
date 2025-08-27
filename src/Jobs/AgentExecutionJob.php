<?php

namespace Vampires\Sentinels\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Vampires\Sentinels\Contracts\AgentMediator;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Exceptions\AgentException;

/**
 * Job for executing a single agent asynchronously within a batch.
 *
 * This job handles the execution of individual agents in parallel pipelines,
 * caching results for later aggregation by the batch completion callback.
 */
class AgentExecutionJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     *
     * @param Context $context The context to process
     * @param string $agentClass The agent class name to execute
     * @param string $jobIdentifier Unique identifier for this job within the batch
     */
    public function __construct(
        public Context $context,
        public string $agentClass,
        public string $jobIdentifier
    ) {
        $this->queue = config('sentinels.async.queue', 'default');
    }

    /**
     * Execute the job.
     */
    public function handle(AgentMediator $mediator): void
    {
        // Skip if batch is already cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            // Execute the agent through the mediator
            $resultContext = $mediator->dispatch($this->context, $this->agentClass);

            // Cache the result for batch aggregation
            $this->cacheResult($resultContext);

        } catch (\Throwable $exception) {
            // Re-throw to trigger batch failure handling
            // Error will be cached automatically in failed() method
            throw new AgentException(
                "Agent {$this->agentClass} failed: " . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Cache the final failure for batch aggregation
        $this->cacheError($exception);
    }

    /**
     * Cache the successful result.
     */
    protected function cacheResult(Context $result): void
    {
        if (!$this->batch()) {
            return;
        }

        $batchId = $this->batch()->id;
        $key = "sentinels:batch:{$batchId}:result:{$this->jobIdentifier}";
        $ttl = config('sentinels.async.cache_ttl', 3600);

        Cache::put($key, $result, $ttl);
    }

    /**
     * Cache an error result.
     */
    protected function cacheError(\Throwable $exception): void
    {
        if (!$this->batch()) {
            return;
        }

        $batchId = $this->batch()->id;
        $key = "sentinels:batch:{$batchId}:error:{$this->jobIdentifier}";
        $ttl = config('sentinels.async.cache_ttl', 3600);

        $errorData = [
            'agent_class' => $this->agentClass,
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'context_id' => $this->context->correlationId,
        ];

        Cache::put($key, $errorData, $ttl);
    }


}