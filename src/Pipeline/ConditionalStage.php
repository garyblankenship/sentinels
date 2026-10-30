<?php

namespace Vampires\Sentinels\Pipeline;

use Vampires\Sentinels\Contracts\PipelineContract;
use Vampires\Sentinels\Core\Context;

/**
 * Helper class for conditional pipeline stages.
 */
class ConditionalStage
{
    public function __construct(
        protected $condition,
        protected PipelineContract $truePipeline,
        protected ?PipelineContract $falsePipeline = null
    ) {
    }

    public function __invoke(Context $context): Context
    {
        $shouldExecuteTrue = call_user_func($this->condition, $context);

        if ($shouldExecuteTrue) {
            return $this->truePipeline->process($context);
        } elseif ($this->falsePipeline) {
            return $this->falsePipeline->process($context);
        }

        return $context;
    }
}
