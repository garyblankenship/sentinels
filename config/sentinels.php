<?php

use Vampires\Sentinels\Enums\PipelineMode;
use Vampires\Sentinels\Enums\RoutingStrategy;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Pipeline Mode
    |--------------------------------------------------------------------------
    |
    | This option controls the default execution mode for pipelines when
    | no explicit mode is specified. Available modes: sequential, parallel,
    | conditional, map_reduce.
    |
    */

    'default_mode' => PipelineMode::Sequential,

    /*
    |--------------------------------------------------------------------------
    | Agent Configuration
    |--------------------------------------------------------------------------
    |
    | These options control agent discovery, execution limits, and behavior.
    |
    */

    'agents' => [
        'discovery' => [
            'enabled' => true,
            'paths' => ['app/Agents', 'app/Pipelines'],
            'cache_key' => 'sentinels.agents.discovered',
            'attributes' => true, // Enable attribute-based discovery
        ],
        'execution' => [
            'timeout' => 30, // seconds
            'memory_limit' => '128M',
            'max_depth' => 10, // nested pipeline depth
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pipeline Configuration
    |--------------------------------------------------------------------------
    |
    | Configure pipeline middleware, execution modes, and behavior.
    |
    */

    'pipelines' => [
        'middleware' => [
            'global' => [
                // Global middleware applied to all pipelines
                // 'Vampires\Sentinels\Middleware\TimingMiddleware',
                // 'Vampires\Sentinels\Middleware\LoggingMiddleware',
            ],
            'groups' => [
                'api' => [
                    // 'Vampires\Sentinels\Middleware\MetricsMiddleware',
                ],
                'background' => [
                    // 'Vampires\Sentinels\Middleware\RetryMiddleware',
                ],
            ],
        ],
        'execution' => [
            PipelineMode::Sequential->value => [
                'timeout' => 300,
                'retry_attempts' => 3,
                'retry_delay' => 1000, // milliseconds
            ],
            PipelineMode::Parallel->value => [
                'max_workers' => 4,
                'timeout' => 60,
                'chunk_size' => 100,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure dynamic agent routing behavior and caching.
    |
    */

    'routing' => [
        'strategy' => RoutingStrategy::ContentBased,
        'cache' => [
            'store' => env('SENTINELS_CACHE_STORE', 'redis'),
            'ttl' => 3600,
            'tags' => ['sentinels', 'routing'],
        ],
        'fallback' => null, // Fallback agent class
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability Configuration
    |--------------------------------------------------------------------------
    |
    | Configure events, metrics, and tracing for pipeline monitoring.
    |
    */

    'observability' => [
        'events' => [
            'enabled' => true,
            'channels' => ['log'], // log, database, webhook
        ],
        'metrics' => [
            'enabled' => env('SENTINELS_METRICS_ENABLED', true),
            'driver' => 'memory', // memory, redis, influx
            'retention' => 7 * 24 * 3600, // 7 days
        ],
        'tracing' => [
            'enabled' => env('SENTINELS_TRACING', false),
            'sample_rate' => 0.1,
            'correlation_header' => 'X-Correlation-ID',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for async pipeline execution via Laravel queues.
    |
    */

    'queue' => [
        'connection' => env('SENTINELS_QUEUE_CONNECTION', 'default'),
        'queue' => env('SENTINELS_QUEUE', 'sentinels'),
        'serialization' => [
            'compress' => true,
            'max_size' => 10 * 1024 * 1024, // 10MB
        ],
        'retry_policy' => [
            'max_attempts' => 3,
            'backoff_strategy' => 'exponential', // linear, exponential
            'base_delay' => 1000, // milliseconds
            'max_delay' => 60000, // milliseconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Async Pipeline Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for true asynchronous parallel pipeline execution.
    | These settings control how pipelines behave when using ->async() mode.
    |
    */

    'async' => [
        /*
        | Queue to use for async pipeline jobs
        */
        'queue' => env('SENTINELS_ASYNC_QUEUE', 'sentinels'),

        /*
        | Cache TTL for batch results (seconds)
        | Results are cached to allow retrieval after batch completion
        */
        'cache_ttl' => env('SENTINELS_ASYNC_CACHE_TTL', 3600),


        /*
        | Maximum batch job timeout (seconds)
        | Individual job timeout within async batches
        */
        'job_timeout' => env('SENTINELS_ASYNC_JOB_TIMEOUT', 120),

        /*
        | Maximum number of job attempts
        | How many times to retry failed async jobs
        */
        'job_tries' => env('SENTINELS_ASYNC_JOB_TRIES', 3),

        /*
        | Validation settings for async contexts
        */
        'validation' => [
            'check_serialization' => env('SENTINELS_CHECK_SERIALIZATION', true),
            'max_payload_size' => env('SENTINELS_MAX_ASYNC_PAYLOAD', 1024 * 1024), // 1MB
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Security-related settings for agent execution and context handling.
    |
    */

    'security' => [
        'validate_inputs' => true,
        'max_context_size' => 10 * 1024 * 1024, // 10MB
        'allowed_classes' => [], // For unserialization
        'sandbox_agents' => env('SENTINELS_SANDBOX', false),
    ],
];