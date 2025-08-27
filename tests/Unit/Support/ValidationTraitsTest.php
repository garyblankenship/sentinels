<?php

namespace Vampires\Sentinels\Tests\Unit\Support;

use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\ValidationResult;
use Vampires\Sentinels\Support\Validation\ValidatesOrder;
use Vampires\Sentinels\Support\Validation\ValidatesPayment;
use Vampires\Sentinels\Support\Validation\ValidatesUser;
use Vampires\Sentinels\Tests\TestCase;

class ValidationTraitsTest extends TestCase
{
    public function test_validates_order_trait(): void
    {
        $agent = new class extends BaseAgent {
            use ValidatesOrder;

            protected function handle(Context $context): Context
            {
                $validation = $this->validateOrder($context);
                
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                return $context;
            }
        };

        // Valid order
        $validOrder = [
            'id' => 'ORD-123',
            'status' => 'pending',
            'total' => 99.99,
            'customer_id' => 'CUST-456',
        ];

        $context = Context::create($validOrder);
        $result = $agent($context);

        $this->assertFalse($result->hasErrors());

        // Invalid order - missing required fields
        $invalidOrder = [
            'id' => 'ORD-123',
            'status' => 'pending',
            // missing total and customer_id
        ];

        $context = Context::create($invalidOrder);
        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $errors = $result->errors;
        $allErrors = implode(' ', $errors);
        $this->assertStringContainsString('total', $allErrors);
        $this->assertStringContainsString('customer', $allErrors); // Laravel formats 'customer_id' as 'customer id'
    }

    public function test_validates_order_status_transition(): void
    {
        $agent = new class extends BaseAgent {
            use ValidatesOrder;

            protected function handle(Context $context): Context
            {
                $validation = $this->validateOrderStatusTransition(
                    $context->payload['current_status'],
                    $context->payload['new_status']
                );
                
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                return $context;
            }
        };

        // Valid transition
        $validTransition = [
            'current_status' => 'pending',
            'new_status' => 'confirmed',
        ];

        $context = Context::create($validTransition);
        $result = $agent($context);

        $this->assertFalse($result->hasErrors());

        // Invalid transition
        $invalidTransition = [
            'current_status' => 'delivered',
            'new_status' => 'pending',
        ];

        $context = Context::create($invalidTransition);
        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('Cannot transition from \'delivered\' to \'pending\'', $result->errors[0]);
    }

    public function test_validates_order_amounts(): void
    {
        $agent = new class extends BaseAgent {
            use ValidatesOrder;

            protected function handle(Context $context): Context
            {
                $validation = $this->validateOrderAmounts($context->payload);
                
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                return $context;
            }
        };

        // Valid amounts
        $validAmounts = [
            'subtotal' => 100.00,
            'tax' => 10.00,
            'total' => 110.00,
            'discount' => 0.00,
        ];

        $context = Context::create($validAmounts);
        $result = $agent($context);

        $this->assertFalse($result->hasErrors());

        // Invalid amounts - total doesn't match
        $invalidAmounts = [
            'subtotal' => 100.00,
            'tax' => 10.00,
            'total' => 120.00, // Should be 110.00
            'discount' => 0.00,
        ];

        $context = Context::create($invalidAmounts);
        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('Order total (120) does not match calculated total (110)', $result->errors[0]);
    }

    public function test_validates_payment_trait(): void
    {
        $agent = new class extends BaseAgent {
            use ValidatesPayment;

            protected function handle(Context $context): Context
            {
                $validation = $this->validatePayment($context);
                
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                return $context;
            }
        };

        // Valid payment
        $validPayment = [
            'amount' => 99.99,
            'currency' => 'USD',
            'payment_method' => 'credit_card',
            'reference_id' => 'PAY-123456',
        ];

        $context = Context::create($validPayment);
        $result = $agent($context);

        $this->assertFalse($result->hasErrors());

        // Invalid payment - negative amount
        $invalidPayment = [
            'amount' => -99.99,
            'currency' => 'USD',
            'payment_method' => 'credit_card',
            'reference_id' => 'PAY-123456',
        ];

        $context = Context::create($invalidPayment);
        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $allErrors = implode(' ', $result->errors);
        $this->assertStringContainsString('amount', $allErrors);
    }

    public function test_validates_payment_amount(): void
    {
        $agent = new class extends BaseAgent {
            use ValidatesPayment;

            protected function handle(Context $context): Context
            {
                $validation = $this->validatePaymentAmount($context->payload);
                
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                return $context;
            }
        };

        // Valid payment amount
        $validPayment = [
            'amount' => 99.99,
            'currency' => 'USD',
        ];

        $context = Context::create($validPayment);
        $result = $agent($context);

        $this->assertFalse($result->hasErrors());

        // Invalid currency
        $invalidPayment = [
            'amount' => 99.99,
            'currency' => 'INVALID',
        ];

        $context = Context::create($invalidPayment);
        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('Currency \'INVALID\' is not supported', $result->errors[0]);
    }

