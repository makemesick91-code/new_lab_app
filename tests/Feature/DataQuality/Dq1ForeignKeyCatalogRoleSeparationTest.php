<?php

use App\Support\Database\ForeignKeyCatalog;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\Database\NonOwnerRole;

/*
 * DMS-SPN4-READY-PILOT-1 G6 / R6-B1 — DQ1-CONSTRAINT-001 must give the same answer
 * whoever owns the tables.
 *
 * The check used information_schema.constraint_column_usage, which only lists tables
 * owned by the current role. After the rule 245 ownership split every critical
 * foreign key read as missing, and the deploy's `--fail-on=error` stopped every
 * deploy (false FAIL). It also had no schema filter, so a same-named table in
 * another schema could satisfy it (false PASS). It now reads pg_catalog, by OID, in
 * the current schema.
 *
 * Selected by the `Dq1` token in BOTH critical-gate variants (CG-19).
 */

uses()->group('DataQuality', 'Dq1');

/** The pre-fix lookup, kept verbatim as the regression reference for the root cause. */
function r6b1LegacyDq1ForeignKeyExists(string $table, string $column, string $referencedTable): bool
{
    return DB::selectOne(
        'SELECT 1 AS ok
         FROM information_schema.table_constraints tc
         JOIN information_schema.key_column_usage kcu
           ON tc.constraint_name = kcu.constraint_name
          AND tc.table_schema = kcu.table_schema
         JOIN information_schema.constraint_column_usage ccu
           ON ccu.constraint_name = tc.constraint_name
          AND ccu.table_schema = tc.table_schema
         WHERE tc.constraint_type = ?
           AND tc.table_name = ?
           AND kcu.column_name = ?
           AND ccu.table_name = ?
         LIMIT 1',
        ['FOREIGN KEY', $table, $column, $referencedTable],
    ) !== null;
}

/** @return array{status: string, missing: list<string>, exit: int} */
function r6b1Dq1ConstraintCheck(): array
{
    $exit = Artisan::call('data-quality:dq1-audit', ['--json' => true, '--fail-on' => 'error']);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $check = collect($payload['checks'])->firstWhere('check_id', 'DQ1-CONSTRAINT-001');

    return ['status' => $check['status'], 'missing' => $check['details']['missing'], 'exit' => $exit];
}

function r6b1DropForeignKey(string $table, string $column, string $referencedTable): string
{
    $name = (string) DB::scalar(
        "SELECT con.conname
           FROM pg_catalog.pg_constraint con
           JOIN pg_catalog.pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY (con.conkey)
          WHERE con.contype = 'f' AND con.conrelid = ?::regclass AND con.confrelid = ?::regclass AND att.attname = ?
          LIMIT 1",
        [$table, $referencedTable, $column],
    );

    expect($name)->not->toBe('');
    DB::statement("ALTER TABLE \"{$table}\" DROP CONSTRAINT \"{$name}\"");

    return $name;
}

it('finds every configured critical foreign key through pg_catalog', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('pg_catalog introspection is PostgreSQL-only; SQLite keeps the PRAGMA path.');
    }

    $catalog = app(ForeignKeyCatalog::class);
    $configured = config('dq1.constraint_checks.DQ1-CONSTRAINT-001.foreign_keys');

    expect($configured)->not->toBeEmpty();

    foreach ($configured as $fk) {
        expect($catalog->exists($fk['table'], $fk['column'], $fk['references']))->toBeTrue();
    }

    // Precision: the right column to the wrong table, or the wrong column to the right table, is not a match.
    expect($catalog->exists('trx_rme_payments', 'rme_invoice_id', 'mst_branches'))->toBeFalse()
        ->and($catalog->exists('trx_rme_payments', 'id', 'trx_rme_invoices'))->toBeFalse()
        ->and($catalog->exists('trx_rme_payments_missing_table', 'rme_invoice_id', 'trx_rme_invoices'))->toBeFalse();
});

