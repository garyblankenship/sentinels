<?php

namespace Vampires\Sentinels\Support\Validation;

use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\ValidationResult;

/**
 * Validation trait for order-related payloads.
 *
 * Provides common validation patterns for order processing,
 * including status checks, amount validation, and required fields.
 */
trait ValidatesOrder
{
    /**
     * Validate order payload with common order business rules.
     *
     * @param Context $context The context containing the order data
     * @param array<string, mixed> $additionalRules Additional validation rules
     */
    protected function validateOrder(Context $context, array $additionalRules = []): ValidationResult
    {
        $payload = $context->payload;

        // Handle different payload structures
        $orderData = $this->extractOrderData($payload);
        
        if ($orderData === null) {
            return ValidationResult::invalid(['order' => ['Invalid order data structure']]);
        }

        $rules = array_merge([
            'id' => ['required'],
            'status' => ['required', 'string'],
            'total' => ['required', 'numeric', 'min:0'],
            'customer_id' => ['required'],
        ], $additionalRules);

        return $this->validateWithRules($orderData, $rules);
    }

    /**
     * Validate order status transitions.
     *
     * Ensures that status changes follow business rules.
     *
     * @param string $currentStatus The current order status
     * @param string $newStatus The desired new status
     * @param array<string, array<string>> $allowedTransitions Custom transition rules
     */
    protected function validateOrderStatusTransition(
        string $currentStatus, 
        string $newStatus,
        array $allowedTransitions = []
    ): ValidationResult {
        $defaultTransitions = [
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['processing', 'cancelled'],
            'processing' => ['shipped', 'cancelled'],
            'shipped' => ['delivered', 'returned'],
            'delivered' => ['returned'],
            'cancelled' => [], // Terminal state
            'returned' => [], // Terminal state
        ];

        $transitions = array_merge($defaultTransitions, $allowedTransitions);
        $allowedStatuses = $transitions[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowedStatuses, true)) {
            return ValidationResult::invalid([
                'status_transition' => [
                    "Cannot transition from '{$currentStatus}' to '{$newStatus}'. " .
                    'Allowed transitions: ' . implode(', ', $allowedStatuses)
                ]
            ]);
        }

