<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->otherBranch = Branch::factory()->create(['code' => 'PDF', 'name' => 'PDF Branch']);
});

function checklistTransfer(Branch $branch, string $status = StockTransfer::STATUS_IN_TRANSIT, string $number = 'TRF-PDF-001'): StockTransfer
{
    $source = InventoryLocation::factory()->create(['branch_id' => $branch->id, 'name' => 'Gudang PDF Sumber']);
    $destination = InventoryLocation::factory()->create(['branch_id' => $branch->id, 'name' => 'Gudang PDF Tujuan']);
    $product = Product::factory()->create(['branch_id' => $branch->id, 'name' => 'Produk PDF', 'code' => 'SKU-PDF']);

    $transfer = StockTransfer::factory()->create([
        'branch_id' => $branch->id,
        'transfer_number' => $number,
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
        'status' => $status,
        'shipped_at' => in_array($status, [StockTransfer::STATUS_IN_TRANSIT, StockTransfer::STATUS_RECEIVED], true) ? now() : null,
    ]);

    StockTransferItem::factory()->create([
        'stock_transfer_id' => $transfer->id,
        'product_id' => $product->id,
        'quantity' => 2.5,
        'notes' => 'Checklist line note',
    ]);

    return $transfer->refresh()->load(['items.product.unit']);
}

function checklistDownloader()
{
    return userWith(['view_inventory', 'download_stock_transfer_checklist']);
}

it('registers the stock transfer checklist route name', function () {
    expect(Route::has('inventory.stock-transfers.checklist'))->toBeTrue();
});

it('assigns checklist download permission to Admin Lab through the role seeder', function () {
    expect(Role::findByName('Admin Lab')->hasPermissionTo('download_stock_transfer_checklist'))->toBeTrue();
});

it('allows an authorized user to download the PDF checklist', function () {
    $transfer = checklistTransfer($this->branch, StockTransfer::STATUS_IN_TRANSIT, 'TRF-PDF-AUTH');

    $response = $this->actingAs(checklistDownloader())
        ->get(route('inventory.stock-transfers.checklist', $transfer));

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('stock-transfer-checklist-TRF-PDF-AUTH.pdf');
});

it('allows checklist download for received transfers', function () {
    $transfer = checklistTransfer($this->branch, StockTransfer::STATUS_RECEIVED, 'TRF-PDF-RECEIVED');

    $this->actingAs(checklistDownloader())
        ->get(route('inventory.stock-transfers.checklist', $transfer))
        ->assertOk();
});

it('denies unauthenticated users from downloading the checklist', function () {
    $transfer = checklistTransfer($this->branch, StockTransfer::STATUS_IN_TRANSIT, 'TRF-PDF-GUEST');

    $this->get(route('inventory.stock-transfers.checklist', $transfer))
        ->assertRedirect(route('login'));
});

it('denies users without the checklist permission', function () {
    $transfer = checklistTransfer($this->branch, StockTransfer::STATUS_IN_TRANSIT, 'TRF-PDF-NO-PERM');

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.stock-transfers.checklist', $transfer))
        ->assertForbidden();
});

it('denies users from another active branch', function () {
    $transfer = checklistTransfer($this->otherBranch, StockTransfer::STATUS_IN_TRANSIT, 'TRF-PDF-OTHER');

    $this->actingAs(checklistDownloader())
        ->get(route('inventory.stock-transfers.checklist', $transfer))
        ->assertForbidden();
});

it('does not create inventory movements or change transfer status when downloading', function () {
    $transfer = checklistTransfer($this->branch, StockTransfer::STATUS_IN_TRANSIT, 'TRF-PDF-NO-MOVE');
    $movementCount = InventoryMovement::count();
    $status = $transfer->status;

    $this->actingAs(checklistDownloader())
        ->get(route('inventory.stock-transfers.checklist', $transfer))
        ->assertOk();

    expect(InventoryMovement::count())->toBe($movementCount)
        ->and($transfer->refresh()->status)->toBe($status);
});

it('shows the checklist button for authorized users on in transit transfers', function () {
    $transfer = checklistTransfer($this->branch, StockTransfer::STATUS_IN_TRANSIT, 'TRF-PDF-BUTTON-IN');

    $this->actingAs(checklistDownloader())
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk()
        ->assertSee('Download Checklist PDF')
        ->assertSee(route('inventory.stock-transfers.checklist', $transfer), false);
});

it('shows the checklist button for authorized users on received transfers', function () {
    $transfer = checklistTransfer($this->branch, StockTransfer::STATUS_RECEIVED, 'TRF-PDF-BUTTON-RC');

    $this->actingAs(checklistDownloader())
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk()
        ->assertSee('Download Checklist PDF');
});

it('hides the checklist button for unauthorized users', function () {
    $transfer = checklistTransfer($this->branch, StockTransfer::STATUS_IN_TRANSIT, 'TRF-PDF-HIDDEN');

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk()
        ->assertDontSee('Download Checklist PDF');
});

it('hides and blocks the checklist for draft and submitted transfers', function (string $status) {
    $transfer = checklistTransfer($this->branch, $status, 'TRF-PDF-'.strtoupper($status));

    $this->actingAs(checklistDownloader())
        ->get(route('inventory.stock-transfers.show', $transfer))
        ->assertOk()
        ->assertDontSee('Download Checklist PDF');

    $this->actingAs(checklistDownloader())
        ->get(route('inventory.stock-transfers.checklist', $transfer))
        ->assertForbidden();
})->with([
    StockTransfer::STATUS_DRAFT,
    StockTransfer::STATUS_SUBMITTED,
]);
