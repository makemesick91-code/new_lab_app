<?php

/*
| DMS-SPN4-READY-PILOT-1 R6-B5: rule 248 layer 1, and owner decision D-Q4-a.
|
| - The seven audit, status-log and financial event tables are append-only. The
|   model refuses update and delete, and PostgreSQL refuses UPDATE, DELETE and
|   TRUNCATE by trigger, query-builder writes included.
| - A lab order with status logs, and a user who performed an audited action,
|   cannot be deleted (the foreign keys restrict).
| - trx_inventory_movements stays a ledger (CG-10). Only a first batch link of
|   the movement's own product and branch, and updated_at, may change; anything
|   else, DELETE and TRUNCATE are refused.
| - The guards hold for a non-owner runtime role, which cannot disable them.
|
| Selected by the `FoundationGovernance` critical-gate token in both runner
| variants, and declared in critical_gate_mandatory_suites (CG-19). Helper
| prefix `fgaao`.
*/

use App\Models\User;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\NonOwnerRole;

const FGAAO_APPEND_ONLY = [
    'sys_audit_logs',
    'trx_lab_order_status_logs',
    'trx_rme_billing_dispute_events',
    'trx_rme_invoice_correction_events',
    'trx_rme_overpayment_obligation_events',
    'trx_rme_refund_request_events',
    'trx_rme_refund_settlement_events',
];

function fgaaoPg(): bool
{
    return DB::getDriverName() === 'pgsql';
}

/** The SQLSTATE and message $statement fails with, inside a savepoint; null when it succeeds. */
function fgaaoRefusal(Closure $statement): ?array
{
    try {
        DB::transaction($statement);
    } catch (QueryException $exception) {
        return [(string) ($exception->errorInfo[0] ?? $exception->getCode()), $exception->getMessage()];
    }

    return null;
}

function fgaaoAudit(?User $performer = null): AuditLog
{
    $performer ??= User::factory()->create();

    return AuditLog::query()->create([
        'entity_type' => 'synthetic.r6b5',
        'entity_id' => 1,
        'action' => AuditLog::ACTION_CREATE,
        'new_values' => ['synthetic' => true],
        'performed_by' => $performer->id,
        'performed_at' => now(),
    ]);
}

function fgaaoStatusLog(LabOrder $order): LabOrderStatusLog
{
    return LabOrderStatusLog::query()->create([
        'lab_order_id' => $order->id,
        'old_status' => null,
        'new_status' => 'DRAFT',
        'notes' => 'synthetic',
        'changed_by' => User::factory()->create()->id,
        'changed_at' => now(),
    ]);
}

/**
 * A synthetic event row whose parent does not exist. The fixture is inserted with
 * foreign-key enforcement off for this transaction only (session_replication_role
 * needs a superuser); the guard is then tested with enforcement back on.
 *
 * @return int the row id
 */
function fgaaoOrphanEvent(string $table): int
{
    $now = now();
    $row = match ($table) {
        'trx_rme_billing_dispute_events' => ['rme_billing_dispute_id' => 900000001, 'event_type' => 'REPORTED', 'to_status' => 'OPEN'],
        'trx_rme_invoice_correction_events' => ['rme_invoice_correction_id' => 900000001, 'to_status' => 'PENDING'],
        'trx_rme_overpayment_obligation_events' => ['rme_overpayment_obligation_id' => 900000001, 'to_status' => 'OPEN', 'actor_id' => 900000001],
        'trx_rme_refund_request_events' => ['rme_refund_request_id' => 900000001, 'to_status' => 'REQUESTED', 'actor_id' => 900000001],
        'trx_rme_refund_settlement_events' => ['rme_refund_settlement_id' => 900000001, 'to_status' => 'STARTED', 'actor_id' => 900000001],
    };

    DB::statement('SET LOCAL session_replication_role = replica');
    $id = (int) DB::table($table)->insertGetId($row + ['occurred_at' => $now, 'created_at' => $now]);
    DB::statement('SET LOCAL session_replication_role = origin');

    return $id;
}

function fgaaoSuperuser(): bool
{
    return (bool) DB::scalar('SELECT rolsuper FROM pg_catalog.pg_roles WHERE rolname = current_user');
}

/** @return array{0: InventoryMovement, 1: InventoryBatch} a movement without a batch, and a batch of its product and branch */
function fgaaoUnbatchedMovement(): array
{
    $movement = InventoryMovement::factory()->create(['inventory_batch_id' => null]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => $movement->branch_id,
        'product_id' => $movement->product_id,
        'supplier_id' => $movement->supplier_id,
    ]);

    return [$movement, $batch];
}

