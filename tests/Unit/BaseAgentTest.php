<?php

namespace Vampires\Sentinels\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Tests\Fixtures\DoubleAgent;
use Vampires\Sentinels\Tests\Fixtures\MetadataAgent;
use Vampires\Sentinels\Tests\Fixtures\TestAgent;
use Vampires\Sentinels\Tests\Fixtures\ValidationAgent;

class BaseAgentTest extends TestCase
{
    public function test_agent_can_process_context(): void
    {
        $agent = new TestAgent();
        $context = new Context('hello');

        $result = $agent($context);

        $this->assertEquals('HELLO', $result->payload);
        $this->assertEquals($context->correlationId, $result->correlationId);
    }

    public function test_agent_adds_execution_metadata(): void
    {
        $agent = new TestAgent();
        $context = new Context('test');

        $result = $agent($context);

        $this->assertTrue($result->hasMetadata('agent_executed'));
        $this->assertTrue($result->hasMetadata('agent_started'));
        $this->assertTrue($result->hasMetadata('agent_completed'));
        $this->assertTrue($result->hasMetadata('agent_start_time'));
        $this->assertTrue($result->hasMetadata('agent_execution_time'));
        $this->assertEquals('Test Agent', $result->getMetadata('agent_executed'));
    }

    public function test_agent_can_be_disabled(): void
    {
        $agent = new TestAgent();
        $agent->setEnabled(false);
        $context = new Context('test');

        $result = $agent($context);

        $this->assertEquals('test', $result->payload); // Unchanged
        $this->assertTrue($result->hasMetadata('agent_skipped'));
    }

    public function test_agent_validates_input(): void
    {
        $agent = new ValidationAgent();
        $invalidContext = new Context(null);

        $result = $agent($invalidContext);

        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasMetadata('validation_failed'));
        $this->assertStringContainsString('required', implode(' ', $result->errors));
    }

    public function test_agent_can_skip_execution_conditionally(): void
    {
        $agent = new TestAgent();
        $context = new Context('skip');

        $result = $agent($context);

        $this->assertEquals('skip', $result->payload);
        $this->assertTrue($result->hasMetadata('agent_skipped_condition'));
    }

    public function test_agent_handles_exceptions(): void
    {
        $agent = new TestAgent(output: null, shouldFail: true);
        $context = new Context('test');

        $result = $agent($context);

        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasMetadata('agent_failed'));
        $this->assertContains('Test agent failure', $result->errors);
    }

    public function test_agent_can_add_metadata(): void
    {
        $agent = new MetadataAgent();
        $context = new Context('test');

        $result = $agent($context);

        $this->assertEquals('Metadata Agent', $result->getMetadata('processed_by'));
        $this->assertTrue($result->hasTag('processed'));
        $this->assertTrue($result->hasMetadata('processing_time'));
    }

    public function test_agent_with_specific_output_type(): void
    {
        $agent = new DoubleAgent();
        $numericContext = new Context(5);
        $stringContext = new Context('hello');

        $numericResult = $agent($numericContext);
        $stringResult = $agent($stringContext);

        $this->assertEquals(10, $numericResult->payload);
        $this->assertEquals('hello', $stringResult->payload); // Unchanged
    }

    public function test_agent_provides_metadata_about_itself(): void
    {
        $agent = new TestAgent();

        $this->assertEquals('Test Agent', $agent->getName());
        $this->assertIsString($agent->getDescription());
        $this->assertIsArray($agent->getTags());
        $this->assertContains('test', $agent->getTags());
        $this->assertGreaterThan(0, $agent->getEstimatedExecutionTime());
    }

    public function test_agent_priority_and_enabled_state(): void
    {
        $agent = new TestAgent();

        $this->assertTrue($agent->isEnabled());
        $this->assertEquals(0, $agent->getPriority());

        $agent->setEnabled(false)->setPriority(10);

        $this->assertFalse($agent->isEnabled());
        $this->assertEquals(10, $agent->getPriority());
    }

    public function test_agent_generates_unique_id(): void
    {
        $agent1 = new TestAgent();
        $agent2 = new TestAgent();

        $this->assertIsString($agent1->getId());
        $this->assertIsString($agent2->getId());
        $this->assertNotEquals($agent1->getId(), $agent2->getId());
    }

    public function test_agent_validation_with_valid_input(): void
    {
        $agent = new ValidationAgent();
        $validContext = new Context('valid input');

        $result = $agent($validContext);

        $this->assertFalse($result->hasErrors());
        $this->assertTrue($result->hasMetadata('validated'));
        $this->assertFalse($result->hasMetadata('validation_failed'));
    }

    public function test_agent_validation_with_short_string(): void
    {
        $agent = new ValidationAgent();
        $shortContext = new Context('hi');

        $result = $agent($shortContext);

        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasMetadata('validation_failed'));
        $this->assertStringContainsString('3 characters', implode(' ', $result->errors));
    }

    public function test_agent_input_and_output_types(): void
    {
        $agent = new DoubleAgent();

        $this->assertEquals('numeric', $agent->getInputType());
        $this->assertEquals('numeric', $agent->getOutputType());
    }

    public function test_agent_required_permissions(): void
    {
        $agent = new TestAgent();

        $this->assertIsArray($agent->getRequiredPermissions());
        $this->assertEmpty($agent->getRequiredPermissions()); // Default: no permissions
    }

    public function test_agent_retry_policy(): void
    {
        $agent = new TestAgent();

        $this->assertNull($agent->getRetryPolicy()); // Default: use system default
    }

    public function test_agent_executes_lifecycle_hooks(): void
    {
        $agent = new class () extends TestAgent {
            public array $hooksCalled = [];

            protected function beforeExecute(Context $context): Context
            {
                $this->hooksCalled[] = 'before';

                return parent::beforeExecute($context);
            }

            protected function afterExecute(Context $originalContext, Context $result): Context
            {
                $this->hooksCalled[] = 'after';

                return parent::afterExecute($originalContext, $result);
            }
        };

        $context = new Context('test');
        $result = $agent($context);

        $this->assertEquals(['before', 'after'], $agent->hooksCalled);
        $this->assertTrue($result->hasMetadata('agent_execution_time'));
    }

    public function test_agent_executes_error_hook_on_failure(): void
    {
        $agent = new class () extends TestAgent {
            public array $hooksCalled = [];

            protected function handle(Context $context): Context
            {
                throw new \RuntimeException('Test error');
            }

            protected function onError(Context $context, \Throwable $exception): Context
            {
                $this->hooksCalled[] = 'error';

                return parent::onError($context, $exception);
            }
        };

        $context = new Context('test');
        $result = $agent($context);

        $this->assertEquals(['error'], $agent->hooksCalled);
        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->hasMetadata('agent_failed'));
    }
}
