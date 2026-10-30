<?php

namespace Vampires\Sentinels\Pipeline;

use Closure;
use Illuminate\Bus\Batch;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Support\ReflectionClosure;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Exceptions\PipelineException;

/** @internal Carries submission data, never the live pipeline or container. */
final class AsyncBatchCallback
{
    private mixed $successCallback;
    private mixed $errorHandler;

    public function __construct(
        private Context $context,
        private array $agentClasses,
        mixed $successCallback = null,
        mixed $errorHandler = null,
    ) {
        $this->successCallback = $this->serializableCallback($successCallback);
        $this->errorHandler = $this->serializableCallback($errorHandler);
    }

    private function serializableCallback(mixed $callback): mixed
    {
        if ($callback instanceof Closure) {
            if ((new ReflectionClosure($callback))->isBindingRequired()) {
                throw new PipelineException(
                    'Async pipeline callbacks must use static closures or serializable callable objects'
                );
            }

            return new SerializableClosure($callback);
        }

        return $callback;
    }

    public function __invoke(Batch $batch): void
    {
        $pipeline = app(Pipeline::class)->mode('parallel')->async();
        foreach ($this->agentClasses as $agentClass) {
            $pipeline->pipe($agentClass);
        }
        if ($this->successCallback) {
            $pipeline->onSuccess($this->successCallback);
        }
        if ($this->errorHandler) {
            $pipeline->onError($this->errorHandler);
        }

        $pipeline->completeAsyncBatch($batch, $this->context);
    }
}
