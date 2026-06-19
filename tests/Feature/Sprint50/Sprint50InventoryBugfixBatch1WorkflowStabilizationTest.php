<?php

// Sprint 50 — Inventory Bugfix Batch 1 & Workflow Stabilization.
// Part A: documentation/history checklist regression over the Sprint 50 doc.
// Part B: targeted local/test Inventory stabilization regression through InventoryStockService
//         (ledger only). No Inventory runtime behavior is changed; stock changes only via stock
//         movements / ledger entries.

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

// ---------------------------------------------------------------------------
// Part A — Documentation regression
// ---------------------------------------------------------------------------

function sprint50InventoryDocPath(): string
{
    return base_path('docs/sprint_50_inventory_bugfix_batch_1_workflow_stabilization.md');
}

function sprint50InventoryDoc(): string
{
    return File::get(sprint50InventoryDocPath());
}

function sprint50HistoryDoc(): string
{
    return File::get(base_path('docs/sprint_history.md'));
}

it('has the Sprint 50 Inventory stabilization documentation file', function () {
    expect(File::exists(sprint50InventoryDocPath()))->toBeTrue();
});

it('contains the sprint title, branch, feature tag, future GO tag, base branch and Sprint 49 baseline', function () {
    $doc = sprint50InventoryDoc();
    expect($doc)->toContain('# Sprint 50 — Inventory Bugfix Batch 1 & Workflow Stabilization')
        ->and($doc)->toContain('feature/sprint-50-inventory-bugfix-batch-1-workflow-stabilization')
        ->and($doc)->toContain('sprint-50-inventory-bugfix-batch-1-workflow-stabilization')
        ->and($doc)->toContain('sprint-50-inventory-bugfix-batch-1-workflow-stabilization-go')
        ->and($doc)->toContain('feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report')
        ->and($doc)->toContain('fd9f8e5');
});

it('states the Inventory focus and local/test stabilization-only scope', function () {
    $doc = sprint50InventoryDoc();
    expect($doc)->toContain('Sprint 50 is Inventory-focused.')
        ->and($doc)->toContain('Sprint 50 is local/test stabilization only.')
        ->and($doc)->toContain('Sprint 49 reported 0 defects and 0 blockers.')
        ->and($doc)->toContain('Sprint 50 must not invent speculative bugfixes.');
});

it('states the no-production and no-change safety boundaries', function () {
    $doc = sprint50InventoryDoc();
    expect($doc)->toContain('No production/VPS/server access.')
        ->and($doc)->toContain('No production database/log/file access.')
        ->and($doc)->toContain('No deployment.')
        ->and($doc)->toContain('No production command execution.')
        ->and($doc)->toContain('No production backup.')
        ->and($doc)->toContain('No production restore.')
        ->and($doc)->toContain('No rollback execution.')
        ->and($doc)->toContain('No `.env` change.')
        ->and($doc)->toContain('No dependency/package install.')
        ->and($doc)->toContain('No migration/schema change.')
        ->and($doc)->toContain('No broad runtime behavior change.')
        ->and($doc)->toContain('No direct stock mutation.');
});

it('states the ledger movement rule and preserved business constraints', function () {
    $doc = sprint50InventoryDoc();
    expect($doc)->toContain('Inventory stock must be changed only through stock movements / ledger entries.')
        ->and($doc)->toContain('Any larger concurrency or locking fix requires a separate approved implementation sprint.')
        ->and($doc)->toContain('RME remains complete/closed for current planning.')
        ->and($doc)->toContain('Pilot Health Check governance loop remains stopped.')
        ->and($doc)->toContain('KTP / ktp_number remains hidden and is not part of Inventory workflow.')
        ->and($doc)->toContain('WhatsApp remains manual-only and is not part of Inventory automation.')
        ->and($doc)->toContain('Zero-remaining receivable rule remains preserved.')
        ->and($doc)->toContain('Overpayment guard remains preserved.');
});

it('includes the bugfix batch and runtime change policy sections', function () {
    $doc = sprint50InventoryDoc();
    expect($doc)->toContain('## 8. Bugfix batch policy')
        ->and($doc)->toContain('## 9. Runtime change policy')
        ->and($doc)->toContain('## 10. Inventory stabilization principle')
        ->and($doc)->toContain('## 11. Local/test environment boundary')
        ->and($doc)->toContain('## 12. Stabilization data setup');
});

it('includes the ledger, current stock and stock card consistency sections', function () {
    $doc = sprint50InventoryDoc();
    expect($doc)->toContain('## 13. Ledger-only stock invariants')
        ->and($doc)->toContain('## 14. Current stock consistency checks')
        ->and($doc)->toContain('## 15. Stock card / movement trail consistency checks')
        ->and($doc)->toContain('## 16. Branch isolation stabilization checks');
});

it('includes permission, inactive guard, and quantity guard sections', function () {
    $doc = sprint50InventoryDoc();
    expect($doc)->toContain('## 17. Permission/access-control representative checks')
        ->and($doc)->toContain('## 18. Inactive product/location/supplier guard checks')
        ->and($doc)->toContain('## 19. Positive quantity and insufficient-stock guard checks')
        ->and($doc)->toContain('## 20. Concurrency watch item')
        ->and($doc)->toContain('INV-FU-001');
});

it('includes the bugfix/stabilization register classification, table and result summary', function () {
    $doc = sprint50InventoryDoc();
    expect($doc)->toContain('## 21. Bugfix/stabilization register classification')
        ->and($doc)->toContain('## 22. Bugfix/stabilization register table')
        ->and($doc)->toContain('## 23. Stabilization result summary')
        ->and($doc)->toContain('PASS — expected behavior confirmed.')
        ->and($doc)->toContain('BLOCKER — confirmed issue that prevents safe Inventory operation.')
        ->and($doc)->toContain('INV-STAB-001')
        ->and($doc)->toContain('INV-STAB-008');
});

