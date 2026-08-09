<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\Database\SchemaFacts;

uses()->group('Inventory', 'Cicd');

/*
 * CICD-FIX-2 — the portability layer itself.
 *
 * The Inventory schema tests used raw SQLite PRAGMA, so on the authoritative
 * PostgreSQL connection they died with
 * `SQLSTATE[42601] syntax error at or near "PRAGMA"`.
 *
 * SchemaFacts reads through Laravel's database-agnostic introspection and
 * normalises only the driver-specific spelling. These tests prove the layer
 * works on whichever supported driver the suite runs on, and — critically —
 * that an unsupported driver fails loudly instead of silently falling back to
 * another driver's semantics.
 */

it('inspects indexes on the active driver', function () {
    $indexes = SchemaFacts::indexes('trx_goods_receipts');

    expect($indexes)->not->toBeEmpty();

    foreach ($indexes as $index) {
        expect($index)->toHaveKeys(['name', 'unique', 'columns'])
            ->and($index['unique'])->toBeBool()
            ->and($index['columns'])->toBeArray();
    }

    // A known composite index resolves to its columns, in order.
    expect(SchemaFacts::indexColumns('trx_goods_receipts', 'trx_goods_receipts_branch_status_index'))
        ->toBe(['branch_id', 'status']);
});

it('detects a unique index by its column shape', function () {
    expect(SchemaFacts::hasUniqueIndexOn('trx_goods_receipts', ['receipt_number']))->toBeTrue()
        // A column set with no unique index must not report one.
        ->and(SchemaFacts::hasUniqueIndexOn('trx_goods_receipts', ['notes']))->toBeFalse();
});

it('normalises column defaults across drivers', function () {
    // PostgreSQL: 'draft'::character varying — SQLite: 'draft'
    expect(SchemaFacts::columnDefault('trx_goods_receipts', 'status'))->toBe('draft');

    // The normaliser itself, over both drivers' spellings.
    expect(SchemaFacts::normaliseDefault("'draft'::character varying"))->toBe('draft')
        ->and(SchemaFacts::normaliseDefault("'draft'"))->toBe('draft')
        ->and(SchemaFacts::normaliseDefault('0'))->toBe('0')
        ->and(SchemaFacts::normaliseDefault("'IDR'::bpchar"))->toBe('IDR');
});

it('reports a canonical decimal type name on the active driver', function () {
    // PostgreSQL never says "decimal" — its native spelling is "numeric".
    expect(SchemaFacts::columnTypeName('trx_inventory_movements', 'quantity_in'))->toBe('decimal');
});

it('reads numeric precision and scale where the driver can express it', function () {
    $numeric = SchemaFacts::numericPrecisionScale('trx_inventory_movements', 'quantity_in');

    if (DB::getDriverName() === 'pgsql') {
        expect($numeric['precision'])->toBe(18)
            ->and($numeric['scale'])->toBe(4);

        return;
    }

    // SQLiteGrammar::typeDecimal() emits a bare `numeric`, so SQLite records no
    // precision at all. Asserting that explicitly keeps the difference visible
    // rather than hiding it behind a skip.
    expect($numeric['precision'])->toBeNull()
        ->and($numeric['scale'])->toBeNull();
});

it('fails closed on an unsupported driver instead of guessing', function () {
    // Never silently fall back to another driver's metadata semantics.
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getDriverName')->andReturn('mysql');

    expect(fn () => SchemaFacts::driver())
        ->toThrow(RuntimeException::class, 'does not support');
});
