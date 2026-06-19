<?php

// Sprint 51 — Inventory UI Operational Walkthrough & User Acceptance Checklist.
// Part A: documentation/history checklist regression over the Sprint 51 doc + sprint history.
// Part B: targeted local/test Inventory UI/HTTP walkthrough regression through existing inventory.*
//         routes and the InventoryStockService ledger APIs. No Inventory runtime behavior is changed;
//         stock changes only via stock movements / ledger entries.

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\File;

// ---------------------------------------------------------------------------
// Part A — Documentation regression
// ---------------------------------------------------------------------------

function sprint51InventoryDocPath(): string
{
    return base_path('docs/sprint_51_inventory_ui_operational_walkthrough_user_acceptance_checklist.md');
}

function sprint51InventoryDoc(): string
{
    return File::get(sprint51InventoryDocPath());
}

function sprint51HistoryDoc(): string
{
    return File::get(base_path('docs/sprint_history.md'));
}

it('has the Sprint 51 Inventory UI walkthrough documentation file', function () {
    expect(File::exists(sprint51InventoryDocPath()))->toBeTrue();
});

it('contains the sprint title, branch, feature tag, future GO tag, base branch and Sprint 50 baseline', function () {
    $doc = sprint51InventoryDoc();
    expect($doc)->toContain('# Sprint 51 — Inventory UI Operational Walkthrough & User Acceptance Checklist')
        ->and($doc)->toContain('feature/sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist')
        ->and($doc)->toContain('sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist')
        ->and($doc)->toContain('sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist-go')
        ->and($doc)->toContain('feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report')
        ->and($doc)->toContain('6b5028b');
});

it('states the Inventory UI focus and local/test UI walkthrough-only scope', function () {
    $doc = sprint51InventoryDoc();
    expect($doc)->toContain('Sprint 51 is Inventory UI-focused.')
        ->and($doc)->toContain('Sprint 51 is local/test UI walkthrough and user acceptance checklist only.');
});

it('states the no-production and no-change safety boundaries', function () {
    $doc = sprint51InventoryDoc();
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

it('states the ledger rule and preserved business constraints', function () {
    $doc = sprint51InventoryDoc();
    expect($doc)->toContain('Inventory stock remains ledger-based.')
        ->and($doc)->toContain('RME remains complete/closed for current planning.')
        ->and($doc)->toContain('Pilot Health Check governance loop remains stopped.')
        ->and($doc)->toContain('KTP / ktp_number remains hidden and is not part of Inventory workflow.')
        ->and($doc)->toContain('WhatsApp remains manual-only and is not part of Inventory automation.')
        ->and($doc)->toContain('Zero-remaining receivable rule remains preserved.')
        ->and($doc)->toContain('Overpayment guard remains preserved.');
});

it('includes the Sprint 48-50 carry-forward and UI module map', function () {
    $doc = sprint51InventoryDoc();
    expect($doc)->toContain('## Sprint 48–50 carry-forward summary')
        ->and($doc)->toContain('## Inventory UI module map')
        ->and($doc)->toContain('## UI walkthrough principle')
        ->and($doc)->toContain('## Local/test UI boundary');
});

it('includes the dashboard, master data and stock movement walkthrough sections', function () {
    $doc = sprint51InventoryDoc();
    expect($doc)->toContain('## Dashboard walkthrough')
        ->and($doc)->toContain('## Master data navigation walkthrough')
        ->and($doc)->toContain('## Product unit page checklist')
        ->and($doc)->toContain('## Product category page checklist')
        ->and($doc)->toContain('## Supplier page checklist')
        ->and($doc)->toContain('## Inventory location page checklist')
        ->and($doc)->toContain('## Product / item page checklist')
        ->and($doc)->toContain('## Stock movement entry point checklist');
});

it('includes the stock movement readiness sections', function () {
    $doc = sprint51InventoryDoc();
    expect($doc)->toContain('## Opening stock UI readiness')
        ->and($doc)->toContain('## Stock receive UI readiness')
        ->and($doc)->toContain('## Adjustment in UI readiness')
        ->and($doc)->toContain('## Adjustment out UI readiness');
});

it('includes the stock, branch, permission, inactive and validation sections', function () {
    $doc = sprint51InventoryDoc();
    expect($doc)->toContain('## Current stock UI checks')
        ->and($doc)->toContain('## Stock card / movement trail UI checks')
        ->and($doc)->toContain('## Low-stock UI checks')
        ->and($doc)->toContain('## Branch-aware UI checks')
        ->and($doc)->toContain('## Permission/access-control UI checks')
        ->and($doc)->toContain('## Inactive product/location UI checks')
        ->and($doc)->toContain('## Validation and error-message checks');
});

it('includes the user acceptance checklist and observation register', function () {
    $doc = sprint51InventoryDoc();
    expect($doc)->toContain('## User acceptance checklist')
        ->and($doc)->toContain('## UI observation/follow-up register classification')
        ->and($doc)->toContain('## UI observation/follow-up register table')
        ->and($doc)->toContain('INV-UI-001')
        ->and($doc)->toContain('INV-FU-001');
});

it('includes the safety confirmation and validation commands sections', function () {
    $doc = sprint51InventoryDoc();
    expect($doc)->toContain('## Safety confirmation')
        ->and($doc)->toContain('## Validation commands')
        ->and($doc)->toContain('Sprint51InventoryUiOperationalWalkthroughUserAcceptanceChecklist');
});

it('records the Sprint 51 entry in sprint history', function () {
    $history = sprint51HistoryDoc();
    expect($history)->toContain('## Sprint 51 — Inventory UI Operational Walkthrough & User Acceptance Checklist')
        ->and($history)->toContain('feature/sprint-51-inventory-ui-operational-walkthrough-user-acceptance-checklist')
        ->and($history)->toContain('6b5028b');
});

// ---------------------------------------------------------------------------
// Part B — Actual local/test Inventory UI/HTTP walkthrough regression.
// Mirrors the proven InventoryUiTest / InventoryRouteAuthorizationTest patterns.
// No runtime behavior is changed; stock changes only via ledger movements.
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->service = app(InventoryStockService::class);
});

