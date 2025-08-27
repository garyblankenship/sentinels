<?php

namespace Vampires\Sentinels\Tests\Integration;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Agents\ExternalServiceAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Pipeline\Pipeline;
use Vampires\Sentinels\Support\ContextBuilder;
use Vampires\Sentinels\Support\Validation\ValidatesOrder;
use Vampires\Sentinels\Support\Validation\ValidatesPayment;
use Vampires\Sentinels\Support\Validation\ValidatesUser;
use Vampires\Sentinels\Tests\TestCase;

class ElegantPatternsTest extends TestCase
{
    public function test_complete_order_processing_workflow(): void
    {
        // Create a mock user
        $user = new class extends User {
            public $id = 123;
            public $email = 'customer@example.com';
            public $name = 'John Doe';

            public function getKey()
            {
                return $this->id;
            }
        };

        // Create order data using ContextBuilder
        $orderData = [
            'id' => 'ORD-2023-001',
            'status' => 'pending',
            'total' => 299.97,
            'subtotal' => 269.97,
            'tax' => 29.99,
            'discount' => 0.00,
            'customer_id' => 123,
            'items' => [
                ['id' => 'ITEM-1', 'name' => 'Widget A', 'quantity' => 2, 'price' => 99.99, 'line_total' => 199.98],
                ['id' => 'ITEM-2', 'name' => 'Widget B', 'quantity' => 1, 'price' => 69.99, 'line_total' => 69.99],
            ],
        ];

        $context = ContextBuilder::for($orderData)
            ->withUser($user)
            ->asHighPriority()
            ->withBusinessObject('order', 'ORD-2023-001', ['status' => 'pending'])
            ->withMetadata('source', 'web_checkout')
            ->withTag('ecommerce')
            ->build();

        // Create validation agent
        $validationAgent = new class extends BaseAgent {
            use ValidatesOrder;

            protected function handle(Context $context): Context
            {
                $validation = $this->validateOrder($context);
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                $amountValidation = $this->validateOrderAmounts($context->payload);
                if (!$amountValidation->valid) {
                    return $context->addErrors($amountValidation->getAllErrors());
                }

                $itemsValidation = $this->validateOrderItems($context->payload['items']);
                if (!$itemsValidation->valid) {
                    return $context->addErrors($itemsValidation->getAllErrors());
                }

                return $context->withMetadata('order_validated', true);
            }
        };

        // Create inventory check service
        $inventoryService = new class extends ExternalServiceAgent {
            protected function callService(mixed $data, Context $context): mixed
            {
                // Simulate inventory check API call
                return [
                    'available' => true,
                    'items' => [
                        'ITEM-1' => ['in_stock' => 10, 'reserved' => 2],
                        'ITEM-2' => ['in_stock' => 5, 'reserved' => 1],
                    ],
                ];
            }

            protected function getServiceName(): string
            {
                return 'inventory_service';
            }

            protected function getFallbackResponse(mixed $data, Context $context): mixed
            {
                return ['available' => true, 'fallback_used' => true];
            }
        };

        // Create order processing pipeline with debugging
        $pipeline = Pipeline::create()
            ->tap(fn($ctx) => $this->assertInstanceOf(Context::class, $ctx))
            ->pipe($validationAgent)
            ->validate(function (Context $context) {
                return !$context->hasErrors();
            }, 'Validation failed')
            ->dump('After Validation')
            ->pipe($inventoryService)
            ->ray('After Inventory Check')
            ->pipe(function (mixed $payload, Context $context) {
                // Mark order as confirmed
                $orderData = $payload;
                if (isset($orderData['items'])) {
                    $orderData['status'] = 'confirmed';
                    $orderData['confirmed_at'] = now()->toISOString();
                    return $context->with($orderData);
                }
                return $context;
            })
            ->logContext('info', 'Order processing completed');

        $result = $pipeline->process($context);

        // Debug errors
        if ($result->hasErrors()) {
            dump('test_complete_order_processing_workflow errors:', $result->errors);
        }

        // Assertions
        $this->assertFalse($result->hasErrors(), 'Order processing should succeed without errors');
        $this->assertEquals('confirmed', $result->payload['status'] ?? null);
        $this->assertTrue($result->getMetadata('order_validated'));
        $this->assertTrue($result->getMetadata('external_service_success'));
        $this->assertEquals('inventory_service', $result->getMetadata('service_name'));
        
        // Context Builder assertions
        $this->assertEquals(123, $result->getMetadata('user_id'));
        $this->assertEquals('customer@example.com', $result->getMetadata('user_email'));
        $this->assertTrue($result->hasTag('authenticated'));
        $this->assertTrue($result->hasTag('high_priority'));
        $this->assertTrue($result->hasTag('ecommerce'));
        $this->assertEquals('ORD-2023-001', $result->getMetadata('order_id'));
    }

