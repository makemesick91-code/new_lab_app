<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-08 10:00:00');

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->mainBranch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    test()->mainBranch->update([
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
        'name' => 'Klinik Gigi Daengtisia Pusat',
    ]);

    test()->rmeBranch = Branch::factory()->create([
        'code' => 'ATG3',
        'name' => 'Cabang Antang',
        'is_active' => true,
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
    ]);

    test()->user = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('shows only locations from the selected branch in the location dropdown', function () {
    [$mainProduct] = createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-LOC',
        'location_name' => 'Main Warehouse Room',
    ]);

    [$antangProduct] = createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-LOC',
        'location_name' => 'Antang Treatment Room',
    ]);

    $mainLocation = InventoryLocation::where('branch_id', test()->mainBranch->id)
        ->where('name', 'Main Warehouse Room')
        ->firstOrFail();
    $antangLocation = InventoryLocation::where('branch_id', test()->rmeBranch->id)
        ->where('name', 'Antang Treatment Room')
        ->firstOrFail();

    $response = $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'branch_id' => test()->rmeBranch->id,
            'tab' => 'current-stock',
        ]))
        ->assertOk()
        ->assertSee('Antang Treatment Room')
        ->assertDontSee('Main Warehouse Room');

    $locationSelect = extractReportSelectOptions($response->getContent(), 'inventory_location_id');
    expect($locationSelect)->toContain((string) $antangLocation->id)
        ->not->toContain((string) $mainLocation->id);
});

it('does not show locations from another branch in the dropdown', function () {
    InventoryLocation::factory()->create([
        'branch_id' => test()->mainBranch->id,
        'name' => 'Hidden Main Location',
        'is_active' => true,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-ONLY',
        'location_name' => 'Visible Antang Location',
    ]);

    $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', ['branch_id' => test()->rmeBranch->id]))
        ->assertOk()
        ->assertSee('Visible Antang Location')
        ->assertDontSee('Hidden Main Location');
});

it('shows only branch-relevant batch options when product is selected', function () {
    [$product] = createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-BATCH',
        'product_name' => 'Antang Batch Product',
    ]);

    $antangBatch = InventoryBatch::factory()->create([
        'branch_id' => test()->rmeBranch->id,
        'product_id' => $product->id,
        'batch_number' => 'ATG-BATCH-001',
        'lot_number' => 'LOT-A',
        'is_active' => true,
    ]);

    [$otherProduct] = createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-BATCH',
    ]);

    $mainBatch = InventoryBatch::factory()->create([
        'branch_id' => test()->mainBranch->id,
        'product_id' => $otherProduct->id,
        'batch_number' => 'MAIN-BATCH-001',
        'is_active' => true,
    ]);

    InventoryMovement::factory()->create([
        'branch_id' => test()->rmeBranch->id,
        'product_id' => $product->id,
        'inventory_location_id' => InventoryLocation::where('branch_id', test()->rmeBranch->id)->firstOrFail()->id,
        'inventory_batch_id' => $antangBatch->id,
        'movement_type' => InventoryMovement::TYPE_OPENING,
        'movement_date' => '2026-06-05',
        'quantity_in' => 2,
        'quantity_out' => 0,
    ]);

    $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'stock-card',
            'branch_id' => test()->rmeBranch->id,
            'product_id' => $product->id,
        ]))
        ->assertOk()
        ->assertSee('ATG-BATCH-001')
        ->assertSee('LOT-A')
        ->assertDontSee('MAIN-BATCH-001')
        ->assertSee('value="'.$antangBatch->id.'"', false)
        ->assertDontSee('value="'.$mainBatch->id.'"', false);
});

it('preserves selected branch_id in tab links when switching tabs', function () {
    $response = $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'branch_id' => test()->rmeBranch->id,
            'tab' => 'current-stock',
        ]))
        ->assertOk();

    expect($response->getContent())
        ->toContain('branch_id='.test()->rmeBranch->id)
        ->toContain('tab=stock-card')
        ->toContain('tab=mutation');
});

it('clears invalid location_id for the selected branch safely', function () {
    [$product, $mainLocation] = createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-FILTER',
        'product_name' => 'Main Filter Product',
        'location_name' => 'Main Only Room',
        'quantity_in' => 4,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-FILTER',
        'product_name' => 'Antang Filter Product',
        'quantity_in' => 9,
    ]);

    $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'current-stock',
            'branch_id' => test()->rmeBranch->id,
            'inventory_location_id' => $mainLocation->id,
        ]))
        ->assertOk()
        ->assertSee('Antang Filter Product')
        ->assertDontSee('Main Filter Product')
        ->assertDontSee('value="'.$mainLocation->id.'" selected', false);
});

