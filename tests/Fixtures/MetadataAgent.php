<?php

namespace Vampires\Sentinels\Tests\Fixtures;

use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Core\Context;

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