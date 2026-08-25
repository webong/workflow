<?php

declare(strict_types=1);

return [
    'driver' => env('WORK_FLOW_STORE', 'database'),

    'database' => [
        'connection' => env('WORK_FLOW_DATABASE_CONNECTION'),
    ],

    'redis' => [
        'store' => env('WORK_FLOW_REDIS_STORE', 'redis'),
        'prefix' => env('WORK_FLOW_REDIS_PREFIX', 'work-flow'),
        'ttl' => null,
        'lock_seconds' => 30,
        'lock_wait_seconds' => 5,
    ],

    /**
     * Temporal connection and worker defaults for the optional adapter.
     *
     * WorkFlow does not create a Temporal client or worker. The host
     * application should install temporal/sdk and consume these values when
     * bootstrapping its client and worker processes.
     */
    'temporal' => [
        'enabled' => env('WORK_FLOW_TEMPORAL_ENABLED', false),
        'address' => env('WORK_FLOW_TEMPORAL_ADDRESS', '127.0.0.1:7233'),
        'namespace' => env('WORK_FLOW_TEMPORAL_NAMESPACE', 'default'),
        'task_queue' => env('WORK_FLOW_TEMPORAL_TASK_QUEUE', 'work-flow'),
    ],
];
