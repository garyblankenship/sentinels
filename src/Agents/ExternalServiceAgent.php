<?php

namespace Vampires\Sentinels\Agents;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\RetryPolicy;

/**
 * Base class for agents that interact with external services.
 *
 * Provides built-in patterns for:
 * - HTTP client management with timeouts and retries
 * - Circuit breaker pattern for service protection
 * - Fallback mechanisms when services are unavailable
 * - Request/response logging and debugging
 * - Caching for performance optimization
 * - Rate limiting and throttling
 */
abstract class ExternalServiceAgent extends BaseAgent
{
    /**
     * Circuit breaker configuration.
     */
    protected array $circuitBreaker = [
        'failure_threshold' => 5,    // Failures before opening circuit
        'recovery_timeout' => 60,    // Seconds before attempting recovery
        'success_threshold' => 3,    // Successes needed to close circuit
    ];

    /**
     * HTTP client configuration.
     */
    protected array $httpConfig = [
        'timeout' => 30,           // Request timeout in seconds
        'connect_timeout' => 5,    // Connection timeout in seconds
        'retry_attempts' => 3,     // Number of retry attempts
        'retry_delay' => 1000,     // Delay between retries in milliseconds
    ];

    /**
     * Caching configuration.
     */
    protected array $cacheConfig = [
        'enabled' => true,         // Whether to use caching
        'ttl' => 300,             // Cache TTL in seconds (5 minutes)
        'key_prefix' => null,     // Cache key prefix (auto-generated if null)
    ];

    /**
     * Rate limiting configuration.
     */
    protected array $rateLimiting = [
        'enabled' => true,        // Whether to enforce rate limiting
        'requests_per_minute' => 60, // Requests allowed per minute
    ];

    /**
     * Whether to preserve the original payload when adding service results.
     * 
     * When true, service results are added as a sub-array to the original payload.
     * When false, service response replaces the entire payload (legacy behavior).
     */
    protected bool $preservePayload = true;

