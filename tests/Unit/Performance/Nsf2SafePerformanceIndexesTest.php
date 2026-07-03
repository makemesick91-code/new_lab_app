<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('creates nsf2 patient branch active index on pgsql after migration', function () {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('NSF-2 index migration is PostgreSQL-only.');
    }

    expect(Schema::hasTable('mst_patients'))->toBeTrue();

    $row = DB::selectOne(
        "SELECT indexname FROM pg_indexes WHERE tablename = 'mst_patients' AND indexdef ILIKE '%(branch_id, is_active)%' LIMIT 1"
    );

    expect($row)->not->toBeNull();
})->group('pgsql');

it('recognizes inventory branch movement date index via column signature on pgsql', function () {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('NSF-2 index audit is PostgreSQL-only.');
    }

    $row = DB::selectOne(
        "SELECT indexname FROM pg_indexes WHERE tablename = 'trx_inventory_movements' AND indexdef ILIKE '%(branch_id, movement_date)%' LIMIT 1"
    );

    expect($row)->not->toBeNull();
})->group('pgsql');

it('includes nsf2 index status in slow query audit json on pgsql', function () {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('NSF-2 audit status requires pgsql.');
    }

    Artisan::call('performance:slow-query-audit', [
        '--json' => true,
        '--skip-benchmarks' => true,
    ]);

    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toHaveKey('nsf2_index_status')
        ->and($payload['nsf2_index_status'])->toBeArray()
        ->and(collect($payload['nsf2_index_status'])->pluck('target_index')->all())
        ->toContain('idx_nsf2_inventory_movements_branch_movement_date', 'idx_nsf2_patients_branch_is_active');
})->group('pgsql');

it('includes plan metadata in benchmark rows when benchmarks run on pgsql', function () {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Benchmark audit requires pgsql.');
    }

    Artisan::call('performance:slow-query-audit', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    if ($payload['benchmarks'] === [] || isset($payload['benchmarks']['skipped'])) {
        $this->markTestSkipped('No benchmark branch available in test database.');
    }

    expect($payload['benchmarks'][0])->toHaveKeys(['plan_node_summary', 'index_names_used']);
})->group('pgsql');
