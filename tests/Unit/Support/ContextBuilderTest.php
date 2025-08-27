<?php

namespace Vampires\Sentinels\Tests\Unit\Support;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Support\ContextBuilder;

class ContextBuilderTest extends TestCase
{
    public function test_can_create_builder_with_payload(): void
    {
        $builder = ContextBuilder::for('test payload');
        $context = $builder->build();

        $this->assertEquals('test payload', $context->payload);
        $this->assertIsString($context->correlationId);
    }

    public function test_can_add_single_metadata(): void
    {
        $context = ContextBuilder::for('test')
            ->withMetadata('key', 'value')
            ->build();

        $this->assertEquals('value', $context->getMetadata('key'));
    }

    public function test_can_add_multiple_metadata(): void
    {
        $context = ContextBuilder::for('test')
            ->withMergedMetadata(['key1' => 'value1', 'key2' => 'value2'])
            ->build();

        $this->assertEquals('value1', $context->getMetadata('key1'));
        $this->assertEquals('value2', $context->getMetadata('key2'));
    }

    public function test_can_add_tags(): void
    {
        $context = ContextBuilder::for('test')
            ->withTag('important')
            ->withTags(['urgent', 'processing'])
            ->build();

        $this->assertTrue($context->hasTag('important'));
        $this->assertTrue($context->hasTag('urgent'));
        $this->assertTrue($context->hasTag('processing'));
    }

    public function test_can_mark_as_high_priority(): void
    {
        $context = ContextBuilder::for('test')
            ->asHighPriority()
            ->build();

        $this->assertEquals('high', $context->getMetadata('priority'));
        $this->assertEquals(100, $context->getMetadata('priority_level'));
        $this->assertTrue($context->hasTag('high_priority'));
        $this->assertTrue($context->hasTag('expedited'));
    }

    public function test_can_mark_as_low_priority(): void
    {
        $context = ContextBuilder::for('test')
            ->asLowPriority()
            ->build();

        $this->assertEquals('low', $context->getMetadata('priority'));
        $this->assertEquals(10, $context->getMetadata('priority_level'));
        $this->assertTrue($context->hasTag('low_priority'));
        $this->assertTrue($context->hasTag('background'));
    }

    public function test_can_mark_as_batch(): void
    {
        $context = ContextBuilder::for('test')
            ->asBatch()
            ->build();

        $this->assertEquals('batch', $context->getMetadata('processing_mode'));
        $this->assertTrue($context->hasTag('batch_processing'));
        $this->assertTrue($context->hasTag('bulk_operation'));
    }

    public function test_can_mark_as_real_time(): void
    {
        $context = ContextBuilder::for('test')
            ->asRealTime()
            ->build();

        $this->assertEquals('realtime', $context->getMetadata('processing_mode'));
        $this->assertTrue($context->getMetadata('requires_immediate_processing'));
        $this->assertTrue($context->hasTag('realtime'));
        $this->assertTrue($context->hasTag('immediate'));
    }

    public function test_can_add_user_information(): void
    {
        $user = new class extends User {
            public $id = 123;
            public $email = 'test@example.com';
            public $name = 'Test User';

            public function getKey()
            {
                return $this->id;
            }
        };

        $context = ContextBuilder::for('test')
            ->withUser($user)
            ->build();

        $this->assertEquals(123, $context->getMetadata('user_id'));
        $this->assertEquals('test@example.com', $context->getMetadata('user_email'));
        $this->assertEquals('Test User', $context->getMetadata('user_name'));
        $this->assertTrue($context->hasTag('authenticated'));
    }

    public function test_can_add_business_object(): void
    {
        $context = ContextBuilder::for('test')
            ->withBusinessObject('order', 'ORD-123', ['status' => 'pending', 'total' => 99.99])
            ->build();

        $this->assertEquals('ORD-123', $context->getMetadata('order_id'));
        $this->assertEquals('order', $context->getMetadata('order_type'));
        $this->assertEquals('pending', $context->getMetadata('order_status'));
        $this->assertEquals(99.99, $context->getMetadata('order_total'));
        $this->assertTrue($context->hasTag('order'));
    }

    public function test_can_add_timing_information(): void
    {
        $deadline = '2023-12-31T23:59:59Z';
        $initiated = '2023-12-01T00:00:00Z';

        $context = ContextBuilder::for('test')
            ->withTiming($deadline, $initiated)
            ->build();

        $this->assertEquals($deadline, $context->getMetadata('deadline'));
        $this->assertEquals($initiated, $context->getMetadata('initiated_at'));
    }

    public function test_can_set_trace_id(): void
    {
        $context = ContextBuilder::for('test')
            ->withTraceId('trace-123')
            ->build();

        $this->assertEquals('trace-123', $context->traceId);
    }

    public function test_can_set_correlation_id(): void
    {
        $context = ContextBuilder::for('test')
            ->withCorrelationId('corr-456')
            ->build();

        $this->assertEquals('corr-456', $context->correlationId);
    }

    public function test_can_add_errors(): void
    {
        $context = ContextBuilder::for('test')
            ->withErrors(['Error 1', 'Error 2'])
            ->build();

        $this->assertTrue($context->hasErrors());
        $this->assertEquals(['Error 1', 'Error 2'], $context->errors);
    }

    public function test_can_add_single_error(): void
    {
        $context = ContextBuilder::for('test')
            ->withErrors('Single error')
            ->build();

        $this->assertTrue($context->hasErrors());
        $this->assertEquals(['Single error'], $context->errors);
    }

    public function test_can_mark_as_cancelled(): void
    {
        $context = ContextBuilder::for('test')
            ->asCancelled()
            ->build();

        $this->assertTrue($context->isCancelled());
    }

    public function test_chaining_methods(): void
    {
        $context = ContextBuilder::for(['order_id' => 123])
            ->withMetadata('source', 'api')
            ->asHighPriority()
            ->withTag('critical')
            ->withTraceId('trace-abc')
            ->build();

        $this->assertEquals(['order_id' => 123], $context->payload);
        $this->assertEquals('api', $context->getMetadata('source'));
        $this->assertEquals('high', $context->getMetadata('priority'));
        $this->assertTrue($context->hasTag('critical'));
        $this->assertTrue($context->hasTag('high_priority'));
        $this->assertEquals('trace-abc', $context->traceId);
    }

    public function test_duplicate_tags_are_ignored(): void
    {
        $context = ContextBuilder::for('test')
            ->withTag('duplicate')
            ->withTag('duplicate')
            ->withTags(['duplicate', 'unique'])
            ->build();

        $tags = $context->tags;
        $duplicateCount = array_count_values($tags)['duplicate'] ?? 0;
        
        $this->assertEquals(1, $duplicateCount, 'Duplicate tags should only appear once');
        $this->assertTrue($context->hasTag('unique'));
    }
}