    final protected function handle(Context $context): Context
    {
        // Check circuit breaker
        if ($this->isCircuitOpen()) {
            $this->log('warning', 'Circuit breaker is open, using fallback', [
                'service' => $this->getServiceName(),
                'correlation_id' => $context->correlationId,
            ]);
            
            return $this->handleFallback($context);
        }

        // Check rate limiting
        if (!$this->checkRateLimit($context)) {
            $this->log('warning', 'Rate limit exceeded, using fallback', [
                'service' => $this->getServiceName(),
                'correlation_id' => $context->correlationId,
            ]);
            
            return $this->handleFallback($context);
        }

        // Try cache first
        $cacheKey = $this->getCacheKey($context);
        if ($this->isCacheEnabled() && $cacheKey) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                $this->log('debug', 'Returning cached response', [
                    'service' => $this->getServiceName(),
                    'cache_key' => $cacheKey,
                ]);
                
                if ($this->preservePayload) {
                    // Preserve original payload and add cached response
                    $preservedPayload = $context->payload;
                    $resultKey = $this->getResultKey();
                    
                    if (is_array($preservedPayload)) {
                        $preservedPayload[$resultKey] = $cached;
                        $newPayload = $preservedPayload;
                    } else {
                        $newPayload = [
                            'original' => $preservedPayload,
                            $resultKey => $cached,
                        ];
                    }
                } else {
                    $newPayload = $cached;
                }
                
                return $context->with($newPayload)->withMetadata('cached', true);
            }
        }

        try {
            // Call the external service
            $response = $this->callService($context->payload, $context);
            
            // Record success for circuit breaker
            $this->recordSuccess();
            
            // Cache the response
            if ($this->isCacheEnabled() && $cacheKey && $this->shouldCacheResponse($response)) {
                Cache::put($cacheKey, $response, $this->cacheConfig['ttl']);
            }
            
            $this->log('info', 'External service call successful', [
                'service' => $this->getServiceName(),
                'correlation_id' => $context->correlationId,
            ]);

            $serviceName = $this->getServiceName();
            
            if ($this->preservePayload) {
                // Preserve original payload and add service response
                $preservedPayload = $context->payload;
                $resultKey = $this->getResultKey();
                
                // If payload is array, merge service response
                if (is_array($preservedPayload)) {
                    $preservedPayload[$resultKey] = $response;
                    $newPayload = $preservedPayload;
                } else {
                    // For non-array payloads, create array structure
                    $newPayload = [
                        'original' => $preservedPayload,
                        $resultKey => $response,
                    ];
                }
            } else {
                // Legacy behavior: replace entire payload with service response
                $newPayload = $response;
            }

            return $context
                ->with($newPayload)
                ->withMetadata('external_service_success', true)
                ->withMetadata('service_name', $serviceName);

        } catch (Throwable $exception) {
            // Record failure for circuit breaker
            $this->recordFailure();
            
            $this->log('error', 'External service call failed', [
                'service' => $this->getServiceName(),
                'error' => $exception->getMessage(),
                'correlation_id' => $context->correlationId,
            ]);

            // Use fallback if available
            return $this->handleFallback($context, $exception);
        }
    }

    /**
     * Call the external service.
     *
     * This method must be implemented by subclasses to define
     * the specific service interaction.
     *
     * @param mixed $data The data to send to the service
     * @param Context $context The full context for additional metadata
     * @return mixed The response from the service
     * @throws Exception When the service call fails
     */
    abstract protected function callService(mixed $data, Context $context): mixed;

    /**
     * Get the service name for logging and metrics.
     */
    abstract protected function getServiceName(): string;

    /**
     * Get the key name for storing service results in the payload.
     * 
     * Override this method to customize how results are stored.
     * By default, it uses the service name with '_results' suffix.
     */
    protected function getResultKey(): string
    {
        return $this->getServiceName() . '_results';
    }

    /**
     * Handle fallback when the service is unavailable.
     *
     * Override this method to provide fallback logic specific to your service.
     * The default implementation returns an error context.
     *
     * @param Context $context The original context
     * @param Throwable|null $exception The exception that triggered fallback
     */
    protected function handleFallback(Context $context, ?Throwable $exception = null): Context
    {
        $fallbackResponse = $this->getFallbackResponse($context->payload, $context);
        
        if ($fallbackResponse !== null) {
            if ($this->preservePayload) {
                // Preserve original payload and add fallback response
                $preservedPayload = $context->payload;
                $resultKey = $this->getResultKey();
                
                if (is_array($preservedPayload)) {
                    $preservedPayload[$resultKey] = $fallbackResponse;
                    $newPayload = $preservedPayload;
                } else {
                    $newPayload = [
                        'original' => $preservedPayload,
                        $resultKey => $fallbackResponse,
                    ];
                }
            } else {
                $newPayload = $fallbackResponse;
            }
            
            return $context
                ->with($newPayload)
                ->withMetadata('fallback_used', true)
                ->withMetadata('service_name', $this->getServiceName());
        }

        $errorMessage = $exception?->getMessage() ?? 'External service unavailable';
        
        return $context
            ->addError("External service '{$this->getServiceName()}' failed: {$errorMessage}")
            ->withMetadata('external_service_failed', true)
            ->withMetadata('service_name', $this->getServiceName());
    }

    /**
     * Provide a fallback response when the service is unavailable.
     *
     * Override this method to provide meaningful fallback data.
     * Return null if no fallback is possible.
     *
     * @param mixed $data The original data
     * @param Context $context The processing context
     * @return mixed|null The fallback response or null
     */
    protected function getFallbackResponse(mixed $data, Context $context): mixed
    {
        return null;
    }

    /**
     * Create an HTTP client with configured options.
     */
    protected function createHttpClient(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout($this->httpConfig['timeout'])
            ->connectTimeout($this->httpConfig['connect_timeout'])
            ->retry(
                $this->httpConfig['retry_attempts'],
                $this->httpConfig['retry_delay']
            );
    }

    /**
     * Make an HTTP GET request with error handling.
     *
     * @param string $url The URL to request
     * @param array<string, mixed> $query Query parameters
     * @param array<string, string> $headers Additional headers
     * @return mixed The response data
     * @throws Exception When the request fails
     */
    protected function get(string $url, array $query = [], array $headers = []): mixed
    {
        $response = $this->createHttpClient()
            ->withHeaders($headers)
            ->get($url, $query);

        if (!$response->successful()) {
            throw new Exception(
                "HTTP GET failed: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json() ?? $response->body();
    }

    /**
     * Make an HTTP POST request with error handling.
     *
     * @param string $url The URL to post to
     * @param mixed $data The data to send
     * @param array<string, string> $headers Additional headers
     * @return mixed The response data
     * @throws Exception When the request fails
     */
    protected function post(string $url, mixed $data, array $headers = []): mixed
    {
        $response = $this->createHttpClient()
            ->withHeaders($headers)
            ->post($url, $data);

        if (!$response->successful()) {
            throw new Exception(
                "HTTP POST failed: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json() ?? $response->body();
    }

    /**
     * Make an HTTP PUT request with error handling.
     *
     * @param string $url The URL to put to
     * @param mixed $data The data to send
     * @param array<string, string> $headers Additional headers
     * @return mixed The response data
     * @throws Exception When the request fails
     */
    protected function put(string $url, mixed $data, array $headers = []): mixed
    {
        $response = $this->createHttpClient()
            ->withHeaders($headers)
            ->put($url, $data);

        if (!$response->successful()) {
            throw new Exception(
                "HTTP PUT failed: {$response->status()} - {$response->body()}"
            );
        }

        return $response->json() ?? $response->body();
    }

    /**
     * Check if the circuit breaker is open.
     */
    protected function isCircuitOpen(): bool
    {
        if (!$this->isCircuitBreakerEnabled()) {
            return false;
        }

        $cacheKey = $this->getCircuitBreakerKey();
        $state = Cache::get($cacheKey, ['failures' => 0, 'last_failure' => null]);

        // Circuit is open if we've exceeded failure threshold
        if ($state['failures'] >= $this->circuitBreaker['failure_threshold']) {
            // Check if recovery timeout has passed
            if ($state['last_failure'] === null) {
                return false; // No last failure time, circuit should not be open
            }
            
            $recoveryTime = $state['last_failure'] + $this->circuitBreaker['recovery_timeout'];
            
            return time() < $recoveryTime;
        }

        return false;
    }

    /**
     * Record a successful service call.
     */
    protected function recordSuccess(): void
    {
        if (!$this->isCircuitBreakerEnabled()) {
            return;
        }

        $cacheKey = $this->getCircuitBreakerKey();
        $state = Cache::get($cacheKey, ['failures' => 0, 'successes' => 0]);
        
        $state['successes'] = ($state['successes'] ?? 0) + 1;

        // Reset failures if we've had enough successes
        if ($state['successes'] >= $this->circuitBreaker['success_threshold']) {
            $state['failures'] = 0;
            $state['successes'] = 0;
            $state['last_failure'] = null;
        }

        Cache::put($cacheKey, $state, 3600); // 1 hour TTL
    }

    /**
     * Record a failed service call.
     */
    protected function recordFailure(): void
    {
        if (!$this->isCircuitBreakerEnabled()) {
            return;
        }

        $cacheKey = $this->getCircuitBreakerKey();
        $state = Cache::get($cacheKey, ['failures' => 0, 'successes' => 0]);
        
        $state['failures'] = ($state['failures'] ?? 0) + 1;
        $state['successes'] = 0; // Reset success counter
        $state['last_failure'] = time();

        Cache::put($cacheKey, $state, 3600); // 1 hour TTL
    }

    /**
     * Check rate limiting.
     */
    protected function checkRateLimit(Context $context): bool
    {
        if (!$this->rateLimiting['enabled']) {
            return true;
        }

        $key = $this->getRateLimitKey($context);
        $requests = Cache::get($key, 0);

        if ($requests >= $this->rateLimiting['requests_per_minute']) {
            return false;
        }

        // Increment counter with 60-second TTL
        Cache::put($key, $requests + 1, 60);

        return true;
    }

    /**
     * Generate cache key for the request.
     *
     * Override this method to customize cache key generation.
     */
    protected function getCacheKey(Context $context): ?string
    {
        if (!$this->isCacheEnabled()) {
            return null;
        }

        $prefix = $this->cacheConfig['key_prefix'] ?? $this->getServiceName();
        $payloadHash = $this->createSecurePayloadHash($context->payload);

        return "external_service:{$prefix}:{$payloadHash}";
    }

    /**
     * Determine if response should be cached.
     *
     * Override this method to implement custom caching logic.
     */
    protected function shouldCacheResponse(mixed $response): bool
    {
        // Don't cache error responses or empty responses
        return $response !== null && !$this->isErrorResponse($response);
    }

    /**
     * Check if response indicates an error.
     *
     * Override this method for service-specific error detection.
     */
    protected function isErrorResponse(mixed $response): bool
    {
        if (is_array($response)) {
            return isset($response['error']) || isset($response['errors']);
        }

        return false;
    }

    /**
     * Get circuit breaker cache key.
     */
    private function getCircuitBreakerKey(): string
    {
        return "circuit_breaker:{$this->getServiceName()}:" . static::class;
    }

    /**
     * Get rate limit cache key.
     */
    private function getRateLimitKey(Context $context): string
    {
        $identifier = $context->getMetadata('user_id', 'anonymous');
        $minute = floor(time() / 60);
        
        return "rate_limit:{$this->getServiceName()}:{$identifier}:{$minute}";
    }

    /**
     * Check if circuit breaker is enabled.
     */
    private function isCircuitBreakerEnabled(): bool
    {
        return $this->circuitBreaker['failure_threshold'] > 0;
    }

    /**
     * Check if caching is enabled.
     */
    private function isCacheEnabled(): bool
    {
        return $this->cacheConfig['enabled'] && $this->cacheConfig['ttl'] > 0;
    }

    /**
     * Create a secure hash of the payload for cache key generation.
     * 
     * Uses JSON encoding with SHA-256 hash instead of unsafe serialize() + MD5.
     * Handles circular references gracefully.
     */
    private function createSecurePayloadHash(mixed $payload): string
    {
        try {
            // First, try standard JSON encoding
            $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // If JSON encoding fails (e.g., circular references), create a fallback representation
            $jsonPayload = $this->createSafePayloadRepresentation($payload);
        }
        
        return hash('sha256', $jsonPayload);
    }
    
    /**
     * Create a safe string representation of payload that handles circular references.
     */
    private function createSafePayloadRepresentation(mixed $payload): string
    {
        if (is_object($payload)) {
            return sprintf('object(%s)', get_class($payload));
        }
        
        if (is_array($payload)) {
            $safeArray = [];
            foreach ($payload as $key => $value) {
                if (is_object($value)) {
                    $safeArray[$key] = sprintf('object(%s)', get_class($value));
                } elseif (is_array($value)) {
                    // Limit depth to prevent infinite recursion
                    $safeArray[$key] = '[array]';
                } else {
                    $safeArray[$key] = $value;
                }
            }
            return json_encode($safeArray, JSON_THROW_ON_ERROR);
        }
        
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * Get retry policy for external service calls.
     */
    public function getRetryPolicy(): ?RetryPolicy
    {
        return new RetryPolicy(
            maxAttempts: $this->httpConfig['retry_attempts'],
            baseDelay: $this->httpConfig['retry_delay'],
            multiplier: 2.0,
            maxDelay: 30000
        );
    }

    /**
     * Get estimated execution time.
     */
    public function getEstimatedExecutionTime(): int
    {
        // Base timeout plus potential retry delays
        $baseTime = $this->httpConfig['timeout'] * 1000; // Convert to milliseconds
        $retryTime = $this->httpConfig['retry_attempts'] * $this->httpConfig['retry_delay'];
        
        return $baseTime + $retryTime;
    }

    /**
     * Get agent tags.
     */
    public function getTags(): array
    {
        return array_merge(parent::getTags(), [
            'external_service',
            'http_client',
            'circuit_breaker',
            $this->getServiceName(),
        ]);
    }
}