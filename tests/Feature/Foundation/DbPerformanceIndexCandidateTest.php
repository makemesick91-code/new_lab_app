<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses()->group('Foundation', 'DbPerformance');

it('no duplicate migration names across index candidates', function () {
    $candidates = collect(config('db_performance_governance.index_candidate_policy'))
        ->pluck('migration_name')
        ->filter();

    expect($candidates->count())->toBe($candidates->unique()->count());
});

it('dbperf1 index migration is additive only and uses safe PostgreSQL syntax', function () {
    $path = database_path('migrations/2026_07_05_200001_add_dbperf1_idempotency_status_expires_at_index.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain('CREATE INDEX CONCURRENTLY IF NOT EXISTS')
        ->and($contents)->toContain('withinTransaction = false')
        ->and($contents)->toContain("getDriverName() !== 'pgsql'")
        ->not->toContain('DROP TABLE')
        ->not->toContain('TRUNCATE');
});

it('dbperf1 index migration down() only drops the index it created', function () {
    $path = database_path('migrations/2026_07_05_200001_add_dbperf1_idempotency_status_expires_at_index.php');
    $contents = file_get_contents($path);

    expect($contents)->toContain('DROP INDEX CONCURRENTLY IF EXISTS idx_dbperf1_idempotency_status_expires_at');
});

it('sys_idempotency_keys table exists so the DBPERF-1 index migration is applicable', function () {
    expect(Schema::hasTable('sys_idempotency_keys'))->toBeTrue();
});

it('dbperf1 idempotency composite index exists on PostgreSQL after migration', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires a pgsql connection to verify CONCURRENTLY index creation.');
    }

    $rows = DB::select("
        SELECT indexname FROM pg_indexes
        WHERE tablename = 'sys_idempotency_keys' AND indexname = 'idx_dbperf1_idempotency_status_expires_at'
    ");

    expect($rows)->not->toBeEmpty();
});

it('every no_action / deferred candidate documents a reason and query family', function () {
    $deferred = collect(config('db_performance_governance.index_candidate_policy'))
        ->whereNotIn('decision', ['add_index_now']);

    expect($deferred)->not->toBeEmpty();

    foreach ($deferred as $candidate) {
        expect($candidate['reason'])->not->toBeEmpty()
            ->and($candidate['query_family'])->not->toBeEmpty()
            ->and($candidate['decision'])->toBeIn(['no_action', 'defer_to_rpt_1', 'defer_to_partitioning', 'audit_only']);
    }
});

it('artifact policy never allows raw PII, DB credentials, or full result sets', function () {
    $forbidden = config('db_performance_governance.artifact_policy.forbidden');

    expect($forbidden)->toContain('no_raw_pii', 'no_db_credentials', 'no_full_result_sets');
});