it('reports the critical foreign keys present for the owner and for the non-owner runtime alike', function (): void {
    if ($reason = NonOwnerRole::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    $owner = r6b1Dq1ConstraintCheck();
    $role = NonOwnerRole::create();

    [$runtime, $runtimeLegacyFound, $runtimeOwnsTables] = NonOwnerRole::run($role, fn (): array => [
        r6b1Dq1ConstraintCheck(),
        r6b1LegacyDq1ForeignKeyExists('trx_rme_payments', 'rme_invoice_id', 'trx_rme_invoices'),
        (bool) DB::scalar("SELECT pg_has_role(relowner, 'USAGE') FROM pg_catalog.pg_class WHERE oid = 'trx_rme_payments'::regclass"),
    ]);

    expect($runtimeOwnsTables)->toBeFalse()
        // Root cause, reproduced: the old lookup cannot see the key as runtime.
        ->and(r6b1LegacyDq1ForeignKeyExists('trx_rme_payments', 'rme_invoice_id', 'trx_rme_invoices'))->toBeTrue()
        ->and($runtimeLegacyFound)->toBeFalse()
        // Fixed check: no false FAIL, and the same answer for both roles.
        ->and($owner['status'])->toBe('PASS')
        ->and($owner['missing'])->toBe([])
        ->and($runtime['status'])->toBe('PASS')
        ->and($runtime['missing'])->toBe([]);
});

it('still reports a really missing foreign key, to the owner and to the non-owner runtime, and fails on error', function (): void {
    if ($reason = NonOwnerRole::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    r6b1DropForeignKey('trx_rme_payments', 'rme_invoice_id', 'trx_rme_invoices');
    $owner = r6b1Dq1ConstraintCheck();
    $role = NonOwnerRole::create();
    $runtime = NonOwnerRole::run($role, fn (): array => r6b1Dq1ConstraintCheck());

    expect($owner['status'])->toBe('FAIL')
        ->and($owner['missing'])->toBe(['trx_rme_payments.rme_invoice_id -> trx_rme_invoices'])
        ->and($owner['exit'])->not->toBe(0)
        ->and($runtime['status'])->toBe('FAIL')
        ->and($runtime['missing'])->toBe(['trx_rme_payments.rme_invoice_id -> trx_rme_invoices'])
        ->and($runtime['exit'])->not->toBe(0);
});

it('is not satisfied by a same-named foreign key in another schema', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('schemas are PostgreSQL-only.');
    }

    if (! DB::scalar("SELECT has_database_privilege(current_database(), 'CREATE')")) {
        $this->markTestSkipped('the test database identity cannot create a schema');
    }

    r6b1DropForeignKey('trx_rme_payments', 'rme_invoice_id', 'trx_rme_invoices');
    $schema = 'r6b1_other_'.bin2hex(random_bytes(4));
    DB::statement("CREATE SCHEMA \"{$schema}\"");
    DB::statement("CREATE TABLE \"{$schema}\".trx_rme_invoices (id bigserial PRIMARY KEY)");
    DB::statement("CREATE TABLE \"{$schema}\".trx_rme_payments (id bigserial PRIMARY KEY, rme_invoice_id bigint REFERENCES \"{$schema}\".trx_rme_invoices (id))");

    // Root cause, reproduced: the old lookup had no schema filter and took the other schema's key.
    expect(r6b1LegacyDq1ForeignKeyExists('trx_rme_payments', 'rme_invoice_id', 'trx_rme_invoices'))->toBeTrue()
        ->and(app(ForeignKeyCatalog::class)->exists('trx_rme_payments', 'rme_invoice_id', 'trx_rme_invoices'))->toBeFalse()
        ->and(r6b1Dq1ConstraintCheck()['missing'])->toBe(['trx_rme_payments.rme_invoice_id -> trx_rme_invoices']);
});

it('never introspects constraints through the ownership-filtered information_schema views in application code', function (): void {
    $forbidden = '/information_schema\s*\.\s*(constraint_column_usage|key_column_usage|table_constraints|referential_constraints|constraint_table_usage|check_constraints)\b/i';
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() === 'php' && preg_match($forbidden, $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([]);
});
