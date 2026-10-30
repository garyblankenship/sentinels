<?php

namespace Vampires\Sentinels\Tests\Integration;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Vampires\Sentinels\Agents\BaseAgent;
use Vampires\Sentinels\Agents\SentinelMediator;
use Vampires\Sentinels\Contracts\AgentContract;
use Vampires\Sentinels\Contracts\AgentMiddlewareContract;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Core\ValidationResult;
use Vampires\Sentinels\Exceptions\AgentException;
use Vampires\Sentinels\Exceptions\AgentResolutionException;
use Vampires\Sentinels\Routing\ContentRouter;
use Vampires\Sentinels\Tests\TestCase;

class LaravelIntegrationRegressionTest extends TestCase
{
    public function test_unbound_agent_and_middleware_receive_constructor_dependencies(): void
    {
        $dependency = new IntegrationDependency();
        $this->app->instance(IntegrationDependency::class, $dependency);
        $mediator = new SentinelMediator($this->app, $this->app->make(Dispatcher::class), [InjectedMiddleware::class]);

        $agent = $mediator->resolveAgent(InjectedAgent::class);
        $this->assertInstanceOf(InjectedAgent::class, $agent);
        $this->assertSame($dependency, $agent->dependency);
        $result = $mediator->dispatch(new Context('input'), $agent);
        $this->assertSame($dependency, $result->getMetadata('injected_dependency'));
    }

    public function test_explicit_agent_binding_is_preserved_and_checked(): void
    {
        $mediator = $this->app->make(SentinelMediator::class);
        $agent = new RoutingFixtureAgent();
        $this->app->instance(InjectedAgent::class, $agent);
        $this->assertSame($agent, $mediator->resolveAgent(InjectedAgent::class));

        $this->app->instance(InjectedAgent::class, new \stdClass());
        $this->expectException(AgentResolutionException::class);
        $mediator->resolveAgent(InjectedAgent::class);
    }

    public function test_invalid_middleware_binding_reports_contract_failure(): void
    {
        $this->app->instance(InjectedMiddleware::class, new \stdClass());
        $this->expectException(AgentException::class);
        $this->expectExceptionMessage('does not implement AgentMiddlewareContract');
        new SentinelMediator($this->app, $this->app->make(Dispatcher::class), [InjectedMiddleware::class]);
    }

    public function test_validation_rules_require_a_factory_but_empty_rules_do_not(): void
    {
        $original = Container::getInstance();
        Container::setInstance(new Container());

        try {
            $agent = new RulesFixtureAgent();
            $this->assertTrue($agent->check([], [])->valid);
            $this->assertSame('input', (new RoutingFixtureAgent())(new Context('input'))->payload);
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('validation factory is required');
            $agent->check([], ['name' => 'required']);
        } finally {
            Container::setInstance($original);
        }
    }

    public function test_invalid_payload_keeps_the_validation_result_contract(): void
    {
        $agent = new RulesFixtureAgent();
        $this->assertFalse($agent->check([], ['name' => 'required'])->valid);
        $this->assertTrue($agent->check(['name' => 'Ada'], ['name' => 'required'])->valid);
        $result = $agent(new Context([]));
        $this->assertTrue($result->hasErrors());
        $this->assertTrue($result->getMetadata('validation_failed'));
        $this->assertFalse($result->hasMetadata('handled'));
    }

    public function test_matching_routes_precede_fallback_in_priority_and_insertion_order(): void
    {
        $router = $this->router();
        $low = new RoutingFixtureAgent();
        $high = new RoutingFixtureAgent();
        $equal = new RoutingFixtureAgent();
        $fallback = new RoutingFixtureAgent();
        $router->addPredicateRoute(fn (Context $context) => $context->payload === 'yes', $low, 1)
            ->addPredicateRoute(fn () => true, $high, 10)
            ->addPredicateRoute(fn () => true, $equal, 10)
            ->setFallback($fallback);

        $context = new Context('yes');
        $this->assertSame($high, $router->route($context));
        $this->assertSame([$high, $equal, $low, $fallback], $router->getCandidates($context));
        $this->assertTrue($router->canRoute($context));
        $this->assertSame(0, $router->getStats()['fallback_usage']);
        $this->assertTrue($router->removeRoute($high));
        $this->assertSame($equal, $router->route($context));
    }

