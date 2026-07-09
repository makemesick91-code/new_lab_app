<?php

/**
 * SPRINT-68.45 Scope B — Branch PR workflow STABILIZATION.
 *
 * Verifies the workflow board KPI cards + status badges, the PR-only invariant
 * for Kepala Cabang (route + policy + direct-request), Admin Warehouse
 * processing, branch isolation, unauthorized 403, and that the existing
 * PR/PO/GR index routes still render.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\Supplier;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    $this->branch = Branch::factory()->create(['code' => 'KCS', 'name' => 'Cabang Stabilisasi', 'is_active' => true, 'is_inventory_enabled' => true]);
});

function s6845Kepala(Branch $branch): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole('Kepala Cabang');

    return $user;
}

function s6845PrPayload(Product $product, InventoryLocation $location, string $prType): array
{
    return [
        'request_date' => now()->toDateString(),
        'pr_type' => $prType,
        'items' => [[
            'product_id' => $product->id,
            'inventory_location_id' => $location->id,
            'quantity_requested' => 5,
            'estimated_unit_price' => 1000,
        ]],
    ];
}

it('lets a Kepala Cabang create a PR Reguler and a PR Darurat', function () {
    $kepala = s6845Kepala($this->branch);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($kepala)
        ->post(route('inventory.purchase-requests.store'), s6845PrPayload($product, $location, 'reguler'))
        ->assertRedirect();
    expect(PurchaseRequest::latest('id')->firstOrFail()->pr_type)->toBe('reguler');

    $this->actingAs($kepala)
        ->post(route('inventory.purchase-requests.store'), s6845PrPayload($product, $location, 'darurat'))
        ->assertRedirect();
    expect(PurchaseRequest::latest('id')->firstOrFail()->pr_type)->toBe('darurat');
});

it('blocks a Kepala Cabang from creating a PO by route, policy, and direct request', function () {
    $kepala = s6845Kepala($this->branch);
    $supplier = Supplier::factory()->create(['branch_id' => $this->branch->id]);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    // Route/UI block.
    $this->actingAs($kepala)->get(route('inventory.purchase-orders.create'))->assertForbidden();
    // Server-side chokepoint (PurchaseOrderPolicy::create).
    expect($kepala->can('create', PurchaseOrder::class))->toBeFalse();
    expect($kepala->can('create', PurchaseRequest::class))->toBeTrue();

    // Direct store request with a well-formed payload still cannot bypass the
    // policy — the controller authorize('create') denies it, and no PO is created.
    $this->actingAs($kepala)
        ->post(route('inventory.purchase-orders.store'), [
            'order_date' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'items' => [[
                'product_id' => $product->id,
                'inventory_location_id' => $location->id,
                'quantity_ordered' => 5,
                'unit_price' => 1000,
            ]],
        ])
        ->assertForbidden();
    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('renders KPI cards and status badges on the workflow board', function () {
    $kepala = s6845Kepala($this->branch);
    PurchaseRequest::factory()->submitted()->create(['branch_id' => $this->branch->id, 'pr_type' => 'darurat']);
    PurchaseRequest::factory()->create(['branch_id' => $this->branch->id, 'status' => 'draft', 'pr_type' => 'reguler']);

    $this->actingAs($kepala)
        ->get(route('inventory.purchase-requests.workflow'))
        ->assertOk()
        ->assertSee('data-workflow-kpis', false)
        ->assertSee('Menunggu Warehouse')
        ->assertSee('PR Darurat (antrian)')
        ->assertSee('Draf PR Cabang');
});

it('shows a Kepala Cabang only their own branch PRs (branch isolation)', function () {
    $kepala = s6845Kepala($this->branch);
    $otherBranch = Branch::factory()->create(['code' => 'OT2', 'is_active' => true, 'is_inventory_enabled' => true]);
    $mine = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id, 'status' => 'draft', 'pr_type' => 'reguler']);
    $theirs = PurchaseRequest::factory()->create(['branch_id' => $otherBranch->id, 'status' => 'draft', 'pr_type' => 'reguler']);

    $this->actingAs($kepala)
        ->get(route('inventory.purchase-requests.workflow'))
        ->assertOk()
        ->assertSee($mine->purchase_request_number)
        ->assertDontSee($theirs->purchase_request_number);
});

it('lets Admin Warehouse view and process the branch PR workflow', function () {
    $mainBranch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $mainBranch->update(['is_inventory_enabled' => true]);
    $warehouse = User::factory()->create();
    $warehouse->assignRole('Admin Warehouse');
    $submitted = PurchaseRequest::factory()->submitted()->create(['branch_id' => $mainBranch->id, 'pr_type' => 'reguler']);

    $this->actingAs($warehouse)
        ->get(route('inventory.purchase-requests.workflow'))
        ->assertOk()
        ->assertSee($submitted->purchase_request_number)
        ->assertSee('Proses');
});

it('denies the workflow board to a user without purchase-request access', function () {
    $this->actingAs(userWith(['view dashboard']))
        ->get(route('inventory.purchase-requests.workflow'))
        ->assertForbidden();
});

it('keeps the existing PR / PO / GR index routes working', function () {
    $manager = userWith(['manage_inventory']);

    $this->actingAs($manager)->get(route('inventory.purchase-requests.index'))->assertOk();
    $this->actingAs($manager)->get(route('inventory.purchase-orders.index'))->assertOk();
    $this->actingAs($manager)->get(route('inventory.goods-receipts.index'))->assertOk();
});
