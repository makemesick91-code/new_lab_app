<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| NSF-21 — SQLite migration compatibility
|--------------------------------------------------------------------------
|
| The RME receivables performance migration is PostgreSQL-only: it issues
| CREATE INDEX CONCURRENTLY ... INCLUDE ... WHERE, none of which SQLite
| supports. What NSF-21 protects is therefore a PORTABILITY contract — running
| the real migration stack against a non-PostgreSQL database must still
| succeed, with the PostgreSQL-only statements skipped rather than fatal.
|
| The authoritative suite runs on PostgreSQL, so that contract cannot be proven
| by inspecting whichever connection the environment happens to hand us. Each
| test below builds its own throwaway in-memory SQLite connection and exercises
| the real migrations against it, which keeps the assertion meaningful whether
| the ambient driver is pgsql or sqlite.
*/

/**
 * Register (and reset) the dedicated in-memory SQLite connection this file
 * uses. The connection is separate from the suite's default one, so nothing
 * here depends on — or disturbs — the ambient database.
 */
function nsf21SqliteCompatConnection(): string
{
    $connection = 'nsf21_sqlite_compat';

    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    // An in-memory database lives only as long as its PDO handle, so purging
    // first guarantees a genuinely empty schema for every test.
    DB::purge($connection);

    return $connection;
}

/**
 * Run the real migration stack — never a hand-copied or simplified schema —
 * against the explicit SQLite connection.
 */
function nsf21MigrateSqliteCompatConnection(string $connection): int
{
    return Artisan::call('migrate', [
        '--database' => $connection,
        '--force' => true,
    ]);
}

afterEach(function () {
    DB::purge('nsf21_sqlite_compat');
    config()->offsetUnset('database.connections.nsf21_sqlite_compat');
});

it('guards rme receivables performance index migration for non-pgsql drivers', function () {
    $path = database_path('migrations/2026_06_30_153729_add_rme_receivables_performance_indexes.php');
    $content = file_get_contents($path);

    expect($content)
        ->toContain("getDriverName() !== 'pgsql'")
        ->and(substr_count($content, "getDriverName() !== 'pgsql'"))->toBeGreaterThanOrEqual(2);
});

it('runs the real migration stack on an explicit sqlite connection', function () {
    $ambientBefore = DB::getDefaultConnection();

    $connection = nsf21SqliteCompatConnection();

    expect(DB::connection($connection)->getDriverName())->toBe('sqlite');

    expect(nsf21MigrateSqliteCompatConnection($connection))->toBe(0)
        ->and(Schema::connection($connection)->hasTable('trx_rme_payments'))->toBeTrue()
        ->and(Schema::connection($connection)->hasTable('trx_rme_invoices'))->toBeTrue();

    // The PostgreSQL-only migration must actually execute (and be recorded) on
    // SQLite rather than blow up or be quietly excluded from the stack.
    expect(
        DB::connection($connection)
            ->table('migrations')
            ->where('migration', '2026_06_30_153729_add_rme_receivables_performance_indexes')
            ->exists()
    )->toBeTrue();

    // ... while the CONCURRENTLY / INCLUDE indexes it creates on PostgreSQL are
    // correctly skipped here, which is precisely the guard being protected.
    $indexes = collect(Schema::connection($connection)->getIndexes('trx_rme_invoices'))
        ->pluck('name')
        ->all();

    expect($indexes)->not->toContain('trx_rme_invoices_active_receivable_order_idx');

    // This test never asks the environment to become SQLite.
    expect(DB::getDefaultConnection())->toBe($ambientBefore);
});

it('runs performance slow query audit on sqlite without migration errors', function () {
    $ambientBefore = DB::getDefaultConnection();

    $connection = nsf21SqliteCompatConnection();

    expect(nsf21MigrateSqliteCompatConnection($connection))->toBe(0);

    // The audit is full of PostgreSQL-specific SQL, so pointing it at SQLite is
    // the only way this assertion tests the portability guard at all.
    DB::setDefaultConnection($connection);

    try {
        $exitCode = Artisan::call('performance:slow-query-audit', [
            '--json' => true,
            '--skip-benchmarks' => true,
        ]);

        $output = Artisan::output();
    } finally {
        DB::setDefaultConnection($ambientBefore);
    }

    $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['privacy']['patient_names'])->toBeFalse()
        ->and($payload['privacy']['ktp_nik'])->toBeFalse()
        ->and(DB::getDefaultConnection())->toBe($ambientBefore);
});
