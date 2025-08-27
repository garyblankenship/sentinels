<?php

namespace Vampires\Sentinels\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Vampires\Sentinels\Core\Context;

/**
 * Fluent builder for creating Context instances with common patterns.
 *
 * Reduces boilerplate when building contexts with common metadata patterns
 * like user information, request data, priority tagging, etc.
 *
 * @example
 * ContextBuilder::for($order)
 *     ->withUser($user)
 *     ->withRequest($request)
 *     ->asHighPriority()
 *     ->withMetadata('source', 'api')
 *     ->build()
 */
class ContextBuilder
{
    protected mixed $payload = null;
    protected array $metadata = [];
    protected array $tags = [];
    protected ?string $traceId = null;
    protected bool $cancelled = false;
    protected array $errors = [];
    protected ?string $correlationId = null;

    /**
     * Create a new ContextBuilder instance.
     *
     * @param mixed $payload The primary data to be processed
     */
    protected function __construct(mixed $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Create a new builder instance for the given payload.
     *
     * @param mixed $payload The primary data to be processed
     */
    public static function for(mixed $payload): self
    {
        return new self($payload);
    }

    /**
     * Add user information to the context metadata.
     *
     * Automatically extracts common user attributes and adds them as metadata
     * for tracking and auditing purposes.
     */
    public function withUser(Authenticatable $user): self
    {
        $userMeta = [
            'user_id' => method_exists($user, 'getKey') ? $user->getKey() : $user->id ?? null,
            'user_type' => get_class($user),
        ];

        // Add email if available
        if (method_exists($user, 'getEmailForPasswordReset') || isset($user->email)) {
            $userMeta['user_email'] = $user->email ?? $user->getEmailForPasswordReset();
        }

        // Add name if available
        if (isset($user->name)) {
            $userMeta['user_name'] = $user->name;
        }

        return $this->withMergedMetadata($userMeta)->withTag('authenticated');
    }

    /**
     * Add HTTP request information to the context metadata.
     *
     * Extracts useful request information for tracing and debugging without
     * storing sensitive data.
     */
    public function withRequest(Request $request): self
    {
        $requestMeta = [
            'request_method' => $request->method(),
            'request_url' => $request->url(),
            'request_path' => $request->path(),
            'request_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_timestamp' => now()->toISOString(),
        ];

        // Add route info if available
        if ($request->route()) {
            $requestMeta['route_name'] = $request->route()->getName();
            $requestMeta['route_action'] = $request->route()->getActionName();
        }

        // Add trace ID from headers if present
        if ($request->hasHeader('X-Trace-Id')) {
            $this->withTraceId($request->header('X-Trace-Id'));
        } elseif ($request->hasHeader('X-Request-Id')) {
            $this->withTraceId($request->header('X-Request-Id'));
        }

        return $this->withMergedMetadata($requestMeta)->withTag('web_request');
    }

    /**
     * Add a single metadata key-value pair.
     *
     * @param string $key The metadata key
     * @param mixed $value The metadata value
     */
    public function withMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;

        return $this;
    }