        return ValidationResult::valid(['current_status' => $currentStatus, 'new_status' => $newStatus]);
    }

    /**
     * Validate order amounts and financial calculations.
     *
     * Ensures that order totals, taxes, and discounts are mathematically correct.
     *
     * @param array<string, mixed> $orderData The order data
     * @param bool $strictMode Whether to enforce strict decimal precision
     */
    protected function validateOrderAmounts(array $orderData, bool $strictMode = false): ValidationResult
    {
        $errors = [];

        // Validate required amount fields
        $requiredFields = ['subtotal', 'tax', 'total'];
        foreach ($requiredFields as $field) {
            if (!isset($orderData[$field])) {
                $errors[$field] = ["The {$field} field is required"];
                continue;
            }

            if (!is_numeric($orderData[$field])) {
                $errors[$field] = ["The {$field} must be a valid number"];
                continue;
            }

            if ($orderData[$field] < 0) {
                $errors[$field] = ["The {$field} cannot be negative"];
            }
        }

        if (!empty($errors)) {
            return ValidationResult::invalid($errors);
        }

        // Validate mathematical consistency
        $subtotal = (float) $orderData['subtotal'];
        $tax = (float) $orderData['tax'];
        $total = (float) $orderData['total'];
        $discount = (float) ($orderData['discount'] ?? 0);
        $shipping = (float) ($orderData['shipping'] ?? 0);

        $expectedTotal = $subtotal + $tax + $shipping - $discount;
        $tolerance = $strictMode ? 0.001 : 0.01; // 0.1 cent vs 1 cent tolerance

        if (abs($total - $expectedTotal) > $tolerance) {
            $errors['total'] = [
                "Order total ({$total}) does not match calculated total ({$expectedTotal}). " .
                "Subtotal: {$subtotal}, Tax: {$tax}, Shipping: {$shipping}, Discount: {$discount}"
            ];
        }

        return empty($errors) 
            ? ValidationResult::valid($orderData) 
            : ValidationResult::invalid($errors);
    }

    /**
     * Validate order items structure and business rules.
     *
     * @param array<array<string, mixed>> $items The order items
     */
    protected function validateOrderItems(array $items): ValidationResult
    {
        if (empty($items)) {
            return ValidationResult::invalid(['items' => ['Order must contain at least one item']]);
        }

        $errors = [];

        foreach ($items as $index => $item) {
            $itemErrors = [];

            // Required fields
            $requiredFields = ['id', 'quantity', 'price'];
            foreach ($requiredFields as $field) {
                if (!isset($item[$field])) {
                    $itemErrors[$field] = ["Item {$field} is required"];
                }
            }

            // Validate quantity
            if (isset($item['quantity'])) {
                if (!is_numeric($item['quantity']) || $item['quantity'] <= 0) {
                    $itemErrors['quantity'] = ['Item quantity must be a positive number'];
                }
            }

            // Validate price
            if (isset($item['price'])) {
                if (!is_numeric($item['price']) || $item['price'] < 0) {
                    $itemErrors['price'] = ['Item price must be a non-negative number'];
                }
            }

            // Validate line total if present
            if (isset($item['line_total'], $item['quantity'], $item['price'])) {
                $expectedLineTotal = (float) $item['quantity'] * (float) $item['price'];
                $actualLineTotal = (float) $item['line_total'];
                
                if (abs($actualLineTotal - $expectedLineTotal) > 0.01) {
                    $itemErrors['line_total'] = [
                        "Line total ({$actualLineTotal}) does not match quantity × price ({$expectedLineTotal})"
                    ];
                }
            }

            if (!empty($itemErrors)) {
                $errors["item_{$index}"] = $itemErrors;
            }
        }

        return empty($errors) 
            ? ValidationResult::valid($items) 
            : ValidationResult::invalid($errors);
    }

    /**
     * Check if order can be processed based on current context.
     *
     * @param Context $context The processing context
     * @param array<string> $requiredTags Tags that must be present
     * @param array<string> $prohibitedTags Tags that must not be present
     */
    protected function validateOrderProcessingContext(
        Context $context,
        array $requiredTags = [],
        array $prohibitedTags = ['cancelled', 'invalid']
    ): ValidationResult {
        $errors = [];

        // Check for prohibited tags
        foreach ($prohibitedTags as $tag) {
            if ($context->hasTag($tag)) {
                $errors['context'] = ["Order processing cannot proceed with '{$tag}' tag"];
                break;
            }
        }

        // Check for required tags
        foreach ($requiredTags as $tag) {
            if (!$context->hasTag($tag)) {
                $errors['context'] = $errors['context'] ?? [];
                $errors['context'][] = "Order processing requires '{$tag}' tag";
            }
        }

        // Check for existing errors
        if ($context->hasErrors()) {
            $errors['context'] = $errors['context'] ?? [];
            $errors['context'][] = 'Order processing cannot proceed with existing context errors';
        }

        return empty($errors) 
            ? ValidationResult::valid($context->payload) 
            : ValidationResult::invalid($errors);
    }

    /**
     * Extract order data from various payload structures.
     *
     * Handles different ways orders might be structured in the payload.
     *
     * @param mixed $payload The context payload
     * @return array<string, mixed>|null The extracted order data or null if invalid
     */
    private function extractOrderData(mixed $payload): ?array
    {
        if (is_array($payload)) {
            // Direct array payload
            return $payload;
        }

        if (is_object($payload)) {
            // Object payload - try to convert to array
            if (method_exists($payload, 'toArray')) {
                return $payload->toArray();
            }

            if (method_exists($payload, 'attributesToArray')) {
                return $payload->attributesToArray();
            }

            // Generic object to array conversion
            return json_decode(json_encode($payload), true);
        }

        return null;
    }
}