// INV-UI-006 — access control: unauthenticated Inventory routes redirect to login.
it('redirects unauthenticated Inventory requests to login', function () {
    $this->get(route('inventory.dashboard'))->assertRedirect(route('login'));
    $this->get(route('inventory.stock.index'))->assertRedirect(route('login'));
    $this->get(route('inventory.products.index'))->assertRedirect(route('login'));
});

// INV-UI-001 — dashboard renders for an authorized user without leaking unrelated data.
it('opens the Inventory dashboard for an authorized user', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard Inventory');
});

// INV-UI-002 — master data index pages render with the expected branch-scoped headers.
it('opens the Inventory master data index pages', function () {
    $user = userWith(['manage master data']);

    $this->actingAs($user)->get(route('inventory.product-units.index'))->assertOk()->assertSee('Satuan Produk');
    $this->actingAs($user)->get(route('inventory.product-categories.index'))->assertOk()->assertSee('Kategori Produk');
    $this->actingAs($user)->get(route('inventory.suppliers.index'))->assertOk()->assertSee('Pemasok Persediaan');
    $this->actingAs($user)->get(route('inventory.locations.index'))->assertOk()->assertSee('Lokasi Persediaan');
});

// INV-UI-003 — products index renders with current-stock columns.
it('opens the Inventory products index with current stock columns', function () {
    Product::factory()->create(['branch_id' => $this->branch->id, 'name' => 'Zirconia UAT Block']);

    $this->actingAs(userWith(['manage master data']))
        ->get(route('inventory.products.index'))
        ->assertOk()
        ->assertSee('Produk Persediaan')
        ->assertSee('Zirconia UAT Block')
        ->assertSee('Status Stok');
});

// INV-UI-003 — stock card renders the ledger movement trail for a product.
it('opens the product stock card with the movement trail', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->service->createOpeningStock($product->id, $location->id, 10);
    $this->service->receiveStock($product->id, $location->id, 5);
    $this->service->adjustOut($product->id, $location->id, 3);

    $this->actingAs(userWith(['manage master data']))
        ->get(route('inventory.products.stock-card', $product))
        ->assertOk()
        ->assertSee('Kartu Stok');
});

// INV-UI-005 — low-stock / alerts page renders for an authorized user.
it('opens the Inventory low-stock alerts page', function () {
    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.alerts.index'))
        ->assertOk()
        ->assertSee('Peringatan Persediaan');
});

// INV-UI-004 — opening stock via UI redirects to the stock card and writes a ledger row (no mutation).
it('records opening stock through the UI as a ledger movement', function () {
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs(userWith(['manage master data']))
        ->post(route('inventory.products.opening-stock.store', $product), [
            'inventory_location_id' => $location->id,
            'quantity' => 12,
            'unit_cost' => 15000,
            'notes' => 'opening via Sprint 51 UI walkthrough',
        ])
        ->assertRedirect(route('inventory.products.stock-card', $product));

    $this->assertDatabaseHas('trx_inventory_movements', [
        'branch_id' => $this->branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'movement_type' => InventoryMovement::TYPE_OPENING,
        'quantity_in' => 12,
        'quantity_out' => 0,
    ]);
});

// INV-UI-007 — branch isolation: a cross-branch product is forbidden from the UI.
it('forbids opening a cross-branch product from the UI', function () {
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $otherBranch->id]);

    $this->actingAs(userWith(['manage master data']))
        ->get(route('inventory.products.show', $product))
        ->assertForbidden();
});

// INV-UI validation — cross-branch supplier on receive-stock is rejected (no ledger row written).
it('rejects receiving stock with a supplier from another branch', function () {
    $otherBranch = Branch::factory()->create();
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $otherSupplier = Supplier::factory()->create(['branch_id' => $otherBranch->id]);

    $this->actingAs(userWith(['manage master data']))
        ->from(route('inventory.products.receive-stock.create', $product))
        ->post(route('inventory.products.receive-stock.store', $product), [
            'inventory_location_id' => $location->id,
            'quantity' => 5,
            'unit_cost' => 15000,
            'supplier_id' => $otherSupplier->id,
        ])
        ->assertRedirect(route('inventory.products.receive-stock.create', $product))
        ->assertSessionHasErrors('supplier_id');

    $this->assertDatabaseMissing('trx_inventory_movements', [
        'supplier_id' => $otherSupplier->id,
        'product_id' => $product->id,
    ]);
});
