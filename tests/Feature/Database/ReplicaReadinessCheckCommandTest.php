<?php

use App\Support\Database\DatabaseReplicaReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses()->group('Replica', 'DatabaseScale');

it('DatabaseScale passes by default when replica is disabled', function () {
    config([
        'database_scale.replica.enabled' => false,
        'database_scale.replica.expected' => false,
    ]);

    $this->artisan('db:replica-readiness-check')
        ->assertExitCode(0);

    $result = app(DatabaseReplicaReadinessService::class)->check();

    expect($result['status'])->toBe('single_primary_ready')
        ->and($result['decision'])->toBe('GO')
        ->and($result['replica_enabled'])->toBeFalse();
});

it('outputs valid json with the expected safe fields', function () {
    $exitCode = Artisan::call('db:replica-readiness-check', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(0)
        ->and($payload)->toBeArray()
        ->and($payload)->toHaveKeys([
            'status',
            'decision',
            'app_env',
            'app_debug_safe',
            'default_connection',
            'primary_driver',
            'primary_host_configured',
            'replica_enabled',
            'replica_expected',
            'replica_connection',
            'replica_host_configured',
            'replica_database_configured',
            'replica_username_configured',
            'replica_password_configured_as_boolean_only',
            'connect_test_status',
            'recovery_check_status',
            'lag_check_status',
            'max_lag_seconds',
            'warnings',
            'recommendations',
        ]);
});

it('fails strict mode when replica is enabled and required read config is missing', function () {
    config([
        'database_scale.replica.enabled' => true,
        'database_scale.replica.expected' => true,
        'database.connections.pgsql_read.host' => null,
        'database.connections.pgsql_read.database' => null,
        'database.connections.pgsql_read.username' => null,
        'database.connections.pgsql_read.password' => '',
    ]);

    $this->artisan('db:replica-readiness-check', ['--strict' => true])
        ->assertExitCode(1);

    $result = app(DatabaseReplicaReadinessService::class)->check(['strict' => true]);

    expect($result['decision'])->toBe('NO_GO')
        ->and($result['missing_required_config_keys'])->toBe([
            'DB_READ_HOST',
            'DB_READ_DATABASE',
            'DB_READ_USERNAME',
            'DB_READ_PASSWORD',
        ]);
});

it('skips connect test safely when replica is disabled', function () {
    config(['database_scale.replica.enabled' => false]);

    $this->artisan('db:replica-readiness-check', ['--connect-test' => true])
        ->assertExitCode(0);

    $result = app(DatabaseReplicaReadinessService::class)->check(['connect_test' => true]);

    expect($result['connect_test_status'])->toBe('skipped')
        ->and($result['connect_test_message'])->toContain('DB_REPLICA_ENABLED is false');
});

it('does not expose database password values', function () {
    config([
        'database_scale.replica.enabled' => true,
        'database.connections.pgsql_read.host' => '127.0.0.1',
        'database.connections.pgsql_read.database' => 'replica_db',
        'database.connections.pgsql_read.username' => 'replica_user',
        'database.connections.pgsql_read.password' => 'super-secret-replica-password',
    ]);

    $result = app(DatabaseReplicaReadinessService::class)->check();

    expect($result['replica_password_configured_as_boolean_only'])->toBeTrue()
        ->and(json_encode($result))->not->toContain('super-secret-replica-password');
});

it('warns when replica is expected but primary and read hosts are identical', function () {
    $defaultConnection = (string) config('database.default');

    config([
        "database.connections.{$defaultConnection}.host" => '127.0.0.1',
        'database_scale.replica.enabled' => true,
        'database_scale.replica.expected' => true,
        'database.connections.pgsql_read.host' => '127.0.0.1',
        'database.connections.pgsql_read.database' => 'replica_db',
        'database.connections.pgsql_read.username' => 'replica_user',
        'database.connections.pgsql_read.password' => 'configured',
    ]);

    $result = app(DatabaseReplicaReadinessService::class)->check();

    expect($result['warnings'])->toContain('Replica is expected but primary/read hosts are identical; acceptable for pilot smoke only.');
});

it('does not perform any query when replica is disabled even if probes are requested', function () {
    config(['database_scale.replica.enabled' => false]);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    app(DatabaseReplicaReadinessService::class)->check([
        'connect_test' => true,
        'lag_check' => true,
    ]);

    expect($queries)->toBe([]);
});