it('includes the follow-up recommendation, safety confirmation and validation commands', function () {
    $doc = sprint50InventoryDoc();
    expect($doc)->toContain('## 24. Follow-up recommendation')
        ->and($doc)->toContain('## 25. Safety confirmation')
        ->and($doc)->toContain('## 26. Validation commands')
        ->and($doc)->toContain('## 27. AI agent memory summary')
        ->and($doc)->toContain('php artisan test --filter=Sprint50InventoryBugfixBatch1WorkflowStabilization');
});

it('records the Sprint 50 entry in the sprint history', function () {
    $history = sprint50HistoryDoc();
    expect($history)->toContain('## Sprint 50 — Inventory Bugfix Batch 1 & Workflow Stabilization')
        ->and($history)->toContain('feature/sprint-50-inventory-bugfix-batch-1-workflow-stabilization')
        ->and($history)->toContain('fd9f8e5');
});

// ---------------------------------------------------------------------------
// Part B — Actual local/test Inventory stabilization regression (ledger only).
// Mirrors the proven InventoryStockServiceTest / Sprint 49 setup pattern.
// ---------------------------------------------------------------------------

beforeEach(function () {
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->service = app(InventoryStockService::class);
});

// INV-STAB-001 — ledger-derived current stock.
it('stabilizes current stock as ledger stock-in minus stock-out', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($product->id, $location->id, 10);
    $this->service->receiveStock($product->id, $location->id, 5);
    $this->service->adjustIn($product->id, $location->id, 3);
    $this->service->adjustOut($product->id, $location->id, 4);

    $ledgerStock = (float) InventoryMovement::query()
        ->where('branch_id', $this->branch->id)
        ->where('product_id', $product->id)
        ->where('inventory_location_id', $location->id)
        ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
        ->value('current_stock');

    expect($ledgerStock)->toBe(14.0)
        ->and($this->service->getCurrentStock($product->id, $location->id))->toBe(14.0);
});

// INV-STAB-002 — stock card / movement trail explains the balance in order.
it('stabilizes the stock card movement trail order', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($product->id, $location->id, 10);
    $this->service->receiveStock($product->id, $location->id, 5);
    $this->service->adjustIn($product->id, $location->id, 2);
    $this->service->adjustOut($product->id, $location->id, 3);

    expect($this->service->getStockCard($product->id, $location->id)->pluck('movement_type')->all())
        ->toBe([
            InventoryMovement::TYPE_OPENING,
            InventoryMovement::TYPE_PURCHASE,
            InventoryMovement::TYPE_ADJUSTMENT_IN,
            InventoryMovement::TYPE_ADJUSTMENT_OUT,
        ]);
});

// INV-STAB-003 — branch isolation: Branch A movement does not affect Branch B stock.
it('stabilizes branch isolation for current stock and cross-branch master data', function () {
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $otherBranch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);

    $this->service->createOpeningStock($product->id, $location->id, 9);

    // Branch A stock is isolated; Branch B product has no stock in Branch A context.
    expect($this->service->getCurrentStock($product->id, $location->id))->toBe(9.0)
        ->and(fn () => $this->service->createOpeningStock($otherProduct->id, $location->id, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->createOpeningStock($product->id, $otherLocation->id, 1))
        ->toThrow(ValidationException::class);
});

// INV-STAB-004 — positive quantity guard.
it('stabilizes the positive quantity guard for inbound and outbound movements', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    expect(fn () => $this->service->createOpeningStock($product->id, $location->id, 0))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->adjustIn($product->id, $location->id, -1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->adjustOut($product->id, $location->id, 0))
        ->toThrow(ValidationException::class);
});

// INV-STAB-005 — insufficient stock guard leaves stock unchanged.
it('stabilizes the insufficient-stock guard and leaves stock unchanged', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($product->id, $location->id, 2);

    expect(fn () => $this->service->adjustOut($product->id, $location->id, 5))
        ->toThrow(ValidationException::class)
        ->and($this->service->getCurrentStock($product->id, $location->id))->toBe(2.0);
});

// INV-STAB-006 — inactive product/location/supplier guards.
it('stabilizes the inactive product, location and supplier guards', function () {
    $inactiveProduct = Product::factory()->inactive()->create(['branch_id' => $this->branch->id]);
    $activeProduct = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $inactiveLocation = InventoryLocation::factory()->inactive()->create(['branch_id' => $this->branch->id]);
    $inactiveSupplier = Supplier::factory()->inactive()->create(['branch_id' => $this->branch->id]);

    expect(fn () => $this->service->createOpeningStock($inactiveProduct->id, $location->id, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->createOpeningStock($activeProduct->id, $inactiveLocation->id, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->receiveStock($activeProduct->id, $location->id, 1, 0, $inactiveSupplier->id))
        ->toThrow(ValidationException::class);
});

// INV-STAB-007 — no direct stock mutation: no mutable stock column exists.
it('stabilizes ledger-only stock with no mutable stock columns', function () {
    $forbidden = ['current_stock', 'quantity_on_hand', 'available_quantity', 'stock', 'qty_on_hand'];

    foreach ($forbidden as $column) {
        expect(Schema::getColumnListing('inv_products'))->not->toContain($column)
            ->and(Schema::getColumnListing('inv_inventory_locations'))->not->toContain($column)
            ->and(Schema::getColumnListing('inv_inventory_batches'))->not->toContain($column);
    }
});

// INV-STAB-008 — representative access-control: unauthenticated Inventory route redirects to login.
it('stabilizes access control by redirecting unauthenticated Inventory requests to login', function () {
    $this->get(route('inventory.stock.index'))->assertRedirect(route('login'));
});
