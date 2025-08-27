<?php

namespace Vampires\Sentinels\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Vampires\Sentinels\SentinelsServiceProvider;

/**
 * Base test case for all Sentinels tests.
 */
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // Additional setup can be added here
    }

    /**
     * Get package providers.
     */
    protected function getPackageProviders($app): array
    {
        return [
            SentinelsServiceProvider::class,
        ];
    }

    /**
     * Get package aliases.
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Sentinels' => \Vampires\Sentinels\Facades\Sentinels::class,
        ];
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment($app): void
    {
        // Setup the application environment for testing
        $app['config']->set('sentinels.observability.events.enabled', false);
        $app['config']->set('sentinels.observability.metrics.enabled', false);
        $app['config']->set('sentinels.observability.tracing.enabled', false);
    }
}