    public function test_nonmatching_routes_return_null_or_the_fallback(): void
    {
        $router = $this->router();
        $router->addPatternRoute('/^email:/', new RoutingFixtureAgent());
        $context = new Context('sms:hello');
        $this->assertNull($router->route($context));
        $this->assertFalse($router->canRoute($context));
        $this->assertSame([], $router->getCandidates($context));

        $fallback = new RoutingFixtureAgent();
        $router->setFallback($fallback);
        $this->assertSame($fallback, $router->route($context));
        $this->assertTrue($router->canRoute($context));
        $this->assertSame(1, $router->getStats()['fallback_usage']);
    }

    public function test_declared_route_helpers_match_their_documented_values(): void
    {
        $agent = new RoutingFixtureAgent();
        $cases = [
            [fn ($router) => $router->addPatternRoute('/^email:/', $agent), new Context('email:hello')],
            [fn ($router) => $router->addPatternRoute('urgent-*', $agent, 'metadata.kind'), new Context(null, ['kind' => 'urgent-task'])],
            [fn ($router) => $router->addTypeRoute('array', $agent), new Context([])],
            [fn ($router) => $router->addTypeRoute(\stdClass::class, $agent), new Context(new \stdClass())],
            [fn ($router) => $router->addMetadataRoute('enabled', false, $agent), new Context(null, ['enabled' => false])],
            [fn ($router) => $router->addMetadataRoute('options', ['a' => 1], $agent), new Context(null, ['options' => ['a' => 1]])],
            [fn ($router) => $router->addMetadataRoute('present', null, $agent), new Context(null, ['present' => null])],
            [fn ($router) => $router->addTagRoute('urgent', $agent), new Context(tags: ['urgent'])],
            [fn ($router) => $router->addRoute('metadata:kind:urgent', $agent), new Context(metadata: ['kind' => 'urgent'])],
            [fn ($router) => $router->addRoute('string', $agent), new Context('hello')],
        ];
        foreach ($cases as [$configure, $context]) {
            $router = $this->router();
            $configure($router);
            $this->assertSame($agent, $router->route($context));
        }

        $router = $this->router()->addMetadataRoute('enabled', false, $agent);
        $this->assertNull($router->route(new Context(metadata: ['enabled' => 0])));
    }

    private function router(): ContentRouter
    {
        return new ContentRouter($this->app->make(SentinelMediator::class));
    }
}

class IntegrationDependency
{
}

class RoutingFixtureAgent extends BaseAgent
{
    protected function handle(Context $context): Context
    {
        return $context->withMetadata('handled', true);
    }
}

class InjectedAgent extends RoutingFixtureAgent
{
    public function __construct(public IntegrationDependency $dependency)
    {
    }
}

class RulesFixtureAgent extends RoutingFixtureAgent
{
    public function check(mixed $data, array $rules): ValidationResult
    {
        return $this->validateWithRules($data, $rules);
    }

    protected function validatePayload(Context $context): ValidationResult
    {
        return $this->check($context->payload, ['name' => 'required']);
    }
}

class InjectedMiddleware implements AgentMiddlewareContract
{
    public function __construct(public IntegrationDependency $dependency)
    {
    }

    public function before(Context $context, AgentContract $agent): Context
    {
        return $context->withMetadata('injected_dependency', $this->dependency);
    }

    public function after(Context $context, AgentContract $agent, Context $result): Context
    {
        return $result;
    }

    public function onError(Context $context, AgentContract $agent, \Throwable $error): Context
    {
        return $context;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function shouldRun(AgentContract $agent, Context $context): bool
    {
        return true;
    }
}
