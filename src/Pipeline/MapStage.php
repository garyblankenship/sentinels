<?php

namespace Vampires\Sentinels\Pipeline;

use Vampires\Sentinels\Core\Context;

/**
 * Helper class for map operations.
 */
class MapStage
{
    public function __construct(
        protected $mapper
    ) {
    }

    public function __invoke(Context $context): Context
    {
        if (!is_iterable($context->payload)) {
            return $context;
        }

        $mapped = [];
        foreach ($context->payload as $key => $value) {
            $mapped[$key] = call_user_func($this->mapper, $value, $key, $context);
        }

        return $context->with($mapped);
    }
}
