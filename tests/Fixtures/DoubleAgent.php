<?php

namespace Vampires\Sentinels\Tests\Fixtures;

use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;

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