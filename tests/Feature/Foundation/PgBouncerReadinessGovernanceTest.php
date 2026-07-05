<?php

use App\Services\Foundation\FeatureFlagService;

uses()->group('Foundation', 'PgBouncer', 'Dbperf2');

it('pgbouncer_readiness policy does not require install/service for GO and disables production routing', function () {
    $readiness = config('postgres_runtime_governance.pgbouncer_readiness');

    expect($readiness['production_routing_enabled'])->toBeFalse()
        ->and($readiness['install_required_for_go'])->toBeFalse()
        ->and($readiness['service_required_for_go'])->toBeFalse()
        ->and($readiness['app_cutover_allowed'])->toBeFalse()
        ->and($readiness['safe_pilot_port'])->toBe(6432)
        ->and($readiness['required_before_cutover'])->toContain(
            'backup', 'rollback plan', 'app compatibility audit', 'migration bypass policy',
            'smoke', 'connection count baseline', 'error log watch', 'explicit owner approval',
        );
});

it('app_compatibility_policy documents every required audit item', function () {
    $policy = config('postgres_runtime_governance.app_compatibility_policy');

    expect($policy['must_audit'])->toContain(
        'persistent connections', 'prepared statements', 'temp tables', 'session variables',
        'advisory locks', 'LISTEN/NOTIFY', 'transaction usage', 'migrations', 'queue workers',
        'pg_stat_statements', 'statement_timeout', 'idle_in_transaction_session_timeout',
    );

    foreach ($policy['must_audit'] as $item) {
        expect($policy['findings'])->toHaveKey($item);
    }
});

it('all four PgBouncer/runtime feature flags exist with complete metadata and safe defaults', function () {
    $service = app(FeatureFlagService::class);

    foreach ([
        'foundation.db.pg_bouncer_readiness',
        'foundation.db.pg_bouncer_cutover_enabled',
        'foundation.db.postgres_runtime_tuning_recommendations',
        'foundation.db.postgres_runtime_apply_enabled',
    ] as $key) {
        $flag = $service->get($key);

        expect($flag['default'])->toBeFalse()
            ->and($flag['owner'])->not->toBeEmpty()
            ->and($flag['rollback_action'])->not->toBeEmpty()
            ->and($flag['dependencies'])->not->toBeEmpty();
    }
});

it('feature flag governance reports no unsafe risky default for the four DBPERF-2 flags', function () {
    $governance = app(FeatureFlagService::class)->validateGovernance();

    expect($governance['summary']['decision'])->not->toBe('FAIL');
});

it('pgbouncer pilot template contains no real secret', function () {
    $template = file_get_contents(base_path('docs/architecture/templates/pgbouncer.ini.example'));

    expect($template)->not->toContain('PGPASSWORD')
        ->not->toMatch('/password\s*=\s*[^<\s]/i')
        ->and($template)->toContain('pool_mode = transaction')
        ->and($template)->toContain('server_reset_query')
        ->and($template)->toContain('listen_port = 6432')
        ->and($template)->toContain('auth_file');
});

it('pgbouncer cutover checklist includes rollback plan and migration bypass', function () {
    $checklist = file_get_contents(base_path('docs/architecture/templates/pgbouncer-cutover-checklist.md'));

    expect($checklist)->toContain('Migration bypass policy confirmed')
        ->and($checklist)->toContain('Rollback env plan')
        ->and($checklist)->toContain('backup')
        ->and($checklist)->toContain('owner approval')
        ->and($checklist)->toContain('PostgreSQL itself is never restarted');
});

it('no PgBouncer production routing occurs in this codebase', function () {
    $connectionName = (string) config('database.default');
    $connection = (array) config("database.connections.{$connectionName}", []);

    expect((string) ($connection['port'] ?? ''))->not->toBe('6432');
});
