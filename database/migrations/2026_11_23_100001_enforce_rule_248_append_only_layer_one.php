<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| DMS-SPN4-READY-PILOT-1 R6-B5: rule 248 layer 1 (append-only audit trail), with
| owner decision D-Q4-a (the inventory ledger column guard).
|
| PostgreSQL only, like every trigger and CHECK guard in this schema; SQLite has
| no TRUNCATE and the model guards cover it. Additive: triggers and two foreign
| keys tightened to RESTRICT. No row is changed.
|
| 1. The seven append-only tables refuse every UPDATE, DELETE and TRUNCATE.
| 2. trx_inventory_movements stays a ledger (CG-10). An UPDATE may only:
|    - link a batch to a movement that has none, when the batch has the
|      movement's own product and branch (the governed batch backfill);
|    - change updated_at.
|    Any other column change, a re-link or unlink, DELETE and TRUNCATE are
|    refused.
| 3. A lab order that has status logs, and a user who performed an audited
|    action, can no longer be deleted (the foreign keys restrict on delete).
|
| The functions are owned by the migrating role (the schema owner after R6-D),
| pin search_path and are not SECURITY DEFINER. Only the table owner can
| disable these triggers; the runtime never owns a table after R6-D (rule 245).
|
| Locks. The deploy migrates while the application serves requests, and
| dropping a foreign key takes ACCESS EXCLUSIVE on BOTH tables, users included.
| Measured on PostgreSQL 16 with 2,000,000 audit rows, one transaction held
| that lock for the whole validation scan, and every read of users waited. So
| each step commits on its own, under a lock_timeout:
| 1. the guards;
| 2. per key, a swap to RESTRICT ... NOT VALID (no scan; milliseconds);
| 3. VALIDATE CONSTRAINT, which scans under SHARE UPDATE EXCLUSIVE and never
|    blocks reads or writes.
| A lock that cannot be taken in time fails the migration, so a long-running
| transaction never queues every request behind it. Each step is idempotent,
| so the migration can simply run again. Old rows already satisfy the keys:
| the previous keys enforced them.
*/
return new class extends Migration
{
    /** Each step commits on its own (see Locks above). */
    public $withinTransaction = false;

    private const LOCK_TIMEOUT = '5s';

    /** @var list<string> */
    private const APPEND_ONLY = [
        'sys_audit_logs',
        'trx_lab_order_status_logs',
        'trx_rme_billing_dispute_events',
        'trx_rme_invoice_correction_events',
        'trx_rme_overpayment_obligation_events',
        'trx_rme_refund_request_events',
        'trx_rme_refund_settlement_events',
    ];

    private const LEDGER = 'trx_inventory_movements';

    /** @var array<string, array{0: string, 1: string, 2: string, 3: string}> table => [constraint, column, referenced table, previous ON DELETE] */
    private const RESTRICTED_FOREIGN_KEYS = [
        'sys_audit_logs' => ['sys_audit_logs_performed_by_foreign', 'performed_by', 'users', 'SET NULL'],
        'trx_lab_order_status_logs' => ['trx_lab_order_status_logs_lab_order_id_foreign', 'lab_order_id', 'trx_lab_orders', 'CASCADE'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->step(function (): void {
            $this->installGuards();
        });

        foreach (self::RESTRICTED_FOREIGN_KEYS as $table => [$constraint, $column, $referenced]) {
            $this->step(function () use ($table, $constraint, $column, $referenced): void {
                if ($this->deleteAction($table, $constraint) !== 'r') {
                    $this->replaceForeignKey($table, $constraint, $column, $referenced, 'RESTRICT');
                }
            });
            $this->step(function () use ($table, $constraint): void {
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
            });
        }
    }

    private function installGuards(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION dms_append_only_guard() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only (rule 248): % refused', TG_TABLE_NAME, TG_OP;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION dms_inventory_ledger_guard() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $$
            DECLARE
                batch_matches boolean;
            BEGIN
                IF TG_OP <> 'UPDATE' THEN
                    RAISE EXCEPTION '% is a ledger (CG-10, D-Q4-a): % refused', TG_TABLE_NAME, TG_OP;
                END IF;

                -- Every column except the two approved ones, including any column
                -- added later: a new column is guarded without editing this function.
                IF (to_jsonb(NEW) - 'inventory_batch_id' - 'updated_at')
                    IS DISTINCT FROM (to_jsonb(OLD) - 'inventory_batch_id' - 'updated_at') THEN
                    RAISE EXCEPTION '% row %: only inventory_batch_id and updated_at may change (CG-10, D-Q4-a)', TG_TABLE_NAME, OLD.id;
                END IF;

                IF NEW.inventory_batch_id IS DISTINCT FROM OLD.inventory_batch_id THEN
                    IF OLD.inventory_batch_id IS NOT NULL OR NEW.inventory_batch_id IS NULL THEN
                        RAISE EXCEPTION '% row %: a linked batch is never changed or removed (CG-10, D-Q4-a)', TG_TABLE_NAME, OLD.id;
                    END IF;

                    EXECUTE format(
                        'SELECT EXISTS (SELECT 1 FROM %I.inv_inventory_batches WHERE id = $1 AND product_id = $2 AND branch_id = $3)',
                        TG_TABLE_SCHEMA
                    ) INTO batch_matches USING NEW.inventory_batch_id, NEW.product_id, NEW.branch_id;

                    IF NOT batch_matches THEN
                        RAISE EXCEPTION '% row %: batch % is not of this movement''s product and branch (CG-10, D-Q4-a)', TG_TABLE_NAME, OLD.id, NEW.inventory_batch_id;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        foreach (self::APPEND_ONLY as $table) {
            DB::statement("CREATE OR REPLACE TRIGGER {$table}_append_only BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION dms_append_only_guard()");
            DB::statement("CREATE OR REPLACE TRIGGER {$table}_append_only_trunc BEFORE TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION dms_append_only_guard()");
        }

        $ledger = self::LEDGER;
        DB::statement("CREATE OR REPLACE TRIGGER {$ledger}_ledger_guard BEFORE UPDATE OR DELETE ON {$ledger} FOR EACH ROW EXECUTE FUNCTION dms_inventory_ledger_guard()");
        DB::statement("CREATE OR REPLACE TRIGGER {$ledger}_ledger_guard_trunc BEFORE TRUNCATE ON {$ledger} FOR EACH STATEMENT EXECUTE FUNCTION dms_inventory_ledger_guard()");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::RESTRICTED_FOREIGN_KEYS as $table => [$constraint, $column, $referenced, $previous]) {
            $this->step(function () use ($table, $constraint, $column, $referenced, $previous): void {
                $this->replaceForeignKey($table, $constraint, $column, $referenced, $previous);
            });
            $this->step(function () use ($table, $constraint): void {
                DB::statement("ALTER TABLE {$table} VALIDATE CONSTRAINT {$constraint}");
            });
        }

        $this->step(function (): void {
            $ledger = self::LEDGER;
            DB::statement("DROP TRIGGER IF EXISTS {$ledger}_ledger_guard_trunc ON {$ledger}");
            DB::statement("DROP TRIGGER IF EXISTS {$ledger}_ledger_guard ON {$ledger}");

            foreach (self::APPEND_ONLY as $table) {
                DB::statement("DROP TRIGGER IF EXISTS {$table}_append_only_trunc ON {$table}");
                DB::statement("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table}");
            }

            DB::statement('DROP FUNCTION IF EXISTS dms_inventory_ledger_guard()');
            DB::statement('DROP FUNCTION IF EXISTS dms_append_only_guard()');
        });
    }

    /** One short transaction under a lock timeout; a savepoint when already inside one (tests). */
    private function step(Closure $work): void
    {
        DB::transaction(function () use ($work): void {
            DB::statement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'");
            $work();
        });
    }

    private function deleteAction(string $table, string $constraint): ?string
    {
        $action = DB::scalar('SELECT confdeltype FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass', [$constraint, $table]);

        return $action === null ? null : (string) $action;
    }

    /** Swaps the key without scanning (NOT VALID); the caller validates in its own step. */
    private function replaceForeignKey(string $table, string $constraint, string $column, string $referenced, string $onDelete): void
    {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} FOREIGN KEY ({$column}) REFERENCES {$referenced} (id) ON UPDATE CASCADE ON DELETE {$onDelete} NOT VALID");
    }
};
