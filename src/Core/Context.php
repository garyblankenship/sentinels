<?php

namespace Vampires\Sentinels\Core;

use Illuminate\Support\Str;

/**
 * Immutable Context object that carries data and metadata through agent pipelines.
 *
 * This class provides a clean, fluent API for creating new contexts with modified
 * data while maintaining immutability. All mutations return new instances.
 */
readonly class Context
{
    public readonly string $correlationId;
    public readonly float $startTime;

    /**
     * Create a new Context instance.
     *
     * @param mixed $payload The primary data being processed
     * @param array<string, mixed> $metadata Additional metadata
     * @param string|null $correlationId Unique identifier for tracing this context
     * @param array<string> $tags Categorization tags
     * @param string|null $traceId Distributed tracing identifier
     * @param bool $cancelled Whether this context has been cancelled
     * @param array<string> $errors Accumulated error messages
     * @param float|null $startTime When this context was created (microtime)
     */
    public function __construct(
        public mixed $payload = null,
        public array $metadata = [],
        ?string $correlationId = null,
        public array $tags = [],
        public ?string $traceId = null,
        public bool $cancelled = false,
        public array $errors = [],
        ?float $startTime = null,
    ) {
        // Generate correlation ID if not provided
        $this->correlationId = $correlationId ?? Str::uuid()->toString();
        $this->startTime = $startTime ?? microtime(true);
    }

    /**
     * Create a new context with the specified payload.
     */
    public function with(mixed $payload): self
    {
        return new self(
            payload: $payload,
            metadata: $this->metadata,
            correlationId: $this->correlationId,
            tags: $this->tags,
            traceId: $this->traceId,
            cancelled: $this->cancelled,
            errors: $this->errors,
            startTime: $this->startTime,
        );
    }

    /**
     * Create a new context with additional metadata.
     */
    public function withMetadata(string $key, mixed $value): self
    {
        $metadata = $this->metadata;
        $metadata[$key] = $value;

        return new self(
            payload: $this->payload,
            metadata: $metadata,
            correlationId: $this->correlationId,
            tags: $this->tags,
            traceId: $this->traceId,
            cancelled: $this->cancelled,
            errors: $this->errors,
            startTime: $this->startTime,
        );
    }

    /**
     * Create a new context with merged metadata.
     *
     * @param array<string, mixed> $metadata
     */
    public function withMergedMetadata(array $metadata): self
    {
        return new self(
            payload: $this->payload,
            metadata: array_merge($this->metadata, $metadata),
            correlationId: $this->correlationId,
            tags: $this->tags,
            traceId: $this->traceId,
            cancelled: $this->cancelled,
            errors: $this->errors,
            startTime: $this->startTime,
        );
    }

    /**
     * Create a new context with an additional tag.
     */
    public function withTag(string $tag): self
    {
        if (in_array($tag, $this->tags, true)) {
            return $this;
        }

        $tags = $this->tags;
        $tags[] = $tag;

        return new self(
            payload: $this->payload,
            metadata: $this->metadata,
            correlationId: $this->correlationId,
            tags: $tags,
            traceId: $this->traceId,
            cancelled: $this->cancelled,
            errors: $this->errors,
            startTime: $this->startTime,
        );
    }

    /**
     * Create a new context with multiple tags.
     *
     * @param array<string> $tags
     */
    public function withTags(array $tags): self
    {
        $allTags = array_unique(array_merge($this->tags, $tags));

        return new self(
            payload: $this->payload,
            metadata: $this->metadata,
            correlationId: $this->correlationId,
            tags: $allTags,
            traceId: $this->traceId,
            cancelled: $this->cancelled,
            errors: $this->errors,
            startTime: $this->startTime,
        );
    }

    /**
     * Create a new context with a trace ID.
     */
    public function withTraceId(string $traceId): self
    {
        return new self(
            payload: $this->payload,
            metadata: $this->metadata,
            correlationId: $this->correlationId,
            tags: $this->tags,
            traceId: $traceId,
            cancelled: $this->cancelled,
            errors: $this->errors,
            startTime: $this->startTime,
        );
    }

    /**
     * Create a new cancelled context.
     */
    public function cancel(): self
    {
        return new self(
            payload: $this->payload,
            metadata: $this->metadata,
            correlationId: $this->correlationId,
            tags: $this->tags,
            traceId: $this->traceId,
            cancelled: true,
            errors: $this->errors,
            startTime: $this->startTime,
        );
    }

    /**
     * Create a new context with an additional error.
     */
    public function addError(string $error): self
    {
        $errors = $this->errors;
        $errors[] = $error;

        return new self(
            payload: $this->payload,
            metadata: $this->metadata,
            correlationId: $this->correlationId,
            tags: $this->tags,
            traceId: $this->traceId,
            cancelled: $this->cancelled,
            errors: $errors,
            startTime: $this->startTime,
        );
    }

    /**
     * Create a new context with multiple errors.
     *
     * @param array<string> $errors
     */
    public function addErrors(array $errors): self
    {
        return new self(
            payload: $this->payload,
            metadata: $this->metadata,
            correlationId: $this->correlationId,
            tags: $this->tags,
            traceId: $this->traceId,
            cancelled: $this->cancelled,
            errors: array_merge($this->errors, $errors),
            startTime: $this->startTime,
        );
    }

    /**
     * Check if this context has been cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Check if this context has accumulated errors.
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Check if this context has a specific tag.
     */
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }

    /**
     * Get a metadata value by key.
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Check if a metadata key exists.
     */
    public function hasMetadata(string $key): bool
    {
        return array_key_exists($key, $this->metadata);
    }

    /**
     * Get the elapsed time since this context was created.
     */
    public function getElapsedTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    /**
     * Get the size of the payload in bytes (approximation).
     * 
     * Uses JSON encoding instead of unsafe serialize() for size calculation.
     */
    public function getPayloadSize(): int
    {
        try {
            // Try JSON encoding first
            $encoded = json_encode($this->payload, JSON_THROW_ON_ERROR);
            return strlen($encoded);
        } catch (\JsonException $e) {
            // Fallback to safe size estimation for non-JSON-serializable data
            return $this->estimatePayloadSize($this->payload);
        }
    }
    
    /**
     * Estimate payload size for complex data structures that can't be JSON encoded.
     */
    private function estimatePayloadSize(mixed $payload): int
    {
        if ($payload === null) {
            return 4; // "null"
        }
        
        if (is_bool($payload)) {
            return $payload ? 4 : 5; // "true" or "false"
        }
        
        if (is_numeric($payload)) {
            return strlen((string) $payload);
        }
        
        if (is_string($payload)) {
            return strlen($payload);
        }
        
        if (is_array($payload)) {
            $size = 2; // []
            foreach ($payload as $key => $value) {
                $size += strlen((string) $key) + 3; // key + "":
                $size += $this->estimatePayloadSize($value) + 1; // value + ,
            }
            return $size;
        }
        
        if (is_object($payload)) {
            // For objects, return a reasonable estimate based on class name
            return strlen(get_class($payload)) + 20; // class name + some overhead
        }
        
        return 50; // fallback for unknown types
    }

    /**
     * Check if the context payload is empty.
     */
    public function isEmpty(): bool
    {
        return $this->payload === null
            || $this->payload === ''
            || (is_array($this->payload) && empty($this->payload));
    }

    /**
     * Create a new Context from a simple value.
     */
    public static function create(mixed $payload = null): self
    {
        return new self(payload: $payload);
    }

    /**
     * Convert the context to an array for debugging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'payload' => $this->payload,
            'metadata' => $this->metadata,
            'correlationId' => $this->correlationId,
            'tags' => $this->tags,
            'traceId' => $this->traceId,
            'cancelled' => $this->cancelled,
            'errors' => $this->errors,
            'startTime' => $this->startTime,
            'elapsedTime' => $this->getElapsedTime(),
        ];
    }

    /**
     * Check if this context can be safely serialized for queue processing.
     *
     * This method validates that the payload and metadata can be serialized
     * and deserialized without data loss or security issues.
     */
    public function isSerializable(): bool
    {
        try {
            // Check for known non-serializable types first
            if ($this->payload instanceof \Closure || 
                $this->payload instanceof \PDO ||
                (is_object($this->payload) && method_exists($this->payload, '__sleep'))) {
                return false;
            }
            
            // Test JSON serialization of payload
            $payloadJson = json_encode($this->payload, JSON_THROW_ON_ERROR);
            $restoredPayload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
            
            // Test JSON serialization of metadata
            $metadataJson = json_encode($this->metadata, JSON_THROW_ON_ERROR);
            $restoredMetadata = json_decode($metadataJson, true, 512, JSON_THROW_ON_ERROR);
            
            return true;
        } catch (\JsonException $e) {
            return false;
        }
    }

    /**
     * Prepare this context for queue dispatch.
     *
     * This method ensures the context is in a safe state for serialization
     * and provides warnings for potential issues.
     *
     * @throws \InvalidArgumentException If context cannot be serialized
     */
    public function prepareForQueue(): self
    {
        if (!$this->isSerializable()) {
            throw new \InvalidArgumentException(
                'Context payload contains non-serializable data. ' .
                'For async pipeline execution, payloads must be JSON-serializable. ' .
                'Consider using IDs instead of objects, or switch to synchronous execution.'
            );
        }

        // Add metadata to indicate this context has been prepared for queue
        return $this->withMetadata('_prepared_for_queue', true)
                    ->withMetadata('_queue_prepared_at', microtime(true));
    }

    /**
     * Restore context after queue processing.
     *
     * This method can be extended to rehydrate relationships or 
     * restore objects from their serialized forms.
     */
    public function hydrateFromQueue(): self
    {
        // Remove queue preparation metadata and add hydration marker
        $metadata = $this->metadata;
        unset($metadata['_prepared_for_queue'], $metadata['_queue_prepared_at']);
        $metadata['_hydrated_from_queue'] = true;
        
        return new self(
            payload: $this->payload,
            metadata: $metadata,
            correlationId: $this->correlationId,
            tags: $this->tags,
            traceId: $this->traceId,
            cancelled: $this->cancelled,
            errors: $this->errors,
            startTime: $this->startTime,
        );
    }

    /**
     * Get payload serialization info for debugging.
     *
     * @return array Information about payload serializability
     */
    public function getSerializationInfo(): array
    {
        $info = [
            'is_serializable' => false,
            'payload_type' => gettype($this->payload),
            'payload_size_bytes' => $this->getPayloadSize(),
            'issues' => [],
        ];

        try {
            json_encode($this->payload, JSON_THROW_ON_ERROR);
            $info['is_serializable'] = true;
        } catch (\JsonException $e) {
            $info['issues'][] = 'Payload JSON serialization failed: ' . $e->getMessage();
        }

        if (is_object($this->payload)) {
            $info['payload_class'] = get_class($this->payload);
            
            // Check for common problematic types
            if ($this->payload instanceof \Closure) {
                $info['issues'][] = 'Payload contains closure - not serializable';
                $info['is_serializable'] = false;
            } elseif ($this->payload instanceof \PDO) {
                $info['issues'][] = 'Payload contains PDO connection - not serializable';
                $info['is_serializable'] = false;
            } elseif (method_exists($this->payload, '__sleep') || method_exists($this->payload, '__wakeup')) {
                $info['issues'][] = 'Payload object has custom serialization methods';
            }
        }

        // Check metadata
        try {
            json_encode($this->metadata, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $info['issues'][] = 'Metadata JSON serialization failed: ' . $e->getMessage();
        }

        return $info;
    }
}
