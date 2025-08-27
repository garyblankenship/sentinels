<?php

namespace Vampires\Sentinels\Tests\Unit\Agents;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Vampires\Sentinels\Agents\ExternalServiceAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Tests\TestCase;

class ExternalServiceAgentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_successful_service_call(): void
    {
        $agent = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for unit tests
            
            protected function callService(mixed $data, Context $context): mixed
            {
                return ['result' => 'success', 'data' => $data];
            }

            protected function getServiceName(): string
            {
                return 'test_service';
            }
        };

        $context = Context::create(['test' => 'data']);
        $result = $agent($context);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals(['result' => 'success', 'data' => ['test' => 'data']], $result->payload);
        $this->assertTrue($result->getMetadata('external_service_success'));
        $this->assertEquals('test_service', $result->getMetadata('service_name'));
    }

    public function test_service_call_failure_uses_fallback(): void
    {
        $agent = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for unit tests
            
            protected function callService(mixed $data, Context $context): mixed
            {
                throw new Exception('Service unavailable');
            }

            protected function getServiceName(): string
            {
                return 'failing_service';
            }

            protected function getFallbackResponse(mixed $data, Context $context): mixed
            {
                return ['fallback' => true, 'original_data' => $data];
            }
        };

        $context = Context::create(['test' => 'data']);
        $result = $agent($context);

        $this->assertFalse($result->hasErrors());
        $this->assertEquals(['fallback' => true, 'original_data' => ['test' => 'data']], $result->payload);
        $this->assertTrue($result->getMetadata('fallback_used'));
        $this->assertEquals('failing_service', $result->getMetadata('service_name'));
    }

    public function test_service_call_failure_without_fallback(): void
    {
        $agent = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for unit tests
            
            protected function callService(mixed $data, Context $context): mixed
            {
                throw new Exception('Service unavailable');
            }

            protected function getServiceName(): string
            {
                return 'failing_service';
            }
        };

        $context = Context::create(['test' => 'data']);
        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('External service \'failing_service\' failed: Service unavailable', $result->errors[0]);
        $this->assertTrue($result->getMetadata('external_service_failed'));
    }

    public function test_circuit_breaker_opens_after_failures(): void
    {
        $agent = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for unit tests
            
            protected array $circuitBreaker = [
                'failure_threshold' => 2,
                'recovery_timeout' => 60,
                'success_threshold' => 1,
            ];

            protected function callService(mixed $data, Context $context): mixed
            {
                throw new Exception('Service always fails');
            }

            protected function getServiceName(): string
            {
                return 'circuit_breaker_test';
            }

            protected function getFallbackResponse(mixed $data, Context $context): mixed
            {
                return ['circuit_breaker_fallback' => true];
            }
        };

        $context = Context::create(['test' => 'data']);

        // First failure
        $result1 = $agent($context);
        $this->assertEquals(['circuit_breaker_fallback' => true], $result1->payload);

        // Second failure - should trigger circuit breaker
        $result2 = $agent($context);
        $this->assertEquals(['circuit_breaker_fallback' => true], $result2->payload);

        // Third call - circuit should be open, should use fallback immediately
        $result3 = $agent($context);
        $this->assertEquals(['circuit_breaker_fallback' => true], $result3->payload);
        $this->assertTrue($result3->getMetadata('fallback_used'));
    }

    public function test_caching_successful_responses(): void
    {
        $callCount = 0;
        
        $agent = new class($callCount) extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for unit tests
            private int $callCount;

            public function __construct(&$callCount)
            {
                $this->callCount = &$callCount;
            }

            protected function callService(mixed $data, Context $context): mixed
            {
                $this->callCount++;
                return ['call_count' => $this->callCount, 'data' => $data];
            }

            protected function getServiceName(): string
            {
                return 'cache_test_service';
            }
        };

        $context = Context::create(['test' => 'data']);

        // First call should hit the service
        $result1 = $agent($context);
        $this->assertEquals(1, $result1->payload['call_count']);
        $this->assertFalse($result1->getMetadata('cached', false));

        // Second call should use cache
        $result2 = $agent($context);
        $this->assertEquals(1, $result2->payload['call_count']); // Same count, meaning cached
        $this->assertTrue($result2->getMetadata('cached'));
    }

    public function test_rate_limiting(): void
    {
        $agent = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for unit tests
            
            protected array $rateLimiting = [
                'enabled' => true,
                'requests_per_minute' => 1, // Only 1 request per minute
            ];

            protected function callService(mixed $data, Context $context): mixed
            {
                return ['success' => true];
            }

            protected function getServiceName(): string
            {
                return 'rate_limited_service';
            }

            protected function getFallbackResponse(mixed $data, Context $context): mixed
            {
                return ['rate_limited' => true];
            }
        };

        $context = Context::create(['test' => 'data']);

        // First call should succeed
        $result1 = $agent($context);
        $this->assertEquals(['success' => true], $result1->payload);

        // Second call should hit rate limit and use fallback
        $result2 = $agent($context);
        $this->assertEquals(['rate_limited' => true], $result2->payload);
        $this->assertTrue($result2->getMetadata('fallback_used'));
    }

    public function test_http_get_helper(): void
    {
        Http::fake([
            'https://api.example.com/test' => Http::response(['success' => true], 200)
        ]);

        $agent = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for unit tests
            
            protected function callService(mixed $data, Context $context): mixed
            {
                return $this->get('https://api.example.com/test');
            }

            protected function getServiceName(): string
            {
                return 'http_test_service';
            }
        };

        $context = Context::create(['test' => 'data']);
        $result = $agent($context);

        $this->assertEquals(['success' => true], $result->payload);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.example.com/test';
        });
    }

    public function test_http_post_helper(): void
    {
        Http::fake([
            'https://api.example.com/create' => Http::response(['created' => true], 201)
        ]);

        $agent = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for unit tests
            
            protected function callService(mixed $data, Context $context): mixed
            {
                return $this->post('https://api.example.com/create', $data);
            }

            protected function getServiceName(): string
            {
                return 'http_post_service';
            }
        };

        $context = Context::create(['name' => 'Test']);
        $result = $agent($context);

        $this->assertEquals(['created' => true], $result->payload);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.example.com/create' 
                && $request->data() === ['name' => 'Test'];
        });
    }

    public function test_http_error_handling(): void
    {
        Http::fake([
            'https://api.example.com/error' => Http::response(['error' => 'Not found'], 404)
        ]);

        $agent = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for unit tests
            
            protected function callService(mixed $data, Context $context): mixed
            {
                return $this->get('https://api.example.com/error');
            }

            protected function getServiceName(): string
            {
                return 'http_error_service';
            }

            protected function getFallbackResponse(mixed $data, Context $context): mixed
            {
                return ['fallback_due_to_http_error' => true];
            }
        };

        $context = Context::create(['test' => 'data']);
        $result = $agent($context);

        $this->assertEquals(['fallback_due_to_http_error' => true], $result->payload);
        $this->assertTrue($result->getMetadata('fallback_used'));
    }

    public function test_retry_policy_configuration(): void
    {
        $agent = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for unit tests
            
            protected array $httpConfig = [
                'timeout' => 15,
                'retry_attempts' => 2,
                'retry_delay' => 500,
            ];

            protected function callService(mixed $data, Context $context): mixed
            {
                return ['success' => true];
            }

            protected function getServiceName(): string
            {
                return 'retry_test_service';
            }
        };

        $retryPolicy = $agent->getRetryPolicy();

        $this->assertEquals(2, $retryPolicy->maxAttempts);
        $this->assertEquals(500, $retryPolicy->baseDelay);
    }

    public function test_estimated_execution_time(): void
    {
        $agent = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for unit tests
            
            protected array $httpConfig = [
                'timeout' => 10,
                'retry_attempts' => 3,
                'retry_delay' => 1000,
            ];

            protected function callService(mixed $data, Context $context): mixed
            {
                return ['success' => true];
            }

            protected function getServiceName(): string
            {
                return 'timing_test_service';
            }
        };

        $estimatedTime = $agent->getEstimatedExecutionTime();

        // 10 second timeout * 1000ms + 3 retries * 1000ms delay
        $expectedTime = (10 * 1000) + (3 * 1000);
        $this->assertEquals($expectedTime, $estimatedTime);
    }

    public function test_tags_include_service_information(): void
    {
        $agent = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for unit tests
            
            protected function callService(mixed $data, Context $context): mixed
            {
                return ['success' => true];
            }

            protected function getServiceName(): string
            {
                return 'tagged_service';
            }
        };

        $tags = $agent->getTags();

        $this->assertContains('external_service', $tags);
        $this->assertContains('http_client', $tags);
        $this->assertContains('circuit_breaker', $tags);
        $this->assertContains('tagged_service', $tags);
    }
}