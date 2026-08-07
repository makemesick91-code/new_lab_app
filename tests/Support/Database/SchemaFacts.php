<?php

namespace Tests\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * CICD-FIX-2 — driver-portable schema facts for tests.
 *
 * The Inventory schema tests originally asserted against raw SQLite `PRAGMA`
 * output. That works only on SQLite, so on the authoritative PostgreSQL CI
 * connection every one of them died with
 * `SQLSTATE[42601] syntax error at or near "PRAGMA"`.
 *
 * The contracts those tests express — a column defaults to 'draft', a unique
 * index covers exactly [receipt_number], a quantity column is numeric(18,4) —
 * are all database-agnostic. Only the SPELLING of the metadata differs:
 *
 *   default 'draft'   SQLite: "'draft'"   PostgreSQL: "'draft'::character varying"
 *   decimal type      SQLite: "numeric"   PostgreSQL: "numeric"  (never "decimal")
 *
 * So this helper reads through Laravel's own database-agnostic introspection
 * (Schema::getIndexes / getColumns / getColumnType) and normalises the spelling,
 * letting each test assert the SEMANTIC contract it always meant.
 *
 * Unsupported drivers fail closed: they must never silently fall back to
 * another driver's semantics.
 */
final class SchemaFacts
{
    /** Drivers whose metadata spelling this helper knows how to normalise. */
    private const SUPPORTED_DRIVERS = ['pgsql', 'sqlite'];

    public static function driver(): string
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new RuntimeException(
                "SchemaFacts does not support the '{$driver}' driver. Add explicit support rather than "
                .'falling back to another driver’s metadata semantics.'
            );
        }

        return $driver;
    }

    /**
     * Every index on a table, normalised to {name, unique, columns}.
     *
     * @return list<array{name: string, unique: bool, columns: list<string>}>
     */
    public static function indexes(string $table): array
    {
        self::driver();

        return array_map(static fn (array $index): array => [
            'name' => (string) ($index['name'] ?? ''),
            'unique' => (bool) ($index['unique'] ?? false),
            'columns' => array_values(array_map('strval', (array) ($index['columns'] ?? []))),
        ], Schema::getIndexes($table));
    }

    /**
     * @return list<string>
     */
    public static function indexNames(string $table): array
    {
        return array_column(self::indexes($table), 'name');
    }

    /**
     * @return list<string>
     */
    public static function indexColumns(string $table, string $indexName): array
    {
        foreach (self::indexes($table) as $index) {
            if ($index['name'] === $indexName) {
                return $index['columns'];
            }
        }

        return [];
    }

    /**
     * Is there a unique index covering exactly these columns, in this order?
     *
     * @param  list<string>  $columns
     */
    public static function hasUniqueIndexOn(string $table, array $columns): bool
    {
        foreach (self::indexes($table) as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * A column's default, normalised to the value itself.
     *
     * PostgreSQL reports `'draft'::character varying` and SQLite reports
     * `'draft'`; both normalise to `draft`.
     */
    public static function columnDefault(string $table, string $column): ?string
    {
        self::driver();

        foreach (Schema::getColumns($table) as $definition) {
            if (($definition['name'] ?? null) !== $column) {
                continue;
            }

            $default = $definition['default'] ?? null;

            return $default === null ? null : self::normaliseDefault((string) $default);
        }

        return null;
    }

    /**
     * Strip a PostgreSQL type cast and the surrounding quotes.
     */
    public static function normaliseDefault(string $raw): string
    {
        $value = trim($raw);

        // PostgreSQL: 'draft'::character varying -> 'draft'
        if (($castAt = strpos($value, '::')) !== false) {
            $value = substr($value, 0, $castAt);
        }

        $value = trim($value);

        // Both drivers quote string literals.
        if (strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Canonical type name. PostgreSQL never reports "decimal" — its native
     * spelling is "numeric" — so both collapse onto one canonical name.
     */
    public static function columnTypeName(string $table, string $column): string
    {
        self::driver();

        $type = strtolower(Schema::getColumnType($table, $column));

        return match ($type) {
            'numeric', 'decimal' => 'decimal',
            default => $type,
        };
    }

    /**
     * Declared numeric precision and scale, e.g. numeric(18,4) -> [18, 4].
     *
     * Read from the full column definition, which both supported drivers
     * expose, so the same assertion proves the same contract on each.
     *
     * @return array{precision: int|null, scale: int|null}
     */
    public static function numericPrecisionScale(string $table, string $column): array
    {
        self::driver();

        $definition = strtolower(Schema::getColumnType($table, $column, true));

        if (preg_match('/(?:numeric|decimal)\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)/', $definition, $matches) === 1) {
            return ['precision' => (int) $matches[1], 'scale' => (int) $matches[2]];
        }

        return ['precision' => null, 'scale' => null];
    }
}
