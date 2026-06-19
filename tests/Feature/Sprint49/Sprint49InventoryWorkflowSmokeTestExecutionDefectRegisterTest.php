<?php

// Sprint 49 — Inventory Workflow Smoke Test Execution & Defect Register.
// Part A: documentation/history checklist regression over the Sprint 49 doc.
// Part B: safe local/test Inventory smoke execution through InventoryStockService (ledger only).
// No Inventory runtime behavior is changed; stock changes only via stock movements / ledger entries.

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

function sprint49InventoryDocPath(): string
{
    return base_path('docs/sprint_49_inventory_workflow_smoke_test_execution_defect_register.md');
}

function sprint49InventoryDoc(): string
{
    return File::get(sprint49InventoryDocPath());
}

function sprint49HistoryDoc(): string
{
    return File::get(base_path('docs/sprint_history.md'));
}

it('has the Sprint 49 Inventory smoke execution documentation file', function () {
    expect(File::exists(sprint49InventoryDocPath()))->toBeTrue();
});

it('contains the sprint title, branch, feature tag, future GO tag, base branch and Sprint 48 baseline', function () {
    $doc = sprint49InventoryDoc();
    expect($doc)->toContain('# Sprint 49 — Inventory Workflow Smoke Test Execution & Defect Register')
        ->and($doc)->toContain('feature/sprint-49-inventory-workflow-smoke-test-execution-defect-register')
        ->and($doc)->toContain('sprint-49-inventory-workflow-smoke-test-execution-defect-register')
        ->and($doc)->toContain('sprint-49-inventory-workflow-smoke-test-execution-defect-register-go')
        ->and($doc)->toContain('feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report')
        ->and($doc)->toContain('7e8fadb');
});

it('states the Inventory focus and local/test smoke-only execution', function () {
    $doc = sprint49InventoryDoc();
    expect($doc)->toContain('Sprint 49 is Inventory-focused.')
        ->and($doc)->toContain('Sprint 49 executes smoke coverage only in local/test environment.');
});

it('states the no-production and no-change safety boundaries', function () {
    $doc = sprint49InventoryDoc();
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
        ->and($doc)->toContain('No runtime behavior change.')
        ->and($doc)->toContain('No direct stock mutation.');
});

it('states the ledger movement rule and preserved business constraints', function () {
    $doc = sprint49InventoryDoc();
    expect($doc)->toContain('Inventory stock must be changed only through stock movements / ledger entries.')
        ->and($doc)->toContain('RME remains complete/closed for current planning.')
        ->and($doc)->toContain('Pilot Health Check governance loop remains stopped.')
        ->and($doc)->toContain('KTP / ktp_number remains hidden and is not part of Inventory workflow.')
        ->and($doc)->toContain('WhatsApp remains manual-only and is not part of Inventory automation.')
        ->and($doc)->toContain('Zero-remaining receivable rule remains preserved.')
        ->and($doc)->toContain('Overpayment guard remains preserved.');
});

it('includes the smoke execution data setup sequence and master data setup', function () {
    $doc = sprint49InventoryDoc();
    expect($doc)->toContain('## 10. Smoke execution data setup sequence')
        ->and($doc)->toContain('## 11. Master data smoke setup')
        ->and($doc)->toContain('Create or resolve branch context in test environment.');
});

it('includes opening stock, receive, usage/out and adjustment smoke sections', function () {
    $doc = sprint49InventoryDoc();
    expect($doc)->toContain('## 12. Opening stock smoke execution')
        ->and($doc)->toContain('## 13. Stock receive smoke execution')
        ->and($doc)->toContain('## 14. Stock usage / stock out smoke execution')
        ->and($doc)->toContain('## 15. Adjustment in smoke execution')
        ->and($doc)->toContain('## 16. Adjustment out smoke execution');
});

it('includes current stock, stock card, low stock and branch isolation validation sections', function () {
    $doc = sprint49InventoryDoc();
    expect($doc)->toContain('## 17. Current stock validation')
        ->and($doc)->toContain('## 18. Stock card / movement trail validation')
        ->and($doc)->toContain('## 19. Low-stock validation')
        ->and($doc)->toContain('## 20. Branch isolation validation');
});

it('includes permission, inactive guard, and quantity guard validation sections', function () {
    $doc = sprint49InventoryDoc();
    expect($doc)->toContain('## 21. Permission and access-control validation')
        ->and($doc)->toContain('## 22. Inactive product/location guard validation')
        ->and($doc)->toContain('## 23. Zero/negative quantity guard validation')
        ->and($doc)->toContain('## 24. Insufficient stock guard validation');
});

it('includes the defect register classification, table and result summary', function () {
    $doc = sprint49InventoryDoc();
    expect($doc)->toContain('## 25. Defect register classification')
        ->and($doc)->toContain('## 26. Defect register table')
        ->and($doc)->toContain('## 27. Smoke execution result summary')
        ->and($doc)->toContain('PASS — expected behavior confirmed.')
        ->and($doc)->toContain('BLOCKER — behavior prevents safe Inventory operation.')
        ->and($doc)->toContain('INV-SMOKE-001')
        ->and($doc)->toContain('INV-SMOKE-002');
});

it('includes the follow-up recommendation and validation commands', function () {
    $doc = sprint49InventoryDoc();
    expect($doc)->toContain('## 28. Follow-up recommendation')
        ->and($doc)->toContain('## 30. Validation commands')
        ->and($doc)->toContain('php artisan test --filter=Sprint49InventoryWorkflowSmokeTestExecutionDefectRegister');
});