// --- Catalog ---------------------------------------------------------------------

it('installs a row and a TRUNCATE guard on every append-only table and on the inventory ledger', function () {
    $triggers = collect(DB::select(<<<'SQL'
        SELECT c.relname AS table_name, t.tgname, p.proname, t.tgenabled, t.tgtype
        FROM pg_trigger t
        JOIN pg_class c ON c.oid = t.tgrelid
        JOIN pg_proc p ON p.oid = t.tgfoid
        WHERE NOT t.tgisinternal AND p.proname IN ('dms_append_only_guard', 'dms_inventory_ledger_guard')
        SQL))->keyBy('tgname');

    // tgtype bits: 1 ROW, 2 BEFORE, 4 INSERT, 8 DELETE, 16 UPDATE, 32 TRUNCATE.
    $expect = fn (string $name, string $table, string $function, int $type) => expect($triggers->get($name))
        ->not->toBeNull("missing trigger {$name}")
        ->and($triggers->get($name)->table_name)->toBe($table)
        ->and($triggers->get($name)->proname)->toBe($function)
        ->and($triggers->get($name)->tgenabled)->toBe('O')
        ->and((int) $triggers->get($name)->tgtype)->toBe($type);

    foreach (FGAAO_APPEND_ONLY as $table) {
        $expect("{$table}_append_only", $table, 'dms_append_only_guard', 1 | 2 | 8 | 16);
        $expect("{$table}_append_only_trunc", $table, 'dms_append_only_guard', 2 | 32);
    }
    $expect('trx_inventory_movements_ledger_guard', 'trx_inventory_movements', 'dms_inventory_ledger_guard', 1 | 2 | 8 | 16);
    $expect('trx_inventory_movements_ledger_guard_trunc', 'trx_inventory_movements', 'dms_inventory_ledger_guard', 2 | 32);
    expect($triggers)->toHaveCount(16);

    $functions = collect(DB::select("SELECT proname, prosecdef, proconfig FROM pg_proc WHERE proname IN ('dms_append_only_guard', 'dms_inventory_ledger_guard')"));
    expect($functions)->toHaveCount(2);
    foreach ($functions as $function) {
        expect($function->prosecdef)->toBeFalse()
            ->and((string) $function->proconfig)->toContain('search_path=pg_catalog, pg_temp');
    }
})->skip(fn () => ! fgaaoPg(), 'PostgreSQL triggers');

it('restricts deleting a lab order with status logs and a user who performed an audited action', function () {
    $keys = collect(DB::select(<<<'SQL'
        SELECT conname, confdeltype::text || CASE WHEN convalidated THEN '/validated' ELSE '/NOT VALID' END AS state FROM pg_constraint
        WHERE conname IN ('sys_audit_logs_performed_by_foreign', 'trx_lab_order_status_logs_lab_order_id_foreign')
        SQL))->pluck('state', 'conname');

    // Swapped NOT VALID, then validated in its own step: never left unvalidated.
    expect($keys->sortKeys()->all())->toBe([
        'sys_audit_logs_performed_by_foreign' => 'r/validated',
        'trx_lab_order_status_logs_lab_order_id_foreign' => 'r/validated',
    ]);

    $order = LabOrder::factory()->create();
    fgaaoStatusLog($order);
    $performer = User::factory()->create();
    fgaaoAudit($performer);

    // PostgreSQL reports ON DELETE RESTRICT as foreign_key_violation (23503); the
    // message must name the restricting key, not some other one.
    $orderRefusal = fgaaoRefusal(fn () => DB::table('trx_lab_orders')->where('id', $order->id)->delete());
    $userRefusal = fgaaoRefusal(fn () => DB::table('users')->where('id', $performer->id)->delete());

    expect($orderRefusal[0] ?? null)->toBe('23503')
        ->and($orderRefusal[1] ?? '')->toContain('trx_lab_order_status_logs_lab_order_id_foreign')
        ->and($userRefusal[0] ?? null)->toBe('23503')
        ->and($userRefusal[1] ?? '')->toContain('sys_audit_logs_performed_by_foreign')
        ->and(DB::table('trx_lab_order_status_logs')->where('lab_order_id', $order->id)->count())->toBe(1);
})->skip(fn () => ! fgaaoPg(), 'PostgreSQL foreign keys');

