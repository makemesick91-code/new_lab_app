<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * DMS-SPN4-READY-PILOT-1 G6 / R6-B1 — PostgreSQL foreign-key introspection that
 * gives the same answer whoever owns the tables.
 *
 * WHY NOT information_schema
 * --------------------------
 * The information_schema views are filtered by the CURRENT role's relationship to
 * each table, not just by what exists:
 *
 *   constraint_column_usage   WHERE pg_has_role(<owner of the referenced table>, 'USAGE')
 *   table_constraints         owner, or a non-SELECT privilege on the table
 *   key_column_usage          owner, or SELECT/INSERT/UPDATE/REFERENCES on the column
 *
 * Today the application role owns every table, so those views show everything.
 * Rule 245 (R6) moves ownership to a NOLOGIN owner role and leaves the runtime role
 * with grants only. From then on constraint_column_usage returns ZERO rows to the
 * runtime, which turned two checks into wrong answers (proven on PostgreSQL 16):
 *
 *   - the patient-merge guard saw no foreign key to mst_patients at all, so an
 *     unregistered patient table no longer blocked a merge (fail OPEN);
 *   - DQ1-CONSTRAINT-001 reported every critical foreign key as missing, so the
 *     deploy's `--fail-on=error` stopped every deploy (false FAIL).
 *
 * The pg_catalog tables used here (pg_constraint, pg_class, pg_namespace,
 * pg_attribute) are readable by PUBLIC and are not row-filtered by ownership or
 * privilege, so the answer does not change when the role does.
 *
 * Constraints are matched by OID, never by name: a foreign-key name is unique per
 * table, not per schema, and the old name-based join could pair one table's key
 * with another table's referenced column. Both tables are resolved in the
 * connection's current schema (what Schema::hasTable() checks); a referencing
 * table in ANOTHER schema is still returned, flagged, so a caller can fail closed
 * on it instead of silently ignoring it.
 */
class ForeignKeyCatalog
{
    /**
     * Every column, in any schema, covered by a foreign key that references
     * $referencedTable in the current schema. One row per (constraint, column).
     *
     * @return list<array{schema: string, table: string, column: string, constraint: string, in_current_schema: bool}>
     */
    public function referencesTo(string $referencedTable, ?Connection $connection = null): array
    {
        $connection = $this->postgres($connection);

        $rows = $connection->select(
            "SELECT src_ns.nspname AS table_schema,
                    src.relname AS table_name,
                    att.attname AS column_name,
                    con.conname AS constraint_name,
                    (src_ns.nspname = current_schema()) AS in_current_schema
               FROM pg_catalog.pg_constraint con
               JOIN pg_catalog.pg_class ref ON ref.oid = con.confrelid
               JOIN pg_catalog.pg_namespace ref_ns ON ref_ns.oid = ref.relnamespace
               JOIN pg_catalog.pg_class src ON src.oid = con.conrelid
               JOIN pg_catalog.pg_namespace src_ns ON src_ns.oid = src.relnamespace
               JOIN pg_catalog.pg_attribute att
                 ON att.attrelid = con.conrelid AND att.attnum = ANY (con.conkey)
              WHERE con.contype = 'f'
                AND ref_ns.nspname = current_schema()
                AND ref.relname = ?
                AND NOT att.attisdropped
              ORDER BY src_ns.nspname, src.relname, att.attname, con.conname",
            [$referencedTable],
            false,
        );

        return array_values(array_map(static fn (object $row): array => [
            'schema' => (string) $row->table_schema,
            'table' => (string) $row->table_name,
            'column' => (string) $row->column_name,
            'constraint' => (string) $row->constraint_name,
            'in_current_schema' => (bool) $row->in_current_schema,
        ], $rows));
    }

    /**
     * True when $table.$column (current schema) is covered by a foreign key to
     * $referencedTable (current schema).
     */
    public function exists(string $table, string $column, string $referencedTable, ?Connection $connection = null): bool
    {
        $connection = $this->postgres($connection);

        $row = $connection->selectOne(
            "SELECT 1 AS ok
               FROM pg_catalog.pg_constraint con
               JOIN pg_catalog.pg_class src ON src.oid = con.conrelid
               JOIN pg_catalog.pg_namespace src_ns ON src_ns.oid = src.relnamespace
               JOIN pg_catalog.pg_class ref ON ref.oid = con.confrelid
               JOIN pg_catalog.pg_namespace ref_ns ON ref_ns.oid = ref.relnamespace
               JOIN pg_catalog.pg_attribute att
                 ON att.attrelid = con.conrelid AND att.attnum = ANY (con.conkey)
              WHERE con.contype = 'f'
                AND src_ns.nspname = current_schema()
                AND ref_ns.nspname = current_schema()
                AND src.relname = ?
                AND att.attname = ?
                AND ref.relname = ?
                AND NOT att.attisdropped
              LIMIT 1",
            [$table, $column, $referencedTable],
            false,
        );

        return $row !== null;
    }

    private function postgres(?Connection $connection): Connection
    {
        $connection ??= DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('ForeignKeyCatalog reads the PostgreSQL catalog; use the schema builder on other drivers.');
        }

        return $connection;
    }
}
