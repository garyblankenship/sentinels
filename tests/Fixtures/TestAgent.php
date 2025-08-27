<?php

namespace Vampires\Sentinels\Tests\Fixtures;

use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\ValidationResult;

/**
 * Simple test agent for unit testing.
 */
class TestAgent extends BaseAgent
{
    public function __construct(
        protected mixed $output = null,
        protected bool $shouldFail = false,
        protected string $agentName = 'Test Agent'
    ) {
    }

    protected function handle(Context $context): Context
    {
        if ($this->shouldFail) {
            throw new \RuntimeException('Test agent failure');
        }

        if ($this->output !== null) {
            return $context->with($this->output);
        }

        // Default behavior: uppercase string payloads
        if (is_string($context->payload)) {
            return $context->with(strtoupper($context->payload));
        }

        return $context->with($context->payload);
    }

    public function getName(): string
    {
        return $this->agentName;
    }

    public function getDescription(): string
    {
        return 'Test agent for unit testing';
    }

    public function validatePayload(Context $context): ValidationResult
    {
        if ($context->payload === 'invalid') {
            return ValidationResult::invalid(['payload' => ['Invalid test payload']]);
        }

        return ValidationResult::valid($context->payload);
    }

    public function shouldExecute(Context $context): bool
    {
        return $context->payload !== 'skip';
    }

    public function getTags(): array
    {
        return ['test', 'fixture'];
    }

    public function getEstimatedExecutionTime(): int
    {
        return 10; // 10ms
    }
}

/**
 * Test agent that adds metadata.
 */
class MetadataAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        return $context
            ->withMetadata('processed_by', $this->getName())
            ->withMetadata('processing_time', microtime(true))
            ->withTag('processed');
    }

    public function getName(): string
    {
        return 'Metadata Agent';
    }

    public function getDescription(): string
    {
        return 'Adds metadata to context';
    }
}

/**
 * Test agent that transforms numeric payloads.
 */
class DoubleAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        if (is_numeric($context->payload)) {
            return $context->with($context->payload * 2);
        }

        return $context;
    }

    public function getName(): string
    {
        return 'Double Agent';
    }

    public function getDescription(): string
    {
        return 'Doubles numeric values';
    }

    public function getInputType(): ?string
    {
        return 'numeric';
    }

    public function getOutputType(): ?string
    {
        return 'numeric';
    }
}

/**
 * Test agent that fails validation for certain inputs.
 */
class ValidationAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        return $context->withMetadata('validated', true);
    }

    public function validatePayload(Context $context): ValidationResult
    {
        if ($context->payload === null) {
            return ValidationResult::requiredFieldMissing('payload');
        }

        if (is_string($context->payload) && strlen($context->payload) < 3) {
            return ValidationResult::invalid(['payload' => ['Payload must be at least 3 characters']]);
        }

        return ValidationResult::valid($context->payload);
    }

    public function getName(): string
    {
        return 'Validation Agent';
    }

    public function getDescription(): string
    {
        return 'Validates input payload';
    }
}