    /**
     * Merge multiple metadata values.
     *
     * @param array<string, mixed> $metadata The metadata to merge
     */
    public function withMergedMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);

        return $this;
    }

    /**
     * Add a tag to categorize the context.
     *
     * @param string $tag The tag to add
     */
    public function withTag(string $tag): self
    {
        if (!in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
        }

        return $this;
    }

    /**
     * Add multiple tags at once.
     *
     * @param array<string> $tags The tags to add
     */
    public function withTags(array $tags): self
    {
        foreach ($tags as $tag) {
            $this->withTag($tag);
        }

        return $this;
    }

    /**
     * Mark this context as high priority.
     *
     * This adds priority metadata and tags that can be used by agents
     * and pipeline routing logic.
     */
    public function asHighPriority(): self
    {
        return $this
            ->withMetadata('priority', 'high')
            ->withMetadata('priority_level', 100)
            ->withTag('high_priority')
            ->withTag('expedited');
    }

    /**
     * Mark this context as low priority.
     *
     * This adds priority metadata and tags for background processing.
     */
    public function asLowPriority(): self
    {
        return $this
            ->withMetadata('priority', 'low')
            ->withMetadata('priority_level', 10)
            ->withTag('low_priority')
            ->withTag('background');
    }

    /**
     * Mark this context as batch processing.
     *
     * Useful for bulk operations that should be handled differently
     * than individual requests.
     */
    public function asBatch(): self
    {
        return $this
            ->withMetadata('processing_mode', 'batch')
            ->withTag('batch_processing')
            ->withTag('bulk_operation');
    }

    /**
     * Mark this context as real-time processing.
     *
     * Indicates that this context requires immediate processing
     * and should not be queued or delayed.
     */
    public function asRealTime(): self
    {
        return $this
            ->withMetadata('processing_mode', 'realtime')
            ->withMetadata('requires_immediate_processing', true)
            ->withTag('realtime')
            ->withTag('immediate');
    }

    /**
     * Set a trace ID for distributed tracing.
     *
     * @param string $traceId The distributed tracing identifier
     */
    public function withTraceId(string $traceId): self
    {
        $this->traceId = $traceId;

        return $this;
    }

    /**
     * Set a custom correlation ID.
     *
     * By default, Context generates a UUID, but this allows
     * you to provide your own correlation ID for tracking.
     *
     * @param string $correlationId The correlation identifier
     */
    public function withCorrelationId(string $correlationId): self
    {
        $this->correlationId = $correlationId;

        return $this;
    }

    /**
     * Add application-specific metadata for common business objects.
     *
     * This method provides shortcuts for adding metadata about common
     * business entities like orders, customers, products, etc.
     *
     * @param string $type The business object type (e.g., 'order', 'customer')
     * @param string|int $id The business object identifier
     * @param array<string, mixed> $attributes Additional attributes
     */
    public function withBusinessObject(string $type, string|int $id, array $attributes = []): self
    {
        $metadata = [
            "{$type}_id" => $id,
            "{$type}_type" => $type,
        ];

        foreach ($attributes as $key => $value) {
            $metadata["{$type}_{$key}"] = $value;
        }

        return $this->withMergedMetadata($metadata)->withTag($type);
    }

    /**
     * Add timing metadata for performance tracking.
     *
     * Useful for tracking when operations should be completed by
     * or when they were initiated from external systems.
     *
     * @param \DateTimeInterface|string|null $deadline When this should be completed
     * @param \DateTimeInterface|string|null $initiated When this was started
     */
    public function withTiming(
        \DateTimeInterface|string|null $deadline = null,
        \DateTimeInterface|string|null $initiated = null
    ): self {
        $metadata = [];

        if ($deadline !== null) {
            $metadata['deadline'] = $deadline instanceof \DateTimeInterface
                ? $deadline->toISOString()
                : $deadline;
        }

        if ($initiated !== null) {
            $metadata['initiated_at'] = $initiated instanceof \DateTimeInterface
                ? $initiated->toISOString()
                : $initiated;
        }

        return $this->withMergedMetadata($metadata);
    }

    /**
     * Add error information to the context.
     *
     * This is useful when creating contexts for error handling
     * or retry scenarios.
     *
     * @param string|array<string> $errors Error messages
     */
    public function withErrors(string|array $errors): self
    {
        $this->errors = array_merge($this->errors, is_array($errors) ? $errors : [$errors]);

        return $this;
    }

    /**
     * Mark this context as cancelled.
     *
     * Useful for creating contexts that represent cancelled operations.
     */
    public function asCancelled(): self
    {
        $this->cancelled = true;

        return $this;
    }

    /**
     * Build the final Context instance.
     *
     * Creates an immutable Context object with all the configured
     * metadata, tags, and properties.
     */
    public function build(): Context
    {
        return new Context(
            payload: $this->payload,
            metadata: $this->metadata,
            correlationId: $this->correlationId,
            tags: $this->tags,
            traceId: $this->traceId,
            cancelled: $this->cancelled,
            errors: $this->errors,
        );
    }
}