it('migrates in short steps, runs again as a no-op, and reverses cleanly', function () {
    $path = database_path('migrations/2026_11_23_100001_enforce_rule_248_append_only_layer_one.php');
    $guards = fn (): int => (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid WHERE NOT t.tgisinternal AND p.proname IN ('dms_append_only_guard', 'dms_inventory_ledger_guard')");
    $keys = fn (): array => collect(DB::select("SELECT conname, confdeltype FROM pg_constraint WHERE conname IN ('sys_audit_logs_performed_by_foreign', 'trx_lab_order_status_logs_lab_order_id_foreign') ORDER BY conname"))
        ->pluck('confdeltype')->all();

    // No transaction around the whole migration: no lock is held across a validation scan.
    expect((require $path)->withinTransaction)->toBeFalse();

    (require $path)->up();
    expect($guards())->toBe(16)->and($keys())->toBe(['r', 'r']);

    (require $path)->down();
    expect($guards())->toBe(0)->and($keys())->toBe(['n', 'c']);

    (require $path)->up();
    expect($guards())->toBe(16)->and($keys())->toBe(['r', 'r']);
})->skip(fn () => ! fgaaoPg(), 'PostgreSQL triggers and foreign keys');

// --- Append-only tables -----------------------------------------------------------

it('accepts new audit and status-log rows and refuses every UPDATE and DELETE of them', function () {
    $audit = fgaaoAudit();
    $log = fgaaoStatusLog(LabOrder::factory()->create());

    foreach ([['sys_audit_logs', $audit->id, ['action' => 'UPDATE']], ['trx_lab_order_status_logs', $log->id, ['notes' => 'edited']]] as [$table, $id, $change]) {
        $update = fgaaoRefusal(fn () => DB::table($table)->where('id', $id)->update($change));
        $delete = fgaaoRefusal(fn () => DB::table($table)->where('id', $id)->delete());

        expect($update[1] ?? '')->toContain("{$table} is append-only (rule 248): UPDATE refused")
            ->and($delete[1] ?? '')->toContain("{$table} is append-only (rule 248): DELETE refused")
            ->and(DB::table($table)->where('id', $id)->exists())->toBeTrue();
    }
})->skip(fn () => ! fgaaoPg(), 'PostgreSQL triggers');

it('refuses UPDATE and DELETE of every financial and approval event row', function (string $table) {
    $id = fgaaoOrphanEvent($table);

    expect(fgaaoRefusal(fn () => DB::table($table)->where('id', $id)->update(['to_status' => 'TAMPERED']))[1] ?? '')
        ->toContain("{$table} is append-only (rule 248): UPDATE refused")
        ->and(fgaaoRefusal(fn () => DB::table($table)->where('id', $id)->delete())[1] ?? '')
        ->toContain("{$table} is append-only (rule 248): DELETE refused")
        ->and(DB::table($table)->where('id', $id)->exists())->toBeTrue();
})->with(array_slice(FGAAO_APPEND_ONLY, 2))
    ->skip(fn () => ! fgaaoPg() || ! fgaaoSuperuser(), 'PostgreSQL triggers; the orphan event fixture needs a superuser test identity');

it('refuses TRUNCATE of every append-only table, CASCADE included', function (string $table) {
    expect(fgaaoRefusal(fn () => DB::statement("TRUNCATE {$table} CASCADE"))[1] ?? '')
        ->toContain("{$table} is append-only (rule 248): TRUNCATE refused");
})->with(FGAAO_APPEND_ONLY)->skip(fn () => ! fgaaoPg(), 'PostgreSQL triggers');

it('refuses model updates and deletes of audit and status-log rows on every driver', function () {
    $audit = fgaaoAudit();
    $log = fgaaoStatusLog(LabOrder::factory()->create());

    expect(fn () => $audit->update(['action' => 'UPDATE']))->toThrow(LogicException::class, 'append-only (rule 248)')
        ->and(fn () => $audit->delete())->toThrow(LogicException::class, 'can never be deleted (rule 248)')
        ->and(fn () => $log->update(['notes' => 'edited']))->toThrow(LogicException::class, 'append-only (rule 248)')
        ->and(fn () => $log->delete())->toThrow(LogicException::class, 'can never be deleted (rule 248)');
});

// --- Inventory ledger (D-Q4-a) ---------------------------------------------------------

it('links a batch of the movement\'s own product and branch once, and lets updated_at change', function () {
    [$movement, $batch] = fgaaoUnbatchedMovement();

    $movement->update(['inventory_batch_id' => $batch->id]);
    DB::table('trx_inventory_movements')->where('id', $movement->id)->update(['updated_at' => now()->addMinute()]);

    expect((int) $movement->fresh()->inventory_batch_id)->toBe((int) $batch->id);
});

it('refuses every other movement change, a re-link, an unlink, a foreign batch, DELETE and TRUNCATE', function () {
    [$movement, $batch] = fgaaoUnbatchedMovement();
    $id = $movement->id;
    $table = DB::table('trx_inventory_movements');
    $refused = fn (array $change): string => fgaaoRefusal(fn () => DB::table('trx_inventory_movements')->where('id', $id)->update($change))[1] ?? 'ACCEPTED';

    // Ledger columns never change.
    foreach ([['quantity_in' => 999], ['quantity_out' => 1], ['unit_cost' => 1], ['movement_date' => '2020-01-01'], ['notes' => 'edited'], ['movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT], ['created_at' => now()->subYear()]] as $change) {
        expect($refused($change))->toContain('only inventory_batch_id and updated_at may change');
    }

    // A batch of another product, or of another branch, is refused.
    $otherProduct = InventoryBatch::factory()->create(['branch_id' => $movement->branch_id]);
    $otherBranch = InventoryBatch::factory()->create(['product_id' => $movement->product_id]);
    expect($refused(['inventory_batch_id' => $otherProduct->id]))->toContain("is not of this movement's product and branch")
        ->and($refused(['inventory_batch_id' => $otherBranch->id]))->toContain("is not of this movement's product and branch");

    // Linked once; never re-linked or unlinked.
    $table->clone()->where('id', $id)->update(['inventory_batch_id' => $batch->id]);
    $second = InventoryBatch::factory()->create(['branch_id' => $movement->branch_id, 'product_id' => $movement->product_id]);
    expect($refused(['inventory_batch_id' => $second->id]))->toContain('a linked batch is never changed or removed')
        ->and($refused(['inventory_batch_id' => null]))->toContain('a linked batch is never changed or removed')
        ->and(fgaaoRefusal(fn () => DB::table('trx_inventory_movements')->where('id', $id)->delete())[1] ?? '')->toContain('is a ledger (CG-10, D-Q4-a): DELETE refused')
        ->and(fgaaoRefusal(fn () => DB::statement('TRUNCATE trx_inventory_movements CASCADE'))[1] ?? '')->toContain('is a ledger (CG-10, D-Q4-a): TRUNCATE refused')
        ->and((int) DB::table('trx_inventory_movements')->where('id', $id)->value('inventory_batch_id'))->toBe((int) $batch->id);
})->skip(fn () => ! fgaaoPg(), 'PostgreSQL trigger');

it('refuses model changes to a movement outside the batch link, and deletes, on every driver', function () {
    [$movement, $batch] = fgaaoUnbatchedMovement();

    expect(fn () => $movement->fresh()->update(['quantity_in' => 999]))->toThrow(LogicException::class, 'quantity_in may not change')
        ->and(fn () => $movement->fresh()->delete())->toThrow(LogicException::class, 'can never be deleted');

    $movement->fresh()->update(['inventory_batch_id' => $batch->id]);
    $second = InventoryBatch::factory()->create(['branch_id' => $movement->branch_id, 'product_id' => $movement->product_id]);

    expect(fn () => $movement->fresh()->update(['inventory_batch_id' => $second->id]))->toThrow(LogicException::class, 'a linked batch is never changed or removed')
        ->and(fn () => $movement->fresh()->update(['inventory_batch_id' => null]))->toThrow(LogicException::class, 'a linked batch is never changed or removed');
});

// --- Non-owner runtime ---------------------------------------------------------------

it('keeps every guard for a non-owner runtime role holding DML grants, which cannot disable them', function () {
    if ($reason = NonOwnerRole::unavailableReason()) {
        $this->markTestSkipped($reason);
    }

    $audit = fgaaoAudit();
    [$movement] = fgaaoUnbatchedMovement();
    $role = NonOwnerRole::create();

    [$update, $delete, $ledger, $disable] = NonOwnerRole::run($role, fn (): array => [
        fgaaoRefusal(fn () => DB::table('sys_audit_logs')->where('id', $audit->id)->update(['action' => 'UPDATE']))[1] ?? 'ACCEPTED',
        fgaaoRefusal(fn () => DB::table('sys_audit_logs')->where('id', $audit->id)->delete())[1] ?? 'ACCEPTED',
        fgaaoRefusal(fn () => DB::table('trx_inventory_movements')->where('id', $movement->id)->update(['quantity_in' => 999]))[1] ?? 'ACCEPTED',
        NonOwnerRole::sqlstate(fn () => DB::statement('ALTER TABLE sys_audit_logs DISABLE TRIGGER sys_audit_logs_append_only')),
    ]);

    expect($update)->toContain('append-only (rule 248): UPDATE refused')
        ->and($delete)->toContain('append-only (rule 248): DELETE refused')
        ->and($ledger)->toContain('only inventory_batch_id and updated_at may change')
        ->and($disable)->toBe('42501');
});
