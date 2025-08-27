<?php

namespace Vampires\Sentinels\Facades;

use Illuminate\Support\Facades\Facade;
use Vampires\Sentinels\Contracts\AgentContract;
use Vampires\Sentinels\Contracts\AgentMediator;
use Vampires\Sentinels\Contracts\PipelineContract;
use Vampires\Sentinels\Core\Context;

/**
 * Facade for the Sentinels package.
 *
 * Provides convenient access to core functionality:
 * - Agent execution
 * - Pipeline creation
 * - Testing utilities
 *
 * @method static Context dispatch(Context $context, AgentContract|string $agent)
 * @method static Context dispatchSequence(Context $context, array $agents)
 * @method static Context dispatchParallel(Context $context, array $agents)
 * @method static AgentContract resolveAgent(AgentContract|string $agent)
 * @method static bool canResolve(AgentContract|string $agent)
 * @method static array getExecutionStats()
 * @method static void clearStats()
 * @method static PipelineContract pipeline()
 * @method static mixed process(mixed $input, AgentContract|string|array $agents)
 * @method static void fake()
 * @method static void assertAgentRan(string $agentClass)
 * @method static void assertPipelineProcessed(mixed $input, mixed $expectedOutput)
 * @method static void assertContextContains(string $key, mixed $value)
 *
 * @see \Vampires\Sentinels\Contracts\AgentMediator
 */
class Sentinels extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return AgentMediator::class;
    }

    /**
     * Create a new pipeline instance.
     */
    public static function pipeline(): PipelineContract
    {
        return app(PipelineContract::class);
    }

    /**
     * Process input through agents or pipelines.
     *
     * This is a convenience method that accepts various input types:
     * - Single agent
     * - Array of agents
     * - Pipeline instance
     */
    public static function process(mixed $input, AgentContract|string|array|PipelineContract $processor): mixed
    {
        $context = Context::create($input);
        $mediator = static::getFacadeRoot();

        if ($processor instanceof PipelineContract) {
            return $processor->process($context)->payload;
        }

        if (is_array($processor)) {
            $result = $mediator->dispatchSequence($context, $processor);

            return $result->payload;
        }

        // Single agent
        $result = $mediator->dispatch($context, $processor);

        return $result->payload;
    }

    /**
     * Create a context from input.
     */
    public static function context(mixed $input = null): Context
    {
        return Context::create($input);
    }

    /**
     * Check if an agent is registered/resolvable.
     */
    public static function hasAgent(string $agent): bool
    {
        return static::getFacadeRoot()->canResolve($agent);
    }

    /**
     * Enable testing mode with fake implementations.
     *
     * This will be implemented in the testing phase.
     */
    public static function fake(): void
    {
        // TODO: Implement in testing phase
        // This would typically swap the real mediator with a fake one
    }

    /**
     * Assert that a specific agent was executed.
     *
     * This will be implemented in the testing phase.
     */
    public static function assertAgentRan(string $agentClass): void
    {
        // TODO: Implement in testing phase
    }

    /**
     * Assert that a pipeline processed input and produced expected output.
     *
     * This will be implemented in the testing phase.
     */
    public static function assertPipelineProcessed(mixed $input, mixed $expectedOutput): void
    {
        // TODO: Implement in testing phase
    }

    /**
     * Assert that a context contains a specific key-value pair.
     *
     * This will be implemented in the testing phase.
     */
    public static function assertContextContains(string $key, mixed $value): void
    {
        // TODO: Implement in testing phase
    }

    /**
     * Get performance statistics.
     *
     * @return array{
     *     total_executions: int,
     *     successful_executions: int,
     *     failed_executions: int,
     *     average_execution_time: float,
     *     most_used_agents: array<string, int>
     * }
     */
    public static function stats(): array
    {
        return static::getFacadeRoot()->getExecutionStats();
    }

    /**
     * Reset all statistics.
     */
    public static function resetStats(): void
    {
        static::getFacadeRoot()->clearStats();
    }

    /**
     * Create a simple agent from a callable.
     *
     * This is useful for quick prototyping and testing.
     */
    public static function agent(callable $handler, string $name = null): AgentContract
    {
        return new class ($handler, $name) extends \Vampires\Sentinels\Agents\BaseAgent {
            public function __construct(
                protected $handler,
                protected ?string $agentName = null
            ) {
            }

            protected function handle(Context $context): Context
            {
                $result = call_user_func($this->handler, $context->payload, $context);

                if ($result instanceof Context) {
                    return $result;
                }

                return $context->with($result);
            }

            public function getName(): string
            {
                return $this->agentName ?? 'Anonymous Agent';
            }

            public function getDescription(): string
            {
                return 'Agent created from callable';
            }
        };
    }

    /**
     * Create a pipeline builder for fluent pipeline construction.
     */
    public static function build(): PipelineContract
    {
        return static::pipeline();
    }

    /**
     * Get version information.
     */
    public static function version(): string
    {
        return '0.1.0';
    }
}
