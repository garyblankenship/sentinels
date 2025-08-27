<?php

namespace Vampires\Sentinels\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vampires\Sentinels\Core\Context;

class ContextTest extends TestCase
{
    public function test_context_can_be_created_with_payload(): void
    {
        $context = new Context('test payload');

        $this->assertEquals('test payload', $context->payload);
        $this->assertIsString($context->correlationId);
        $this->assertIsFloat($context->startTime);
        $this->assertFalse($context->cancelled);
        $this->assertEmpty($context->errors);
        $this->assertEmpty($context->metadata);
        $this->assertEmpty($context->tags);
    }

    public function test_context_maintains_immutability(): void
    {
        $original = new Context('original');
        $modified = $original->with('modified');

        $this->assertEquals('original', $original->payload);
        $this->assertEquals('modified', $modified->payload);
        $this->assertNotSame($original, $modified);
        $this->assertEquals($original->correlationId, $modified->correlationId);
    }

    public function test_context_can_add_metadata(): void
    {
        $context = new Context('test');
        $withMetadata = $context->withMetadata('key', 'value');

        $this->assertEmpty($context->metadata);
        $this->assertEquals(['key' => 'value'], $withMetadata->metadata);
        $this->assertEquals('value', $withMetadata->getMetadata('key'));
        $this->assertNull($withMetadata->getMetadata('missing'));
        $this->assertEquals('default', $withMetadata->getMetadata('missing', 'default'));
    }

    public function test_context_can_merge_metadata(): void
    {
        $context = new Context('test', ['existing' => 'value']);
        $merged = $context->withMergedMetadata(['new' => 'data', 'existing' => 'overridden']);

        $this->assertEquals([
            'existing' => 'overridden',
            'new' => 'data',
        ], $merged->metadata);
    }

    public function test_context_can_add_tags(): void
    {
        $context = new Context('test');
        $withTag = $context->withTag('important');
        $withMultipleTags = $withTag->withTags(['urgent', 'important']);

        $this->assertEmpty($context->tags);
        $this->assertEquals(['important'], $withTag->tags);
        $this->assertEquals(['important', 'urgent'], $withMultipleTags->tags);
        $this->assertTrue($withMultipleTags->hasTag('important'));
        $this->assertTrue($withMultipleTags->hasTag('urgent'));
        $this->assertFalse($withMultipleTags->hasTag('missing'));
    }

    public function test_context_can_be_cancelled(): void
    {
        $context = new Context('test');
        $cancelled = $context->cancel();

        $this->assertFalse($context->isCancelled());
        $this->assertTrue($cancelled->isCancelled());
    }

    public function test_context_can_accumulate_errors(): void
    {
        $context = new Context('test');
        $withError = $context->addError('Something went wrong');
        $withMultipleErrors = $withError->addErrors(['Another error', 'Third error']);

        $this->assertFalse($context->hasErrors());
        $this->assertTrue($withError->hasErrors());
        $this->assertEquals(['Something went wrong'], $withError->errors);
        $this->assertEquals([
            'Something went wrong',
            'Another error',
            'Third error',
        ], $withMultipleErrors->errors);
    }

    public function test_context_tracks_elapsed_time(): void
    {
        $context = new Context('test');
        usleep(1000); // 1ms

        $elapsed = $context->getElapsedTime();
        $this->assertGreaterThan(0, $elapsed);
        $this->assertLessThan(1, $elapsed); // Should be less than 1 second
    }

    public function test_context_can_check_if_empty(): void
    {
        $emptyContexts = [
            new Context(),
            new Context(null),
            new Context(''),
            new Context([]),
        ];

        foreach ($emptyContexts as $context) {
            $this->assertTrue($context->isEmpty(), 'Context should be empty');
        }

        $nonEmptyContexts = [
            new Context('test'),
            new Context(0),
            new Context(false),
            new Context(['item']),
        ];

        foreach ($nonEmptyContexts as $context) {
            $this->assertFalse($context->isEmpty(), 'Context should not be empty');
        }
    }

    public function test_context_static_create_method(): void
    {
        $context = Context::create('test payload');

        $this->assertInstanceOf(Context::class, $context);
        $this->assertEquals('test payload', $context->payload);
    }

    public function test_context_with_trace_id(): void
    {
        $context = new Context('test');
        $withTrace = $context->withTraceId('trace-123');

        $this->assertNull($context->traceId);
        $this->assertEquals('trace-123', $withTrace->traceId);
    }

    public function test_context_to_array(): void
    {
        $context = new Context(
            payload: 'test',
            metadata: ['key' => 'value'],
            tags: ['important'],
        );

        $array = $context->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('test', $array['payload']);
        $this->assertEquals(['key' => 'value'], $array['metadata']);
        $this->assertEquals(['important'], $array['tags']);
        $this->assertArrayHasKey('correlationId', $array);
        $this->assertArrayHasKey('startTime', $array);
        $this->assertArrayHasKey('elapsedTime', $array);
    }

    public function test_context_generates_unique_correlation_ids(): void
    {
        $context1 = new Context('test1');
        $context2 = new Context('test2');

        $this->assertNotEquals($context1->correlationId, $context2->correlationId);
        $this->assertIsString($context1->correlationId);
        $this->assertIsString($context2->correlationId);
    }

    public function test_context_payload_size_estimation(): void
    {
        $smallContext = new Context('test');
        $largeContext = new Context(str_repeat('x', 1000));

        $this->assertGreaterThan(0, $smallContext->getPayloadSize());
        $this->assertGreaterThan($smallContext->getPayloadSize(), $largeContext->getPayloadSize());
    }
}
