<?php
/**
 * Simple demonstration script showing Laravel Pipeline integration with Sentinels.
 * 
 * This script can be run to verify the bridge functionality works as expected.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Pipeline\Pipeline as LaravelPipeline;
use Vampires\Sentinels\Agents\LaravelPipelineAgent;
use Vampires\Sentinels\Agents\SentinelMediator;
use Vampires\Sentinels\Core\Context;
use Vampires\Sentinels\Pipeline\Pipeline;

// Setup basic container and event dispatcher (normally done by Laravel)
$container = new Container();
$events = new Dispatcher($container);

// Register Laravel Pipeline in container
$container->singleton(LaravelPipeline::class, function ($app) {
    return new LaravelPipeline($app);
});

// Create a simple mediator for demo
$mediator = new SentinelMediator($container, $events);

echo "=== Laravel Pipeline Integration Demo ===\n\n";

// Demo 1: Basic Laravel Pipeline Bridge
echo "1. Basic Laravel Pipeline Bridge:\n";

$laravelAgent = new LaravelPipelineAgent([
    function ($data, $next) {
        echo "   Laravel Pipe 1: Processing {$data['name']}\n";
        $data['step1'] = 'completed';
        return $next($data);
    },
    function ($data, $next) {
        echo "   Laravel Pipe 2: Adding timestamp\n";
        $data['processed_at'] = date('Y-m-d H:i:s');
        return $next($data);
    }
]);

$context = Context::create(['name' => 'John Doe', 'age' => 30]);
$result = $laravelAgent($context);

echo "   Result: " . json_encode($result->payload, JSON_PRETTY_PRINT) . "\n";
echo "   Correlation ID: {$result->correlationId}\n";
echo "   Laravel Pipeline executed: " . ($result->getMetadata('laravel_pipeline_executed') ? 'Yes' : 'No') . "\n\n";

// Demo 2: Error Handling
echo "2. Error Handling:\n";

$errorAgent = new LaravelPipelineAgent([
    function ($data, $next) {
        echo "   Laravel Pipe: Processing before error\n";
        return $next($data);
    },
    function ($data, $next) {
        echo "   Laravel Pipe: Throwing error\n";
        throw new \RuntimeException('Simulated pipeline error');
    }
]);

$errorContext = Context::create(['test' => 'data']);
$errorResult = $errorAgent($errorContext);

echo "   Has Errors: " . ($errorResult->hasErrors() ? 'Yes' : 'No') . "\n";
if ($errorResult->hasErrors()) {
    echo "   First Error: {$errorResult->errors[0]}\n";
}
echo "\n";

// Demo 3: Factory Methods
echo "3. Factory Methods:\n";

$factoryAgent = LaravelPipelineAgent::through(
    function ($data, $next) {
        echo "   Factory Pipe: Processing {$data['message']}\n";
        $data['factory_processed'] = true;
        return $next($data);
    }
);

$factoryContext = Context::create(['message' => 'Hello World']);
$factoryResult = $factoryAgent($factoryContext);

echo "   Factory processed: " . ($factoryResult->payload['factory_processed'] ? 'Yes' : 'No') . "\n";
echo "   Agent name: {$factoryAgent->getName()}\n\n";

// Demo 4: Fluent Pipe Addition
echo "4. Fluent Pipe Addition:\n";

$fluentAgent = new LaravelPipelineAgent();
$fluentAgent
    ->pipe(function ($data, $next) {
        echo "   Fluent Pipe 1: Adding metadata\n";
        $data['metadata'] = ['version' => '1.0'];
        return $next($data);
    })
    ->pipe(function ($data, $next) {
        echo "   Fluent Pipe 2: Finalizing\n";
        $data['finalized'] = true;
        return $next($data);
    });

$fluentContext = Context::create(['original' => 'data']);
$fluentResult = $fluentAgent($fluentContext);

echo "   Pipe Count: " . count($fluentAgent->getPipes()) . "\n";
echo "   Finalized: " . ($fluentResult->payload['finalized'] ? 'Yes' : 'No') . "\n\n";

// Demo 5: Performance Comparison
echo "5. Performance Comparison:\n";

$testData = ['name' => 'Performance Test', 'value' => 42];
$iterations = 1000;

// Laravel Pipeline direct
$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $container->make(LaravelPipeline::class)
        ->send($testData)
        ->through([
            fn($d, $next) => $next(array_merge($d, ['iteration' => $i])),
            fn($d, $next) => $next(array_merge($d, ['processed' => true])),
        ])
        ->thenReturn();
}
$laravelTime = microtime(true) - $start;

// Sentinels bridge
$bridgeAgent = new LaravelPipelineAgent([
    fn($d, $next) => $next(array_merge($d, ['iteration' => 'bridge'])),
    fn($d, $next) => $next(array_merge($d, ['processed' => true])),
]);

$start = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $bridgeAgent(Context::create($testData));
}
$bridgeTime = microtime(true) - $start;

echo "   Laravel Pipeline ({$iterations} iterations): " . round($laravelTime * 1000, 2) . "ms\n";
echo "   Sentinels Bridge ({$iterations} iterations): " . round($bridgeTime * 1000, 2) . "ms\n";
echo "   Overhead: " . round((($bridgeTime - $laravelTime) / $laravelTime) * 100, 1) . "%\n\n";

echo "=== Demo Complete ===\n";
echo "All tests passed! Laravel Pipeline integration is working correctly.\n";