    public function test_payment_processing_with_validation_and_external_service(): void
    {
        // Create payment context
        $paymentData = [
            'amount' => 299.97,
            'currency' => 'USD',
            'payment_method' => 'credit_card',
            'reference_id' => 'PAY-2023-001',
            'card_number' => '4111111111111111', // Test Visa card
            'expiry_month' => 12,
            'expiry_year' => 2025,
            'cvv' => '123',
        ];

        $context = ContextBuilder::for($paymentData)
            ->withMetadata('client_ip', '192.168.1.100')
            ->withMetadata('user_agent', 'Mozilla/5.0 Test Browser')
            ->asRealTime()
            ->withTag('payment_processing')
            ->build();

        // Create payment validation agent
        $paymentValidator = new class extends BaseAgent {
            use ValidatesPayment;

            protected function handle(Context $context): Context
            {
                $validation = $this->validatePayment($context);
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                $methodValidation = $this->validatePaymentMethod($context->payload);
                if (!$methodValidation->valid) {
                    return $context->addErrors($methodValidation->getAllErrors());
                }

                return $context->withMetadata('payment_validated', true);
            }
        };

        // Mock payment gateway service
        Http::fake([
            'https://api.payment-gateway.com/charge' => Http::response([
                'success' => true,
                'transaction_id' => 'TXN-12345',
                'status' => 'completed',
            ], 200)
        ]);

        $paymentGateway = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for this test

            protected function callService(mixed $data, Context $context): mixed
            {
                return $this->post('https://api.payment-gateway.com/charge', [
                    'amount' => $data['amount'],
                    'currency' => $data['currency'],
                    'payment_method' => $data['payment_method'],
                    'reference_id' => $data['reference_id'],
                ]);
            }

            protected function getServiceName(): string
            {
                return 'payment_gateway';
            }

            protected function getFallbackResponse(mixed $data, Context $context): mixed
            {
                return [
                    'success' => false,
                    'error' => 'Gateway unavailable',
                    'fallback_used' => true,
                ];
            }
        };

        // Create payment processing pipeline
        $pipeline = Pipeline::create()
            ->pipe($paymentValidator)
            ->validate(function (Context $context) {
                return !$context->hasErrors() && $context->payload['amount'] > 0;
            }, 'Invalid payment data')
            ->pipe($paymentGateway)
            ->tap(function (Context $context) {
                // Log successful payment
                if (isset($context->payload['success']) && $context->payload['success']) {
                    logger()->info('Payment processed successfully', [
                        'transaction_id' => $context->payload['transaction_id'],
                        'correlation_id' => $context->correlationId,
                    ]);
                }
            });

        $result = $pipeline->process($context);

