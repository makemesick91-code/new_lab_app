<?php

/**
 * FIX-PRE-68-45 Scope G — branch PR workflow (Kepala Cabang → Admin Warehouse).
 *
 * Kepala Cabang can create PR Reguler + Darurat but NEVER a Purchase Order
 * (blocked by route + policy). Admin Warehouse can view/process branch PRs. The
 * workflow board is branch-scoped (Kepala Cabang pinned via users.branch_id).
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    $this->branch = Branch::factory()->create(['code' => 'KCB', 'name' => 'Cabang Kepala', 'is_active' => true, 'is_inventory_enabled' => true]);
});

function kepalaCabang(Branch $branch): User
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole('Kepala Cabang');

    return $user;
}

function prCreatePayload(Product $product, InventoryLocation $location, string $prType): array
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

it('lets a Kepala Cabang create a PR Reguler in their own branch', function () {
    $kepala = kepalaCabang($this->branch);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($kepala)
        ->post(route('inventory.purchase-requests.store'), prCreatePayload($product, $location, 'reguler'))
        ->assertRedirect();

    $pr = PurchaseRequest::query()->latest('id')->firstOrFail();
    expect($pr->pr_type)->toBe('reguler');
    expect($pr->branch_id)->toBe($this->branch->id);
});

it('lets a Kepala Cabang create a PR Darurat', function () {
    $kepala = kepalaCabang($this->branch);
    $product = Product::factory()->create(['branch_id' => $this->branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);

    $this->actingAs($kepala)
        ->post(route('inventory.purchase-requests.store'), prCreatePayload($product, $location, 'darurat'))
        ->assertRedirect();

    expect(PurchaseRequest::query()->latest('id')->firstOrFail()->pr_type)->toBe('darurat');
});

it('blocks a Kepala Cabang from creating a Purchase Order (route + policy)', function () {
    $kepala = kepalaCabang($this->branch);

    // Route/UI block: the PO create page is forbidden.
    $this->actingAs($kepala)
        ->get(route('inventory.purchase-orders.create'))
        ->assertForbidden();

    // Policy block (the server-side chokepoint): Kepala Cabang cannot pass the
    // PurchaseOrder create ability, so a direct store request can never create one.
    expect($kepala->can('create', PurchaseOrder::class))->toBeFalse();
    expect($kepala->can('create', PurchaseRequest::class))->toBeTrue();
});

it('shows a Kepala Cabang only their own branch PRs on the workflow board', function () {
    $kepala = kepalaCabang($this->branch);
    $otherBranch = Branch::factory()->create(['code' => 'OTH', 'name' => 'Cabang Lain', 'is_active' => true, 'is_inventory_enabled' => true]);

    $mine = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id, 'status' => 'draft', 'pr_type' => 'reguler']);
    $theirs = PurchaseRequest::factory()->create(['branch_id' => $otherBranch->id, 'status' => 'draft', 'pr_type' => 'reguler']);

    $this->actingAs($kepala)
        ->get(route('inventory.purchase-requests.workflow'))
        ->assertOk()
        ->assertSee('Alur PR Cabang')
        ->assertSee($mine->purchase_request_number)
        ->assertDontSee($theirs->purchase_request_number);
});

it('lets Admin Warehouse view and process the branch PR workflow', function () {
    $mainBranch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $mainBranch->update(['is_inventory_enabled' => true]);
    $warehouse = User::factory()->create();
    $warehouse->assignRole('Admin Warehouse');

    // A submitted PR in MAIN (Admin Warehouse's default branch) → shows "Proses".
    $submitted = PurchaseRequest::factory()->submitted()->create(['branch_id' => $mainBranch->id, 'pr_type' => 'reguler']);

    $this->actingAs($warehouse)
        ->get(route('inventory.purchase-requests.workflow'))
        ->assertOk()
        ->assertSee($submitted->purchase_request_number)
        ->assertSee('Proses');
});

it('denies the workflow board to a user without purchase-request access', function () {
    $user = userWith(['view dashboard']);

    $this->actingAs($user)
        ->get(route('inventory.purchase-requests.workflow'))
        ->assertForbidden();
});
