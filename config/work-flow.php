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
];