        // Assertions
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->getMetadata('payment_validated'));
        $this->assertTrue($result->payload['success']);
        $this->assertEquals('TXN-12345', $result->payload['transaction_id']);
        $this->assertTrue($result->hasTag('realtime'));
        $this->assertTrue($result->hasTag('payment_processing'));
    }

    public function test_user_registration_with_comprehensive_validation(): void
    {
        $userData = [
            'name' => 'Alice Johnson',
            'email' => 'alice@example.com',
            'password' => 'SecurePassword123!',
            'password_confirmation' => 'SecurePassword123!',
            'phone' => '+1-555-0123',
            'terms_accepted' => true,
        ];

        $context = ContextBuilder::for($userData)
            ->withMetadata('client_ip', '203.0.113.45')
            ->withMetadata('user_agent', 'Mozilla/5.0 Registration Form')
            ->withTag('user_registration')
            ->asBatch() // Background processing for registration
            ->build();

        // Create user validation agent
        $userValidator = new class extends BaseAgent {
            use ValidatesUser;

            protected function handle(Context $context): Context
            {
                $validation = $this->validateUserRegistration($context);
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                $contactValidation = $this->validateUserContactInfo($context->payload);
                if (!$contactValidation->valid) {
                    return $context->addErrors($contactValidation->getAllErrors());
                }

                $passwordValidation = $this->validatePasswordStrength($context->payload['password']);
                if (!$passwordValidation->valid) {
                    return $context->addErrors($passwordValidation->getAllErrors());
                }

                return $context->withMetadata('user_validated', true);
            }
        };

        // Mock email verification service
        $emailService = new class extends ExternalServiceAgent {
            protected function callService(mixed $data, Context $context): mixed
            {
                // Simulate email verification API
                return [
                    'email_sent' => true,
                    'verification_token' => 'TOKEN-' . uniqid(),
                    'expires_at' => now()->addHours(24)->toISOString(),
                ];
            }

            protected function getServiceName(): string
            {
                return 'email_service';
            }

            protected function getFallbackResponse(mixed $data, Context $context): mixed
            {
                return [
                    'email_sent' => false,
                    'fallback' => 'Email will be sent manually',
                ];
            }
        };

        // Create registration pipeline
        $pipeline = Pipeline::create()
            ->pipe($userValidator)
            ->validate(function (Context $context) {
                return !$context->hasErrors();
            }, 'User validation failed')
            ->pipe(function (mixed $payload, Context $context) {
                // Hash password (simulate)
                $userData = $payload;
                $userData['password'] = 'HASHED_' . $userData['password'];
                unset($userData['password_confirmation']);
                return $context->with($userData);
            })
            ->pipe($emailService)
            ->pipe(function (mixed $payload, Context $context) {
                // Create user record (simulate)
                $userRecord = [
                    'id' => rand(1000, 9999),
                    'name' => $payload['name'],
                    'email' => $payload['email'],
                    'email_verified' => false,
                    'created_at' => now()->toISOString(),
                ];
                
                return $context->with($userRecord);
            })
            ->logContext('info', 'User registration completed');

        $result = $pipeline->process($context);

        // Debug errors
        if ($result->hasErrors()) {
            dump('test_user_registration errors:', $result->errors);
        }

        // Assertions
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->getMetadata('user_validated'));
        $this->assertTrue($result->getMetadata('external_service_success'));
        $this->assertIsInt($result->payload['id']);
        $this->assertEquals('alice@example.com', $result->payload['email']);
        $this->assertFalse($result->payload['email_verified']);
        $this->assertTrue($result->hasTag('batch_processing'));
        $this->assertTrue($result->hasTag('user_registration'));
    }

    public function test_error_handling_and_fallbacks_integration(): void
    {
        // Create context that will trigger failures
        $context = ContextBuilder::for(['invalid' => 'data'])
            ->withMetadata('test_failure_mode', true)
            ->withTag('error_test')
            ->build();

        // Agent that always fails
        $failingAgent = new class extends BaseAgent {
            protected function handle(Context $context): Context
            {
                throw new \Exception('Agent failure for testing');
            }
        };

        // External service that fails but has fallback
        $failingService = new class extends ExternalServiceAgent {
            protected bool $preservePayload = false; // Use legacy behavior for this test
            
            protected function callService(mixed $data, Context $context): mixed
            {
                throw new \Exception('Service unavailable');
            }

            protected function getServiceName(): string
            {
                return 'failing_test_service';
            }

            protected function getFallbackResponse(mixed $data, Context $context): mixed
            {
                return ['fallback_data' => 'Service unavailable, using fallback'];
            }
        };

        // Pipeline with error handling
        $pipeline = Pipeline::create()
            ->pipe($failingAgent) // This will add error to context
            ->validate(function (Context $context) {
                // Continue processing even with errors for testing
                return true;
            })
            ->pipe($failingService) // This will use fallback
            ->tap(function (Context $context) {
                // Verify we have both agent errors and fallback data
                $this->assertTrue($context->hasErrors());
                $this->assertEquals('Service unavailable, using fallback', $context->payload['fallback_data']);
            })
            ->onError(function (Context $context, \Throwable $exception) {
                // Global error handler - preserve existing errors and add metadata
                return $context
                    ->addError($exception->getMessage())
                    ->withMetadata('global_error_handled', true);
            });

        $result = $pipeline->process($context);

        // Assertions
        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('Agent failure for testing', implode(' ', $result->errors));
        $this->assertTrue($result->getMetadata('fallback_used'));
        $this->assertEquals('Service unavailable, using fallback', $result->payload['fallback_data']);
        $this->assertEquals('failing_test_service', $result->getMetadata('service_name'));
    }

    public function test_performance_with_all_patterns_combined(): void
    {
        // Create a complex context using all ContextBuilder features
        $complexData = [
            'order_id' => 'PERF-001',
            'items' => array_fill(0, 100, ['id' => 'ITEM-X', 'quantity' => 1, 'price' => 10.00]),
            'user' => ['id' => 999, 'email' => 'perf@test.com'],
        ];

        $context = ContextBuilder::for($complexData)
            ->withMetadata('performance_test', true)
            ->withMetadata('item_count', 100)
            ->asHighPriority()
            ->withTags(['performance', 'bulk', 'test'])
            ->build();

        $startTime = microtime(true);

        // Create a pipeline with multiple agents and debugging
        $pipeline = Pipeline::create()
            ->tap(function () { /* Measure tap overhead */ })
            ->pipe(function (mixed $payload, Context $context) {
                // Simple processing agent
                return $context->withMetadata('processed', true);
            })
            ->dump('Performance Check')
            ->validate(function (Context $context) {
                return count($context->payload['items']) <= 100;
            }, 'Too many items')
            ->pipe(function (mixed $payload, Context $context) {
                // Simulate some processing time
                usleep(1000); // 1ms
                return $context->withMetadata('performance_processed', microtime(true));
            })
            ->ray('Performance Complete')
            ->logContext('debug', 'Performance test completed');

        $result = $pipeline->process($context);
        $executionTime = microtime(true) - $startTime;

        // Performance assertions
        $this->assertLessThan(0.1, $executionTime, 'Complex pipeline should execute in under 100ms');
        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->getMetadata('processed'));
        $this->assertTrue($result->getMetadata('performance_test'));
        $this->assertEquals(100, $result->getMetadata('item_count'));
        $this->assertTrue($result->hasTag('performance'));
    }

    public function test_real_world_ecommerce_checkout_scenario(): void
    {
        // Simulate a complete e-commerce checkout flow
        $checkoutData = [
            'cart' => [
                'items' => [
                    ['id' => 'PROD-1', 'name' => 'Laptop', 'price' => 999.99, 'quantity' => 1],
                    ['id' => 'PROD-2', 'name' => 'Mouse', 'price' => 29.99, 'quantity' => 2],
                ],
                'subtotal' => 1059.97,
                'tax' => 84.80,
                'shipping' => 15.00,
                'total' => 1159.77,
            ],
            'customer' => [
                'id' => 'CUST-123',
                'email' => 'customer@shop.com',
                'name' => 'Jane Customer',
            ],
            'payment' => [
                'method' => 'credit_card',
                'amount' => 1159.77,
                'currency' => 'USD',
                'card_last_four' => '1111',
            ],
            'shipping_address' => [
                'street' => '123 Main St',
                'city' => 'Anytown',
                'state' => 'CA',
                'zip' => '90210',
            ],
        ];

        $context = ContextBuilder::for($checkoutData)
            ->withMetadata('checkout_session_id', 'SESS-' . uniqid())
            ->withMetadata('client_ip', '198.51.100.42')
            ->asRealTime()
            ->withBusinessObject('checkout', 'CHK-' . uniqid())
            ->withTags(['ecommerce', 'checkout', 'high_value'])
            ->build();

        // Create comprehensive checkout pipeline
        $pipeline = Pipeline::create()
            // Step 1: Validate cart and pricing
            ->pipe(new class extends BaseAgent {
                use ValidatesOrder;
                
                protected function handle(Context $context): Context
                {
                    $cartValidation = $this->validateOrderAmounts($context->payload['cart']);
                    if (!$cartValidation->valid) {
                        return $context->addErrors($cartValidation->getAllErrors());
                    }
                    return $context->withMetadata('cart_validated', true);
                }
            })
            ->dump('Cart Validated')
            
            // Step 2: Check inventory  
            ->pipe(new class extends ExternalServiceAgent {
                protected bool $preservePayload = false; // Handle payload manually
                
                protected function callService(mixed $data, Context $context): mixed
                {
                    // Preserve original data and add inventory results
                    $inventoryResult = ['inventory_reserved' => true, 'reservation_id' => 'RES-' . uniqid()];
                    return array_merge($data, ['inventory_results' => $inventoryResult]);
                }
                protected function getServiceName(): string { return 'inventory_service'; }
            })
            
            // Step 3: Process payment
            ->pipe(new class extends ExternalServiceAgent {
                use ValidatesPayment;
                protected bool $preservePayload = false; // Handle payload manually
                
                protected function callService(mixed $data, Context $context): mixed
                {
                    $paymentValidation = $this->validatePaymentAmount($data['payment']);
                    if (!$paymentValidation->valid) {
                        throw new \Exception('Payment validation failed');
                    }
                    
                    // Preserve original data and add payment results
                    $paymentResult = [
                        'payment_successful' => true,
                        'transaction_id' => 'TXN-' . uniqid(),
                        'charged_amount' => $data['payment']['amount'],
                    ];
                    return array_merge($data, ['payment_results' => $paymentResult]);
                }
                protected function getServiceName(): string { return 'payment_processor'; }
            })
            ->ray('Payment Processed')
            
            // Step 4: Create order
            ->pipe(function (mixed $payload, Context $context) {
                $orderData = $payload;
                $orderData['order_id'] = 'ORD-' . uniqid();
                $orderData['status'] = 'confirmed';
                $orderData['created_at'] = now()->toISOString();
                return $context->with($orderData);
            })
            
            // Step 5: Send confirmation email
            ->pipe(new class extends ExternalServiceAgent {
                protected function callService(mixed $data, Context $context): mixed
                {
                    $emailResult = ['email_sent' => true, 'email_id' => 'EMAIL-' . uniqid()];
                    return array_merge($data, ['email_results' => $emailResult]);
                }
                protected function getServiceName(): string { return 'email_service'; }
                protected function getFallbackResponse(mixed $data, Context $context): mixed
                {
                    $emailResult = ['email_sent' => false, 'queued_for_retry' => true];
                    return array_merge($data, ['email_results' => $emailResult]);
                }
            })
            
            ->logContext('info', 'Checkout completed successfully')
            ->validate(function (Context $context) {
                return isset($context->payload['order_id']) && 
                       isset($context->payload['status']) && 
                       $context->payload['status'] === 'confirmed';
            }, 'Order creation failed');

        $result = $pipeline->process($context);

        // Comprehensive assertions
        $this->assertFalse($result->hasErrors(), 'Checkout should complete without errors');
        $this->assertNotEmpty($result->payload['order_id']);
        $this->assertEquals('confirmed', $result->payload['status']);
        $this->assertTrue($result->getMetadata('cart_validated'));
        $this->assertTrue($result->hasTag('ecommerce'));
        $this->assertTrue($result->hasTag('realtime'));
        
        // Verify all external services were called successfully
        $this->assertTrue($result->getMetadata('external_service_success'));
        
        // Verify the complex data structure is preserved
        $this->assertEquals(1159.77, $result->payload['cart']['total']);
        $this->assertEquals('customer@shop.com', $result->payload['customer']['email']);
        $this->assertArrayHasKey('transaction_id', $result->payload['payment_results']);
    }
}