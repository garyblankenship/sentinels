<?php

namespace Vampires\Sentinels;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Vampires\Sentinels\Agents\SentinelMediator;
use Vampires\Sentinels\Console\Commands\MakeAgentCommand;
use Vampires\Sentinels\Console\Commands\MakePipelineCommand;
use Vampires\Sentinels\Console\Commands\SentinelsListCommand;
use Vampires\Sentinels\Contracts\AgentMediator;
use Vampires\Sentinels\Contracts\PipelineContract;
use Vampires\Sentinels\Contracts\RouterContract;
use Vampires\Sentinels\Pipeline\Pipeline;
use Vampires\Sentinels\Routing\ContentRouter;

class SentinelsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sentinels.php', 'sentinels');

        $this->registerCoreServices();
        $this->registerAliases();
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishConfiguration();
        $this->registerCommands();
        $this->discoverAgents();
        $this->bootEvents();
    }

    /**
     * Register core services in the container.
     */
    protected function registerCoreServices(): void
    {
        // Register the mediator as a singleton
        $this->app->singleton(AgentMediator::class, function (Application $app) {
            return new SentinelMediator(
                $app,
                $app['events'],
                config('sentinels.pipelines.middleware.global', [])
            );
        });

        // Register the router
        $this->app->singleton(RouterContract::class, function (Application $app) {
            return new ContentRouter(
                $app[AgentMediator::class],
                config('sentinels.routing', [])
            );
        });

        // Register pipeline factory
        $this->app->bind(PipelineContract::class, function (Application $app) {
            return new Pipeline(
                $app[AgentMediator::class],
                $app['events']
            );
        });

        // Register pipeline builder alias
        $this->app->alias(PipelineContract::class, 'sentinels.pipeline');
    }

    /**
     * Register service aliases.
     */
    protected function registerAliases(): void
    {
        $this->app->alias(AgentMediator::class, 'sentinels.mediator');
        $this->app->alias(RouterContract::class, 'sentinels.router');
    }

    /**
     * Publish configuration files.
     */
    protected function publishConfiguration(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sentinels.php' => config_path('sentinels.php'),
            ], 'sentinels-config');
        }
    }

    /**
     * Register Artisan commands.
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeAgentCommand::class,
                MakePipelineCommand::class,
                SentinelsListCommand::class,
            ]);
        }
    }

    /**
     * Auto-discover agents in configured paths.
     */
    protected function discoverAgents(): void
    {
        if (!config('sentinels.agents.discovery.enabled', true)) {
            return;
        }

        // Agent discovery will be implemented in a later phase
        // For now, we'll set up the foundation for it
    }

    /**
     * Bootstrap event system integration.
     */
    protected function bootEvents(): void
    {
        if (!config('sentinels.observability.events.enabled', true)) {
            return;
        }

        // Event listeners will be registered here
        // For now, we'll set up the foundation
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            AgentMediator::class,
            RouterContract::class,
            PipelineContract::class,
            'sentinels.mediator',
            'sentinels.router',
            'sentinels.pipeline',
        ];
    }
}