it('uses selected branch and branch-scoped location options for current stock', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-STOCK',
        'product_name' => 'Main Branch Stock Product',
        'location_name' => 'Main Stock Room',
        'quantity_in' => 3,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-STOCK',
        'product_name' => 'Antang Branch Stock Product',
        'location_name' => 'Antang Stock Room',
        'quantity_in' => 8,
    ]);

    $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'current-stock',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->assertSee('Antang Branch Stock Product')
        ->assertSee('Antang Stock Room')
        ->assertSee('8.0000')
        ->assertDontSee('Main Branch Stock Product');
});

it('keeps stock card product-required empty state with branch-scoped filters', function () {
    [$product] = createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-CARD',
        'product_name' => 'Antang Stock Card Product',
        'quantity_in' => 5,
        'movement_date' => '2026-06-05',
    ]);

    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-CARD',
        'product_name' => 'Main Stock Card Product',
        'quantity_in' => 9,
        'movement_date' => '2026-06-05',
    ]);

    $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'stock-card',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->assertSee('Pilih produk terlebih dahulu untuk melihat Kartu Stok.');

    $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'stock-card',
            'branch_id' => test()->rmeBranch->id,
            'product_id' => $product->id,
        ]))
        ->assertOk()
        ->assertSee('Antang Stock Card Product')
        ->assertDontSee('Main Stock Card Product');
});

it('preserves branch and date range filters on mutation report', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'MAIN-MUT',
        'product_name' => 'Main Mutation Product',
        'quantity_in' => 2,
        'movement_date' => '2026-06-07',
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-MUT',
        'product_name' => 'Antang Mutation Product',
        'quantity_in' => 6,
        'movement_date' => '2026-06-07',
    ]);

    $response = $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'mutation',
            'branch_id' => test()->rmeBranch->id,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk()
        ->assertSee('Antang Mutation Product')
        ->assertDontSee('Main Mutation Product');

    expect($response->getContent())
        ->toContain('value="'.test()->rmeBranch->id.'"', false)
        ->toContain('value="2026-06-01"', false)
        ->toContain('value="2026-06-30"', false);
});

it('includes selected branch in export link when export is available', function () {
    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'ATG-EXPORT',
        'product_name' => 'Antang Export Product',
        'quantity_in' => 2,
    ]);

    $response = $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'current-stock',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk();

    expect($response->getContent())
        ->toContain(route('inventory.reports.export', [], false))
        ->toContain('branch_id='.test()->rmeBranch->id)
        ->toContain('report_type=current_stock');
});

it('keeps sprint 68.29 tab-scoped loading behavior', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'TAB-SCOPE',
        'product_name' => 'Tab Scope Product',
        'quantity_in' => 2,
    ]);

    $this->actingAs(userWith(['view_inventory']))
        ->get(route('inventory.reports.index', [
            'tab' => 'current-stock',
            'branch_id' => test()->mainBranch->id,
        ]))
        ->assertOk()
        ->assertSee('data-report-panel="current-stock"', false)
        ->assertDontSee('data-report-panel="stock-card"', false)
        ->assertDontSee('data-report-panel="mutation"', false);
});

it('keeps sprint 68.30 branch filter integration behavior', function () {
    createReportStockRow(test()->mainBranch, [
        'product_code' => 'S6830-MAIN',
        'product_name' => 'S6830 Main Product',
        'quantity_in' => 2,
    ]);

    createReportStockRow(test()->rmeBranch, [
        'product_code' => 'S6830-ATG',
        'product_name' => 'S6830 Antang Product',
        'quantity_in' => 7,
    ]);

    $this->actingAs(test()->user)
        ->get(route('inventory.reports.index', [
            'tab' => 'current-stock',
            'branch_id' => test()->rmeBranch->id,
        ]))
        ->assertOk()
        ->assertSee('Cabang Antang')
        ->assertSee('S6830 Antang Product')
        ->assertDontSee('S6830 Main Product');
});

function extractReportSelectOptions(string $html, string $selectName): array
{
    if (! preg_match('/<select[^>]*name="'.$selectName.'"[^>]*>(.*?)<\/select>/s', $html, $matches)) {
        return [];
    }

    preg_match_all('/<option[^>]*value="([^"]*)"/', $matches[1], $options);

    return array_values(array_filter($options[1], fn ($value) => $value !== ''));
}