it('records the Sprint 49 entry in the sprint history', function () {
    $history = sprint49HistoryDoc();
    expect($history)->toContain('## Sprint 49 — Inventory Workflow Smoke Test Execution & Defect Register')
        ->and($history)->toContain('feature/sprint-49-inventory-workflow-smoke-test-execution-defect-register')
        ->and($history)->toContain('7e8fadb');
});

// ---------------------------------------------------------------------------
// Part B — Actual local/test Inventory smoke execution (ledger only)
// Mirrors the proven InventoryStockServiceTest setup pattern.
// ---------------------------------------------------------------------------

beforeEach(function () {
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->service = app(InventoryStockService::class);
});

it('smoke: opening stock creates a ledger stock-in movement and increases current stock', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $movement = $this->service->createOpeningStock($product->id, $location->id, 10, 12500);

    expect($movement->movement_type)->toBe(InventoryMovement::TYPE_OPENING)
        ->and((float) $movement->quantity_in)->toBe(10.0)
        ->and((float) $movement->quantity_out)->toBe(0.0)
        ->and($this->service->getCurrentStock($product->id, $location->id))->toBe(10.0);
});

it('smoke: receive stock increases current stock through a purchase movement', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($product->id, $location->id, 5, 1000);
    $movement = $this->service->receiveStock($product->id, $location->id, 7, 1100, $supplier->id, 'smoke receive');

    expect($movement->movement_type)->toBe(InventoryMovement::TYPE_PURCHASE)
        ->and($movement->supplier_id)->toBe($supplier->id)
        ->and($this->service->getCurrentStock($product->id, $location->id))->toBe(12.0);
});

it('smoke: adjustment in increases and adjustment out decreases current stock', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($product->id, $location->id, 10);
    $this->service->adjustIn($product->id, $location->id, 4);
    $out = $this->service->adjustOut($product->id, $location->id, 3, 'smoke usage');

    expect($out->movement_type)->toBe(InventoryMovement::TYPE_ADJUSTMENT_OUT)
        ->and((float) $out->quantity_out)->toBe(3.0)
        ->and($this->service->getCurrentStock($product->id, $location->id))->toBe(11.0);
});

it('smoke: insufficient stock out is rejected and leaves stock unchanged', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($product->id, $location->id, 2);

    expect(fn () => $this->service->adjustOut($product->id, $location->id, 5))
        ->toThrow(ValidationException::class)
        ->and($this->service->getCurrentStock($product->id, $location->id))->toBe(2.0);
});

it('smoke: zero and negative quantities are rejected for inbound and outbound movements', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    expect(fn () => $this->service->createOpeningStock($product->id, $location->id, 0))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->adjustIn($product->id, $location->id, -1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->adjustOut($product->id, $location->id, 0))
        ->toThrow(ValidationException::class);
});

it('smoke: inactive product cannot be used for a new movement', function () {
    $product = Product::factory()->inactive()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    expect(fn () => $this->service->createOpeningStock($product->id, $location->id, 1))
        ->toThrow(ValidationException::class);
});

it('smoke: inactive location cannot be used for a new movement', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->inactive()->create(['branch_id' => $this->branch->id]);

    expect(fn () => $this->service->createOpeningStock($product->id, $location->id, 1))
        ->toThrow(ValidationException::class);
});

it('smoke: current stock equals ledger stock-in minus stock-out', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($product->id, $location->id, 10);
    $this->service->receiveStock($product->id, $location->id, 5);
    $this->service->adjustOut($product->id, $location->id, 4);

    $ledgerStock = (float) InventoryMovement::query()
        ->where('branch_id', $this->branch->id)
        ->where('product_id', $product->id)
        ->where('inventory_location_id', $location->id)
        ->selectRaw('COALESCE(SUM(quantity_in) - SUM(quantity_out), 0) as current_stock')
        ->value('current_stock');

    expect($ledgerStock)->toBe(11.0)
        ->and($this->service->getCurrentStock($product->id, $location->id))->toBe(11.0);
});

it('smoke: stock card lists the movement trail in order', function () {
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

it('smoke: low stock list includes products at or below minimum and excludes healthy ones', function () {
    $lowProduct = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 10,
        'average_cost' => 100,
    ]);
    $healthyProduct = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'minimum_stock' => 10,
        'average_cost' => 50,
    ]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($lowProduct->id, $location->id, 4, 100);
    $this->service->createOpeningStock($healthyProduct->id, $location->id, 20, 50);

    expect($this->service->getLowStockProducts($location->id)->pluck('id')->all())
        ->toBe([$lowProduct->id]);
});

it('smoke: branch isolation rejects product/location/supplier from another branch', function () {
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $otherProduct = Product::factory()->create(['branch_id' => $otherBranch->id]);
    $otherLocation = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);
    $otherSupplier = Supplier::factory()->create(['branch_id' => $otherBranch->id]);

    expect(fn () => $this->service->createOpeningStock($otherProduct->id, $location->id, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->createOpeningStock($product->id, $otherLocation->id, 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->service->receiveStock($product->id, $location->id, 1, 0, $otherSupplier->id))
        ->toThrow(ValidationException::class);
});

it('smoke: inventory keeps stock ledger-based with no mutable stock columns', function () {
    $forbidden = ['current_stock', 'quantity_on_hand', 'available_quantity', 'stock', 'qty_on_hand'];

    foreach ($forbidden as $column) {
        expect(Schema::getColumnListing('inv_products'))->not->toContain($column)
            ->and(Schema::getColumnListing('inv_inventory_locations'))->not->toContain($column)
            ->and(Schema::getColumnListing('inv_inventory_batches'))->not->toContain($column);
    }
});