    public function test_validates_card_data(): void
    {
        $agent = new class extends BaseAgent {
            use ValidatesPayment;

            protected function handle(Context $context): Context
            {
                $validation = $this->validateCardData($context->payload);
                
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                return $context;
            }
        };

        // Valid card (Visa test number)
        $validCard = [
            'card_number' => '4111111111111111',
            'expiry_month' => 12,
            'expiry_year' => date('Y') + 1, // Next year
            'cvv' => '123',
        ];

        $context = Context::create($validCard);
        $result = $agent($context);

        $this->assertFalse($result->hasErrors());

        // Invalid card number
        $invalidCard = [
            'card_number' => '1234567890123456', // Invalid Luhn
            'expiry_month' => 12,
            'expiry_year' => date('Y') + 1,
            'cvv' => '123',
        ];

        $context = Context::create($invalidCard);
        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $this->assertContains('Invalid credit card number', $result->errors);
    }

    public function test_validates_user_trait(): void
    {
        $agent = new class extends BaseAgent {
            use ValidatesUser;

            protected function handle(Context $context): Context
            {
                $validation = $this->validateUser($context);
                
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                return $context;
            }
        };

        // Valid user
        $validUser = [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ];

        $context = Context::create($validUser);
        $result = $agent($context);

        $this->assertFalse($result->hasErrors());

        // Invalid user - bad email
        $invalidUser = [
            'email' => 'not-an-email',
            'name' => 'Test User',
        ];

        $context = Context::create($invalidUser);
        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $allErrors = implode(' ', $result->errors);
        $this->assertStringContainsString('email', $allErrors);
    }

    public function test_validates_user_registration(): void
    {
        $agent = new class extends BaseAgent {
            use ValidatesUser;

            protected function handle(Context $context): Context
            {
                $validation = $this->validateUserRegistration($context);
                
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                return $context;
            }
        };

        // Valid registration
        $validRegistration = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'SecurePassword123',
            'password_confirmation' => 'SecurePassword123',
            'terms_accepted' => true,
        ];

        $context = Context::create($validRegistration);
        $result = $agent($context);

        $this->assertFalse($result->hasErrors());

        // Invalid registration - password too short
        $invalidRegistration = [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => '123',
            'password_confirmation' => '123',
            'terms_accepted' => true,
        ];

        $context = Context::create($invalidRegistration);
        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $allErrors = implode(' ', $result->errors);
        $this->assertStringContainsString('password', $allErrors);
        $this->assertStringContainsString('8 characters', $allErrors);
    }

    public function test_validates_user_permissions(): void
    {
        $agent = new class extends BaseAgent {
            use ValidatesUser;

            protected function handle(Context $context): Context
            {
                $validation = $this->validateUserPermissions($context, ['edit_orders'], ['admin']);
                
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                return $context;
            }
        };

        // Valid permissions
        $context = Context::create(['action' => 'edit_order'])
            ->withTag('authenticated')
            ->withMetadata('user_permissions', ['edit_orders', 'view_orders'])
            ->withMetadata('user_roles', ['admin', 'manager']);

        $result = $agent($context);

        $this->assertFalse($result->hasErrors());

        // Invalid permissions - missing permission
        $context = Context::create(['action' => 'edit_order'])
            ->withTag('authenticated')
            ->withMetadata('user_permissions', ['view_orders']) // Missing edit_orders
            ->withMetadata('user_roles', ['admin']);

        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('Missing required permission: edit_orders', $result->errors[0]);
    }

    public function test_validates_password_strength(): void
    {
        $agent = new class extends BaseAgent {
            use ValidatesUser;

            protected function handle(Context $context): Context
            {
                $validation = $this->validatePasswordStrength($context->payload['password']);
                
                if (!$validation->valid) {
                    return $context->addErrors($validation->getAllErrors());
                }

                return $context;
            }
        };

        // Strong password
        $context = Context::create(['password' => 'MySecureP@ssw0rd123']);
        $result = $agent($context);

        $this->assertFalse($result->hasErrors());

        // Weak password
        $context = Context::create(['password' => 'password']);
        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $errors = implode(' ', $result->errors);
        $this->assertStringContainsString('uppercase letter', $errors);
        $this->assertStringContainsString('number', $errors);
        $this->assertStringContainsString('too common or weak', $errors);
    }
}