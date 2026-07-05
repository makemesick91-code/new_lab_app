<?php

return [
    /*
    |--------------------------------------------------------------------------
    | REPLICA-1 — Read Replica Readiness & Database Scale Foundation
    |--------------------------------------------------------------------------
    |
    | Readiness/audit only. These flags do not switch runtime reads to a
    | replica and do not modify the default database connection.
    |
    */

    'replica' => [
        'enabled' => env('DB_REPLICA_ENABLED', false),
        'expected' => env('DB_REPLICA_EXPECTED', false),
        'strict' => env('DB_REPLICA_STRICT', false),
        'connection' => env('DB_REPLICA_CONNECTION', 'pgsql_read'),
        'max_lag_seconds' => env('DB_REPLICA_MAX_LAG_SECONDS', 5),
        'connect_timeout_seconds' => env('DB_REPLICA_CONNECT_TIMEOUT_SECONDS', 2),
        'query_timeout_ms' => env('DB_REPLICA_QUERY_TIMEOUT_MS', 1500),
        'expect_read_only' => env('DB_REPLICA_EXPECT_READ_ONLY', true),
        'healthcheck_enabled' => env('DB_REPLICA_HEALTHCHECK_ENABLED', true),
        'healthcheck_deep' => env('DB_REPLICA_HEALTHCHECK_DEEP', false),
        'allow_reporting_reads' => env('DB_REPLICA_ALLOW_REPORTING_READS', false),
        'fail_on_lag' => env('DB_REPLICA_FAIL_ON_LAG', false),
    ],
];
