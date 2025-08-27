<?php

namespace Vampires\Sentinels\Support;

use Illuminate\Bus\Batch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Exceptions\PipelineException;

/**
 * Manages the lifecycle of async pipeline batches.
 *
 * Handles result aggregation, cache management, and cleanup
 * for asynchronous pipeline executions.
 */
class AsyncBatchManager
{
    /**
     * Aggregate results from a completed batch.
     *
     * @param Batch $batch The completed batch
     * @param Context $originalContext The original pipeline context
     * @return Context The aggregated context
     */
    public static function aggregateResults(Batch $batch, Context $originalContext): Context
    {
        $results = static::fetchBatchResults($batch);
        $errors = static::fetchBatchErrors($batch);

        // Merge all successful agent results
        $aggregatedPayload = [];
        $mergedMetadata = $originalContext->metadata;
        $mergedTags = $originalContext->tags;
        $allErrors = $originalContext->errors;

        foreach ($results as $jobId => $resultContext) {
            if ($resultContext instanceof Context) {
                // Collect payloads into an array
                $aggregatedPayload[$jobId] = $resultContext->payload;
                
                // Merge metadata and tags
                $mergedMetadata = array_merge($mergedMetadata, $resultContext->metadata);
                $mergedTags = array_unique(array_merge($mergedTags, $resultContext->tags));
                
                // Collect any errors from individual contexts
                $allErrors = array_merge($allErrors, $resultContext->errors);
            }
        }

        // Add batch-level errors
        foreach ($errors as $error) {
            $allErrors[] = sprintf(
                'Agent %s failed: %s',
                $error['agent_class'] ?? 'unknown',
                $error['message'] ?? 'Unknown error'
            );
        }

        // Create the final aggregated context
        return new Context(
            payload: $aggregatedPayload,
            metadata: array_merge($mergedMetadata, [
                'batch_id' => $batch->id,
                'parallel_execution' => true,
                'job_count' => $batch->totalJobs,
                'successful_jobs' => count($results),
                'failed_jobs' => count($errors),
            ]),
            correlationId: $originalContext->correlationId,
            tags: array_merge($mergedTags, ['async_parallel']),
            traceId: $originalContext->traceId,
            cancelled: $originalContext->cancelled,
            errors: $allErrors,
            startTime: $originalContext->startTime,
        );
    }

    /**
     * Fetch all successful results from batch cache.
     *
     * @param Batch $batch
     * @return Collection<string, Context>
     */
    public static function fetchBatchResults(Batch $batch): Collection
    {
        $results = collect();
        $pattern = "sentinels:batch:{$batch->id}:result:*";
        
        // Get all result keys for this batch
        $keys = static::getCacheKeys($pattern);
        
        foreach ($keys as $key) {
            $result = Cache::get($key);
            if ($result instanceof Context) {
                // Extract job ID from cache key
                $jobId = static::extractJobIdFromKey($key);
                $results->put($jobId, $result);
            }
        }

        return $results;
    }

    /**
     * Fetch all errors from batch cache.
     *
     * @param Batch $batch
     * @return Collection<string, array>
     */
    public static function fetchBatchErrors(Batch $batch): Collection
    {
        $errors = collect();
        $pattern = "sentinels:batch:{$batch->id}:error:*";
        
        // Get all error keys for this batch
        $keys = static::getCacheKeys($pattern);
        
        foreach ($keys as $key) {
            $error = Cache::get($key);
            if (is_array($error)) {
                $jobId = static::extractJobIdFromKey($key);
                $errors->put($jobId, $error);
            }
        }

        return $errors;
    }

    /**
     * Clean up all cache entries for a batch.
     *
     * @param Batch $batch
     * @return int Number of keys cleaned up
     */
    public static function cleanupBatchCache(Batch $batch): int
    {
        $patterns = [
            "sentinels:batch:{$batch->id}:result:*",
            "sentinels:batch:{$batch->id}:error:*",
            "sentinels:batch:{$batch->id}:final",
        ];

        $totalCleaned = 0;
        
        foreach ($patterns as $pattern) {
            $keys = static::getCacheKeys($pattern);
            foreach ($keys as $key) {
                Cache::forget($key);
                $totalCleaned++;
            }
        }

        return $totalCleaned;
    }

    /**
     * Store the final aggregated result.
     *
     * @param Batch $batch
     * @param Context $finalContext
     */
    public static function storeFinalResult(Batch $batch, Context $finalContext): void
    {
        $key = "sentinels:batch:{$batch->id}:final";
        $ttl = config('sentinels.async.cache_ttl', 3600);
        
        Cache::put($key, $finalContext, $ttl);
    }

    /**
     * Retrieve the final aggregated result.
     *
     * @param Batch $batch
     * @return Context|null
     */
    public static function getFinalResult(Batch $batch): ?Context
    {
        $key = "sentinels:batch:{$batch->id}:final";
        return Cache::get($key);
    }

    /**
     * Get cache keys matching a pattern.
     *
     * Note: This is a simplified implementation. In production,
     * consider using Redis SCAN or a more efficient key discovery method.
     */
    protected static function getCacheKeys(string $pattern): array
    {
        // This is a basic implementation - may need optimization for Redis
        $store = Cache::getStore();
        
        if (method_exists($store, 'connection')) {
            // Redis implementation
            $connection = $store->connection();
            return $connection->keys($pattern);
        }
        
        // For other cache drivers, we'll need to track keys differently
        // This is a limitation that should be documented
        throw new PipelineException('Cache key pattern matching requires Redis cache driver');
    }

    /**
     * Extract job ID from cache key.
     */
    protected static function extractJobIdFromKey(string $key): string
    {
        $parts = explode(':', $key);
        return end($parts);
    }

    /**
     * Generate a unique job identifier.
     */
    public static function generateJobIdentifier(): string
    {
        return uniqid('job_', true);
    }

    /**
     * Get batch statistics.
     *
     * @param Batch $batch
     * @return array
     */
    public static function getBatchStats(Batch $batch): array
    {
        return [
            'id' => $batch->id,
            'name' => $batch->name,
            'total_jobs' => $batch->totalJobs,
            'pending_jobs' => $batch->pendingJobs,
            'processed_jobs' => $batch->processedJobs(),
            'failed_jobs' => $batch->failedJobs,
            'progress' => $batch->progress(),
            'finished' => $batch->finished(),
            'cancelled' => $batch->cancelled(),
            'created_at' => $batch->createdAt,
            'finished_at' => $batch->finishedAt,
        ];
    }
}