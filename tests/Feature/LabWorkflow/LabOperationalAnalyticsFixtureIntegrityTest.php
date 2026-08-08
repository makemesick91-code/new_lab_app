<?php

/*
|--------------------------------------------------------------------------
| CICD-FIX-4 — LAB-PROD-2 fixture / foreign-key integrity
|--------------------------------------------------------------------------
|
| The Lab operational-analytics suites used to build orders with a hardcoded
| `branch_id => 1` (and `changed_by`/`created_by`/`analyzed_by => 1`) without
| ever creating those parent rows.
|
| That was NOT a missing-constraint difference between drivers: SQLite and
| PostgreSQL both declare `trx_lab_orders_branch_id_foreign` and both enforce
| it. It was a surrogate-id assumption. `LabOrder::factory()` pulls in
| `Doctor::factory()`, which pulls in `Branch::factory()`, so a branch is created
| as a side effect. Under SQLite each test runs inside a transaction that is
| rolled back, so rowids restart and that incidental branch happens to land on
| id 1. PostgreSQL does not roll sequences back, so from the second test onward
| the branch is id 2, 3, ... and id 1 simply does not exist:
|
|   SQLSTATE[23503] Key (branch_id)=(1) is not present in table "mst_branches"
|
| These tests pin the contract so the assumption cannot come back: the foreign
| key must be declared on every supported driver, a dangling parent must be
| rejected rather than silently accepted, and the shared fixtures must stay
| correct for ANY id the database hands out.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabOrderStatusLog;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\SchemaFacts;

beforeEach(fn () => seedAccessControl());

it('declares the trx_lab_orders branch_id foreign key to mst_branches on every supported driver', function () {
    expect(SchemaFacts::foreignKeyTargetFor('trx_lab_orders', 'branch_id'))->toBe('mst_branches');
});

it('creates a lab order against a real parent branch', function () {
    $branch = labOpsBranch();

    $order = LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'branch_id' => $branch->id,
        'order_date' => now()->toDateString(),
        'status' => LabWorkflowState::RECEIVED_AT_LAB,
    ]);

    expect($order->branch_id)->toBe($branch->id);
    $this->assertDatabaseHas('mst_branches', ['id' => $branch->id]);
    $this->assertDatabaseHas('trx_lab_orders', ['id' => $order->id, 'branch_id' => $branch->id]);
});

it('rejects a lab order whose branch_id has no parent row', function () {
    $danglingBranchId = ((int) Branch::query()->max('id')) + 1000;

    expect(Branch::query()->whereKey($danglingBranchId)->exists())->toBeFalse();

    // Wrapped in a nested transaction so the driver rolls back to a SAVEPOINT.
    // Without it PostgreSQL leaves the surrounding test transaction aborted
    // (SQLSTATE 25P02) and every later query in this test would fail too.
    expect(fn () => DB::transaction(fn () => LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'branch_id' => $danglingBranchId,
        'order_date' => now()->toDateString(),
        'status' => LabWorkflowState::RECEIVED_AT_LAB,
    ])))->toThrow(QueryException::class);

    expect(LabOrder::query()->where('branch_id', $danglingBranchId)->exists())->toBeFalse();
});

it('keeps the shared lab fixtures valid for any id the database assigns', function () {
    // Push the branch and user ids well past 1, the way a PostgreSQL sequence
    // does once it has survived earlier tests in the same process.
    Branch::factory()->count(3)->create();
    User::factory()->count(3)->create();

    $branch = labOpsBranch();
    $actor = labOpsActor();

    expect($branch->id)->toBeGreaterThan(1)
        ->and($actor->id)->toBeGreaterThan(1);

    $order = LabOrder::factory()->create([
        'workflow_version' => LabOrder::WORKFLOW_V2,
        'branch_id' => $branch->id,
        'order_date' => now()->toDateString(),
        'status' => LabWorkflowState::RECEIVED_AT_LAB,
    ]);

    LabOrderStatusLog::create([
        'lab_order_id' => $order->id,
        'old_status' => LabWorkflowState::RECEIVED_AT_LAB,
        'new_status' => LabWorkflowState::MODEL_REGISTERED,
        'changed_by' => $actor->id,
        'changed_at' => now(),
    ]);

    $this->assertDatabaseHas('trx_lab_orders', ['id' => $order->id, 'branch_id' => $branch->id]);
    $this->assertDatabaseHas('trx_lab_order_status_logs', [
        'lab_order_id' => $order->id,
        'changed_by' => $actor->id,
    ]);
});

it('resolves one shared branch and one shared actor per test', function () {
    expect(labOpsBranch()->id)->toBe(labOpsBranch()->id)
        ->and(labOpsActor()->id)->toBe(labOpsActor()->id);

    expect(Branch::query()->where('code', 'LABOPS')->count())->toBe(1)
        ->and(User::query()->where('email', 'lab-ops-fixture@example.test')->count())->toBe(1);
});
