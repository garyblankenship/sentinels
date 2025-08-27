<?php

namespace Vampires\Sentinels\Core;

use Illuminate\Validation\Validator;

/**
 * Immutable result of context validation.
 *
 * Contains validation status, error messages, and sanitized input
 * for use by agents and the pipeline system.
 */
readonly class ValidationResult
{
    /**
     * Create a new ValidationResult.
     *
     * @param bool $valid Whether the validation passed
     * @param array<string, array<string>> $errors Validation errors grouped by field
     * @param mixed $sanitizedInput The cleaned/sanitized input
     * @param array<string, mixed> $metadata Additional validation metadata
     */
    public function __construct(
        public bool $valid,
        public array $errors = [],
        public mixed $sanitizedInput = null,
        public array $metadata = [],
    ) {
    }

    /**
     * Create a successful validation result.
     */
    public static function valid(mixed $sanitizedInput = null, array $metadata = []): self
    {
        return new self(
            valid: true,
            errors: [],
            sanitizedInput: $sanitizedInput,
            metadata: $metadata,
        );
    }

    /**
     * Create a failed validation result.
     *
     * @param array<string, array<string>|string> $errors
     */
    public static function invalid(array $errors, mixed $sanitizedInput = null, array $metadata = []): self
    {
        // Normalize errors to be arrays of strings
        $normalizedErrors = [];
        foreach ($errors as $field => $fieldErrors) {
            $normalizedErrors[$field] = is_array($fieldErrors) ? $fieldErrors : [$fieldErrors];
        }

        return new self(
            valid: false,
            errors: $normalizedErrors,
            sanitizedInput: $sanitizedInput,
            metadata: $metadata,
        );
    }

    /**
     * Create a validation result from a Laravel Validator.
     */
    public static function fromValidator(Validator $validator): self
    {
        if ($validator->passes()) {
            return self::valid(
                sanitizedInput: $validator->validated(),
                metadata: ['validator_used' => true]
            );
        }

        return self::invalid(
            errors: $validator->errors()->toArray(),
            metadata: ['validator_used' => true]
        );
    }

    /**
     * Create a validation result for a required field that's missing.
     */
    public static function requiredFieldMissing(string $field): self
    {
        return self::invalid([
            $field => ["The {$field} field is required."],
        ]);
    }

    /**
     * Create a validation result for invalid type.
     */
    public static function invalidType(string $field, string $expected, string $actual): self
    {
        return self::invalid([
            $field => ["The {$field} must be of type {$expected}, {$actual} given."],
        ]);
    }

    /**
     * Create a validation result for value out of range.
     */
    public static function outOfRange(string $field, mixed $min = null, mixed $max = null): self
    {
        $message = "The {$field} value is out of range.";

        if ($min !== null && $max !== null) {
            $message = "The {$field} must be between {$min} and {$max}.";
        } elseif ($min !== null) {
            $message = "The {$field} must be at least {$min}.";
        } elseif ($max !== null) {
            $message = "The {$field} must not be greater than {$max}.";
        }

        return self::invalid([
            $field => [$message],
        ]);
    }

    /**
     * Check if the validation failed.
     */
    public function failed(): bool
    {
        return !$this->valid;
    }

    /**
     * Check if there are any errors.
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Check if a specific field has errors.
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]) && !empty($this->errors[$field]);
    }

    /**
     * Get errors for a specific field.
     *
     * @return array<string>
     */
    public function getErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Get the first error for a specific field.
     */
    public function getFirstError(string $field): ?string
    {
        $errors = $this->getErrors($field);

        return empty($errors) ? null : $errors[0];
    }

    /**
     * Get all error messages as a flat array.
     *
     * @return array<string>
     */
    public function getAllErrors(): array
    {
        $allErrors = [];
        foreach ($this->errors as $fieldErrors) {
            $allErrors = array_merge($allErrors, $fieldErrors);
        }

        return $allErrors;
    }

    /**
     * Get all error messages as a single string.
     */
    public function getErrorString(string $separator = '; '): string
    {
        return implode($separator, $this->getAllErrors());
    }

    /**
     * Get the number of fields with errors.
     */
    public function getErrorCount(): int
    {
        return count($this->errors);
    }

    /**
     * Get the total number of error messages.
     */
    public function getTotalErrorCount(): int
    {
        return array_sum(array_map('count', $this->errors));
    }

    /**
     * Get the fields that have errors.
     *
     * @return array<string>
     */
    public function getErrorFields(): array
    {
        return array_keys($this->errors);
    }

    /**
     * Get a metadata value by key.
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Check if metadata key exists.
     */
    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    /**
     * Merge this validation result with another.
     */
    public function merge(ValidationResult $other): self
    {
        $mergedErrors = $this->errors;

        foreach ($other->errors as $field => $fieldErrors) {
            if (isset($mergedErrors[$field])) {
                $mergedErrors[$field] = array_merge($mergedErrors[$field], $fieldErrors);
            } else {
                $mergedErrors[$field] = $fieldErrors;
            }
        }

        return new self(
            valid: $this->valid && $other->valid,
            errors: $mergedErrors,
            sanitizedInput: $other->sanitizedInput ?? $this->sanitizedInput,
            metadata: array_merge($this->metadata, $other->metadata),
        );
    }

    /**
     * Convert the result to an array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => $this->errors,
            'sanitized_input' => $this->sanitizedInput,
            'metadata' => $this->metadata,
            'error_count' => $this->getErrorCount(),
            'total_error_count' => $this->getTotalErrorCount(),
            'error_fields' => $this->getErrorFields(),
        ];
    }

    /**
     * Create a validation result from an array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            valid: $data['valid'],
            errors: $data['errors'] ?? [],
            sanitizedInput: $data['sanitized_input'] ?? null,
            metadata: $data['metadata'] ?? [],
        );
    }
}
