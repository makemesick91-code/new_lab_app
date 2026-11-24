<?php

namespace Tests\Support\Database;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Test-only writes to an append-only table (rule 248) or the inventory ledger
 * (CG-10, D-Q4-a), made as the table owner.
 *
 * Two uses, both test fixtures:
 * - delete(): cleanup of rows a committed concurrency test wrote through a
 *   second connection. RefreshDatabase cannot roll those back, and PostgreSQL
 *   refuses the delete by trigger.
 * - update(): a fixture that recreates the shape older code wrote, inside the
 *   rolled-back test transaction.
 *
 * Rule 248 leaves such writes to the owner role. The test connection owns the
 * tables it migrated. In one transaction (a savepoint inside the test
 * transaction) the helper:
 * 1. disables only the table's guard triggers;
 * 2. writes;
 * 3. enables the triggers again.
 *
 * A failure rolls the whole transaction back, so the triggers are never left off.
 * The runtime never owns a table (rule 245) and cannot do this: disabling a
 * trigger needs ownership (SQLSTATE 42501). Application code never calls this.
 */
final class AppendOnlyOwnerWrite
{
    private const GUARD_FUNCTIONS = ['dms_append_only_guard', 'dms_inventory_ledger_guard'];

    /** @param  Closure(Builder): Builder  $scope  selects the rows the test itself wrote */
    public static function delete(ConnectionInterface $connection, string $table, Closure $scope): int
    {
        return self::asOwner($connection, $table, fn (): int => $scope($connection->table($table))->delete());
    }

    /**
     * @param  Closure(Builder): Builder  $scope
     * @param  array<string, mixed>  $values
     */
    public static function update(ConnectionInterface $connection, string $table, Closure $scope, array $values): int
    {
        return self::asOwner($connection, $table, fn (): int => $scope($connection->table($table))->update($values));
    }

    /** @param  Closure(): int  $write */
    private static function asOwner(ConnectionInterface $connection, string $table, Closure $write): int
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return $write();
        }

        return $connection->transaction(function () use ($connection, $table, $write): int {
            $triggers = array_map(
                fn (object $row): string => (string) $row->tgname,
                $connection->select(
                    'SELECT t.tgname FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid
                     WHERE t.tgrelid = ?::regclass AND NOT t.tgisinternal AND p.proname IN (?, ?)',
                    [$table, ...self::GUARD_FUNCTIONS],
                ),
            );

            foreach ($triggers as $trigger) {
                $connection->statement("ALTER TABLE \"{$table}\" DISABLE TRIGGER \"{$trigger}\"");
            }

            $written = $write();

            foreach ($triggers as $trigger) {
                $connection->statement("ALTER TABLE \"{$table}\" ENABLE TRIGGER \"{$trigger}\"");
            }

            return $written;
        });
    }
}
