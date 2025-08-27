<?php

namespace Vampires\Sentinels\Support\Validation;

use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\ValidationResult;

/**
 * Validation trait for payment-related payloads.
 *
 * Provides validation patterns for payment processing,
 * including amount validation, payment method checks, and security validations.
 */
trait ValidatesPayment
{
    /**
     * Validate payment payload with common payment business rules.
     *
     * @param Context $context The context containing payment data
     * @param array<string, mixed> $additionalRules Additional validation rules
     */
    protected function validatePayment(Context $context, array $additionalRules = []): ValidationResult
    {
        $payload = $context->payload;
        $paymentData = $this->extractPaymentData($payload);

        if ($paymentData === null) {
            return ValidationResult::invalid(['payment' => ['Invalid payment data structure']]);
        }

        $rules = array_merge([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'size:3'],
            'payment_method' => ['required', 'string'],
            'reference_id' => ['required', 'string'],
        ], $additionalRules);

        return $this->validateWithRules($paymentData, $rules);
    }

    /**
     * Validate payment amount and currency consistency.
     *
     * @param array<string, mixed> $paymentData The payment data
     * @param array<string> $allowedCurrencies Supported currency codes
     * @param array<string, array<string, mixed>> $currencyLimits Min/max amounts per currency
     */
    protected function validatePaymentAmount(
        array $paymentData,
        array $allowedCurrencies = ['USD', 'EUR', 'GBP'],
        array $currencyLimits = []
    ): ValidationResult {
        $errors = [];

        if (!isset($paymentData['amount']) || !is_numeric($paymentData['amount'])) {
            $errors['amount'] = ['Payment amount must be a valid number'];
            return ValidationResult::invalid($errors);
        }

        if (!isset($paymentData['currency'])) {
            $errors['currency'] = ['Payment currency is required'];
            return ValidationResult::invalid($errors);
        }

        $amount = (float) $paymentData['amount'];
        $currency = strtoupper($paymentData['currency']);

        // Validate currency
        if (!in_array($currency, $allowedCurrencies, true)) {
            $errors['currency'] = [
                "Currency '{$currency}' is not supported. " .
                'Allowed currencies: ' . implode(', ', $allowedCurrencies)
            ];
        }

        // Validate amount range
        if ($amount <= 0) {
            $errors['amount'] = ['Payment amount must be greater than zero'];
        }

        // Check currency-specific limits
        if (isset($currencyLimits[$currency])) {
            $limits = $currencyLimits[$currency];
            
            if (isset($limits['min']) && $amount < $limits['min']) {
                $errors['amount'] = ["Minimum payment amount for {$currency} is {$limits['min']}"];
            }
            
            if (isset($limits['max']) && $amount > $limits['max']) {
                $errors['amount'] = ["Maximum payment amount for {$currency} is {$limits['max']}"];
            }
        }

        // Validate decimal precision for different currencies
        $decimalPlaces = $this->getDecimalPlacesForCurrency($currency);
        $amountStr = (string) $amount;
        if (str_contains($amountStr, '.')) {
            $actualDecimals = strlen(substr($amountStr, strpos($amountStr, '.') + 1));
            if ($actualDecimals > $decimalPlaces) {
                $errors['amount'] = [
                    "Currency {$currency} supports maximum {$decimalPlaces} decimal places"
                ];
            }
        }

        return empty($errors) 
            ? ValidationResult::valid($paymentData)
            : ValidationResult::invalid($errors);
    }

    /**
     * Validate payment method and associated data.
     *
     * @param array<string, mixed> $paymentData The payment data
     * @param array<string, array<string>> $methodRequiredFields Required fields per payment method
     */
    protected function validatePaymentMethod(
        array $paymentData,
        array $methodRequiredFields = []
    ): ValidationResult {
        if (!isset($paymentData['payment_method'])) {
            return ValidationResult::invalid([
                'payment_method' => ['Payment method is required']
            ]);
        }

        $method = $paymentData['payment_method'];
        $errors = [];

        // Default required fields for common payment methods
        $defaultRequiredFields = [
            'credit_card' => ['card_number', 'expiry_month', 'expiry_year', 'cvv'],
            'debit_card' => ['card_number', 'expiry_month', 'expiry_year', 'cvv'],
            'bank_transfer' => ['bank_account_number', 'routing_number'],
            'paypal' => ['paypal_email'],
            'stripe' => ['stripe_token'],
            'square' => ['square_nonce'],
        ];

        $requiredFields = array_merge($defaultRequiredFields, $methodRequiredFields);

        if (isset($requiredFields[$method])) {
            foreach ($requiredFields[$method] as $field) {
                if (!isset($paymentData[$field]) || empty($paymentData[$field])) {
                    $errors[$field] = ["The {$field} field is required for {$method} payments"];
                }
            }
        }

        // Method-specific validations
        switch ($method) {
            case 'credit_card':
            case 'debit_card':
                $cardValidation = $this->validateCardData($paymentData);
                if (!$cardValidation->valid) {
                    $errors = array_merge($errors, $cardValidation->errors);
                }
                break;

            case 'bank_transfer':
                $bankValidation = $this->validateBankData($paymentData);
                if (!$bankValidation->valid) {
                    $errors = array_merge($errors, $bankValidation->errors);
                }
                break;
        }

        return empty($errors) 
            ? ValidationResult::valid($paymentData)
            : ValidationResult::invalid($errors);
    }

    /**
     * Validate credit/debit card data.
     *
     * @param array<string, mixed> $paymentData Payment data containing card information
     */
    protected function validateCardData(array $paymentData): ValidationResult
    {
        $errors = [];

        // Validate card number (basic Luhn check)
        if (isset($paymentData['card_number'])) {
            $cardNumber = preg_replace('/\s+/', '', $paymentData['card_number']);
            if (!$this->isValidCardNumber($cardNumber)) {
                $errors['card_number'] = ['Invalid credit card number'];
            }
        }

        // Validate expiry date
        if (isset($paymentData['expiry_month'], $paymentData['expiry_year'])) {
            $month = (int) $paymentData['expiry_month'];
            $year = (int) $paymentData['expiry_year'];
            
            if ($month < 1 || $month > 12) {
                $errors['expiry_month'] = ['Expiry month must be between 1 and 12'];
            }

            // Convert 2-digit year to 4-digit
            if ($year < 100) {
                $year += 2000;
            }

            $expiryDate = mktime(0, 0, 0, $month, 1, $year);
            $lastDayOfMonth = mktime(0, 0, 0, $month + 1, 0, $year);

            if ($lastDayOfMonth < time()) {
                $errors['expiry_date'] = ['Card has expired'];
            }
        }

        // Validate CVV
        if (isset($paymentData['cvv'])) {
            $cvv = $paymentData['cvv'];
            if (!preg_match('/^\d{3,4}$/', $cvv)) {
                $errors['cvv'] = ['CVV must be 3 or 4 digits'];
            }
        }

        return empty($errors) 
            ? ValidationResult::valid($paymentData)
            : ValidationResult::invalid($errors);
    }

    /**
     * Validate bank transfer data.
     *
     * @param array<string, mixed> $paymentData Payment data containing bank information
     */
    protected function validateBankData(array $paymentData): ValidationResult
    {
        $errors = [];

        // Validate bank account number
        if (isset($paymentData['bank_account_number'])) {
            $accountNumber = $paymentData['bank_account_number'];
            if (!preg_match('/^\d{8,17}$/', $accountNumber)) {
                $errors['bank_account_number'] = ['Bank account number must be 8-17 digits'];
            }
        }

        // Validate routing number (US format)
        if (isset($paymentData['routing_number'])) {
            $routingNumber = $paymentData['routing_number'];
            if (!preg_match('/^\d{9}$/', $routingNumber)) {
                $errors['routing_number'] = ['Routing number must be 9 digits'];
            } elseif (!$this->isValidRoutingNumber($routingNumber)) {
                $errors['routing_number'] = ['Invalid routing number'];
            }
        }

        return empty($errors) 
            ? ValidationResult::valid($paymentData)
            : ValidationResult::invalid($errors);
    }

    /**
     * Validate payment security context.
     *
     * @param Context $context The payment context
     */
    protected function validatePaymentSecurity(Context $context): ValidationResult
    {
        $errors = [];

        // Check for required security metadata
        $requiredSecurityFields = ['client_ip', 'user_agent'];
        foreach ($requiredSecurityFields as $field) {
            if (!$context->hasMetadata($field)) {
                $errors['security'] = $errors['security'] ?? [];
                $errors['security'][] = "Missing security metadata: {$field}";
            }
        }

        // Check for suspicious patterns
        if ($context->hasMetadata('client_ip')) {
            $ip = $context->getMetadata('client_ip');
            if ($this->isSuspiciousIP($ip)) {
                $errors['security'] = $errors['security'] ?? [];
                $errors['security'][] = 'Payment from suspicious IP address';
            }
        }

        // Check for required authentication
        if (!$context->hasTag('authenticated')) {
            $errors['authentication'] = ['Payment requires user authentication'];
        }

        return empty($errors) 
            ? ValidationResult::valid($context->payload)
            : ValidationResult::invalid($errors);
    }

    /**
     * Extract payment data from payload.
     *
     * @param mixed $payload The context payload
     * @return array<string, mixed>|null
     */
    private function extractPaymentData(mixed $payload): ?array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_object($payload)) {
            if (method_exists($payload, 'toArray')) {
                return $payload->toArray();
            }
            return json_decode(json_encode($payload), true);
        }

        return null;
    }

    /**
     * Validate credit card number using Luhn algorithm.
     *
     * @param string $cardNumber The card number to validate
     */
    private function isValidCardNumber(string $cardNumber): bool
    {
        $sum = 0;
        $alternate = false;

        for ($i = strlen($cardNumber) - 1; $i >= 0; $i--) {
            $digit = (int) $cardNumber[$i];

            if ($alternate) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit = 1 + ($digit % 10);
                }
            }

            $sum += $digit;
            $alternate = !$alternate;
        }

        return ($sum % 10) === 0;
    }

    /**
     * Validate routing number using check digit algorithm.
     *
     * @param string $routingNumber The routing number to validate
     */
    private function isValidRoutingNumber(string $routingNumber): bool
    {
        $checkDigit = (int) $routingNumber[8];
        $sum = 3 * ((int) $routingNumber[0] + (int) $routingNumber[3] + (int) $routingNumber[6]) +
               7 * ((int) $routingNumber[1] + (int) $routingNumber[4] + (int) $routingNumber[7]) +
               1 * ((int) $routingNumber[2] + (int) $routingNumber[5]);

        return ($sum % 10) === $checkDigit;
    }

    /**
     * Get decimal places for currency.
     *
     * @param string $currency The currency code
     */
    private function getDecimalPlacesForCurrency(string $currency): int
    {
        $noDecimalCurrencies = ['JPY', 'KRW', 'VND', 'CLP', 'ISK'];
        $threeDecimalCurrencies = ['BHD', 'KWD', 'OMR'];

        if (in_array($currency, $noDecimalCurrencies, true)) {
            return 0;
        }

        if (in_array($currency, $threeDecimalCurrencies, true)) {
            return 3;
        }

        return 2; // Default for most currencies
    }

    /**
     * Check if IP address is suspicious.
     *
     * @param string $ip The IP address to check
     */
    private function isSuspiciousIP(string $ip): bool
    {
        // Basic checks - in production, integrate with fraud detection services
        $suspiciousRanges = [
            '10.0.0.0/8',     // Private ranges shouldn't be payment sources
            '172.16.0.0/12',
            '192.168.0.0/16',
            '127.0.0.0/8',    // Localhost
        ];

        foreach ($suspiciousRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP is in CIDR range.
     *
     * @param string $ip The IP address
     * @param string $range The CIDR range
     */
    private function ipInRange(string $ip, string $range): bool
    {
        [$range, $netmask] = explode('/', $range, 2);
        $rangeDecimal = ip2long($range);
        $ipDecimal = ip2long($ip);
        $wildcardDecimal = pow(2, (32 - $netmask)) - 1;
        $netmaskDecimal = ~$wildcardDecimal;

        return ($ipDecimal & $netmaskDecimal) === ($rangeDecimal & $netmaskDecimal);
    }
}