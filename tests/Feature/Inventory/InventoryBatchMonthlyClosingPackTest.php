<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Enums\InventoryBatchActionType;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestStatus;
use App\Modules\Inventory\Enums\InventoryBatchDisposalRequestType;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryBatchActionLog;
use App\Modules\Inventory\Models\InventoryBatchDisposalRequest;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Carbon::setTestNow('2026-07-03 12:00:00');

    test()->mainBranch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    test()->mainBranch->update([
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
    ]);

    test()->otherBranch = Branch::factory()->create([
        'code' => 'T6844',
        'name' => 'Cabang Closing 6844',
        'is_active' => true,
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
    ]);

    test()->reportUser = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);
    test()->branchUser = userWith(['view_inventory']);
    test()->operator = userWith(['manage_inventory_batch_lot']);
    test()->outsider = userWith(['view dashboard']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function sprint6844BatchWithStock(Branch $branch, array $batchOverrides = [], float $quantity = 10): array
{
    $product = $batchOverrides['product'] ?? Product::factory()->create(['branch_id' => $branch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => $branch->id]);

    unset($batchOverrides['product']);

    $batch = InventoryBatch::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'expiry_date' => now()->subDays(2)->toDateString(),
    ], $batchOverrides));

    InventoryMovement::factory()->purchase()->create([
        'branch_id' => $branch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'quantity_in' => $quantity,
        'quantity_out' => 0,
    ]);

    return compact('batch', 'product', 'location');
}

function sprint6844CreateDisposalRequest(
    Branch $branch,
    array $batchOverrides = [],
    string $status = InventoryBatchDisposalRequestStatus::SUBMITTED,
    array $requestOverrides = [],
): InventoryBatchDisposalRequest {
    $data = sprint6844BatchWithStock($branch, $batchOverrides);

    return InventoryBatchDisposalRequest::query()->create(array_merge([
        'branch_id' => $branch->id,
        'inventory_batch_id' => $data['batch']->id,
        'inventory_location_id' => $data['location']->id,
        'product_id' => $data['product']->id,
        'request_type' => InventoryBatchDisposalRequestType::DISPOSAL,
        'status' => $status,
        'quantity_requested' => 5,
        'available_quantity_snapshot' => 10,
        'evidence_note' => 'Closing pack note',
        'evidence_reference' => 'BA-6844',
        'submitted_by' => test()->operator->id,
        'submitted_at' => now(),
    ], $requestOverrides));
}

it('allows authorized inventory report user to view monthly closing pack', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6844-VIEW']);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index'))
        ->assertOk()
        ->assertSee('Closing Bulanan Governance Batch')
        ->assertSee('B6844-VIEW');
});

it('denies unauthorized user from viewing monthly closing pack', function () {
    $this->actingAs(test()->outsider)
        ->get(route('inventory.reports.batch-monthly-closing.index'))
        ->assertForbidden();
});

it('scopes normal branch user to current branch data only', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6844-MAIN']);
    sprint6844CreateDisposalRequest(test()->otherBranch, ['batch_number' => 'B6844-OTHER']);

    $this->actingAs(test()->branchUser)
        ->get(route('inventory.reports.batch-monthly-closing.index'))
        ->assertOk()
        ->assertSee('B6844-MAIN')
        ->assertDontSee('B6844-OTHER');
});

it('allows cross branch authorized user to filter selected branch', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6844-XMAIN']);
    sprint6844CreateDisposalRequest(test()->otherBranch, ['batch_number' => 'B6844-XOTHER']);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index', ['branch_id' => test()->otherBranch->id]))
        ->assertOk()
        ->assertSee('B6844-XOTHER')
        ->assertDontSee('B6844-XMAIN');
});

it('ignores branch_id filter for unauthorized cross branch user', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6844-IGN-MAIN']);
    sprint6844CreateDisposalRequest(test()->otherBranch, ['batch_number' => 'B6844-IGN-OTHER']);

    $this->actingAs(test()->branchUser)
        ->get(route('inventory.reports.batch-monthly-closing.index', ['branch_id' => test()->otherBranch->id]))
        ->assertOk()
        ->assertSee('B6844-IGN-MAIN')
        ->assertDontSee('B6844-IGN-OTHER');
});

it('defaults period to current month', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index'))
        ->assertOk()
        ->assertSee('Juli 2026')
        ->assertSee(now()->startOfMonth()->toDateString());
});

it('filters by year and month', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6844-JUN'], InventoryBatchDisposalRequestStatus::SUBMITTED, [
        'submitted_at' => Carbon::parse('2026-06-15'),
    ]);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index', ['year' => 2026, 'month' => 6]))
        ->assertOk()
        ->assertSee('Juni 2026')
        ->assertSee('B6844-JUN');
});

it('counts expired batch with positive ledger stock in summary', function () {
    sprint6844BatchWithStock(test()->mainBranch, [
        'batch_number' => 'B6844-EXPIRED-POS',
        'expiry_date' => now()->subDays(5)->toDateString(),
    ], 8);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('Batch Kedaluwarsa Bersaldo')
        ->assertSee('B6844-EXPIRED-POS');
});

it('counts near expiry batch with positive ledger stock in summary', function () {
    sprint6844BatchWithStock(test()->mainBranch, [
        'batch_number' => 'B6844-NEAR-POS',
        'expiry_date' => now()->addDays(30)->toDateString(),
    ], 6);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('Batch Akan Kedaluwarsa Bersaldo')
        ->assertSee('B6844-NEAR-POS');
});

it('excludes expired batch without positive stock from expiry risk list', function () {
    $product = Product::factory()->create(['branch_id' => test()->mainBranch->id]);
    $location = InventoryLocation::factory()->create(['branch_id' => test()->mainBranch->id]);
    $batch = InventoryBatch::factory()->create([
        'branch_id' => test()->mainBranch->id,
        'product_id' => $product->id,
        'batch_number' => 'B6844-EXPIRED-ZERO',
        'expiry_date' => now()->subDays(10)->toDateString(),
    ]);

    InventoryMovement::factory()->purchase()->create([
        'branch_id' => test()->mainBranch->id,
        'inventory_location_id' => $location->id,
        'product_id' => $product->id,
        'inventory_batch_id' => $batch->id,
        'quantity_in' => 5,
        'quantity_out' => 5,
    ]);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertDontSee('B6844-EXPIRED-ZERO');
});

it('includes period action logs in action log section', function () {
    $data = sprint6844BatchWithStock(test()->mainBranch, ['batch_number' => 'B6844-LOG']);
    InventoryBatchActionLog::query()->create([
        'branch_id' => test()->mainBranch->id,
        'inventory_batch_id' => $data['batch']->id,
        'action_type' => InventoryBatchActionType::QUARANTINE,
        'note' => 'Karantina bulan ini',
        'acted_by' => test()->operator->id,
        'acted_at' => now(),
    ]);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('Karantina')
        ->assertSee('Karantina bulan ini');
});

it('includes period disposal requests in disposal workflow section', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6844-DISP']);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('B6844-DISP')
        ->assertSee('Ringkasan Disposal');
});

it('includes linked adjustment out movements in ledger evidence section', function () {
    $data = sprint6844BatchWithStock(test()->mainBranch, ['batch_number' => 'B6844-LEDGER'], 12);
    $movement = InventoryMovement::factory()->create([
        'branch_id' => test()->mainBranch->id,
        'inventory_location_id' => $data['location']->id,
        'product_id' => $data['product']->id,
        'inventory_batch_id' => $data['batch']->id,
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
        'quantity_in' => 0,
        'quantity_out' => 4,
        'movement_date' => now()->toDateString(),
    ]);

    InventoryBatchDisposalRequest::query()->create([
        'branch_id' => test()->mainBranch->id,
        'inventory_batch_id' => $data['batch']->id,
        'inventory_location_id' => $data['location']->id,
        'product_id' => $data['product']->id,
        'request_type' => InventoryBatchDisposalRequestType::DISPOSAL,
        'status' => InventoryBatchDisposalRequestStatus::ADJUSTMENT_RECORDED,
        'quantity_requested' => 4,
        'available_quantity_snapshot' => 12,
        'evidence_note' => 'Ledger evidence test',
        'evidence_reference' => 'BA-6844-LEDGER',
        'inventory_movement_id' => $movement->id,
        'submitted_by' => test()->operator->id,
        'submitted_at' => now(),
        'finalized_by' => test()->operator->id,
        'finalized_at' => now(),
    ]);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('Ledger Evidence')
        ->assertSee('ADJUSTMENT_OUT')
        ->assertSee('Kartu Stok');
});

it('includes expired positive stock batch without action log as exception', function () {
    sprint6844BatchWithStock(test()->mainBranch, [
        'batch_number' => 'B6844-EXC-NOLOG',
        'expiry_date' => now()->subDays(3)->toDateString(),
    ]);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('expired_no_action_log')
        ->assertSee('B6844-EXC-NOLOG');
});

it('includes approved request not finalized as exception', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6844-EXC-APPR'], InventoryBatchDisposalRequestStatus::APPROVED);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('approved_not_finalized')
        ->assertSee('B6844-EXC-APPR');
});

it('exports csv with expected headers and sections', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6844-CSV']);

    $response = $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.export', ['branch_id' => test()->mainBranch->id]));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain('section')
        ->and($content)->toContain('expiry_risk')
        ->and($content)->toContain('disposal_workflow')
        ->and($content)->toContain('B6844-CSV');
});

it('scopes csv export to selected branch', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6844-CSV-MAIN']);
    sprint6844CreateDisposalRequest(test()->otherBranch, ['batch_number' => 'B6844-CSV-OTHER']);

    $content = $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.export', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain('B6844-CSV-MAIN')
        ->and($content)->not->toContain('B6844-CSV-OTHER');
});

it('renders print page', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.print', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('Closing Bulanan Governance Batch')
        ->assertSee('Admin Warehouse')
        ->assertSee('Checklist Closing');
});

it('scopes print page to selected branch', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6844-PRINT-MAIN']);
    sprint6844CreateDisposalRequest(test()->otherBranch, ['batch_number' => 'B6844-PRINT-OTHER']);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.print', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('B6844-PRINT-MAIN')
        ->assertDontSee('B6844-PRINT-OTHER');
});

it('does not create inventory movements when viewing closing pack', function () {
    sprint6844CreateDisposalRequest(test()->mainBranch);

    $before = InventoryMovement::query()->count();

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index'))
        ->assertOk();

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.export'))
        ->assertOk();

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.print'))
        ->assertOk();

    expect(InventoryMovement::query()->count())->toBe($before);
});

it('does not change ledger stock when viewing closing pack', function () {
    $data = sprint6844BatchWithStock(test()->mainBranch, quantity: 15);
    sprint6844CreateDisposalRequest(test()->mainBranch);

    $stockService = app(InventoryStockService::class);
    $before = $stockService->getCurrentStockByBatch(
        $data['product']->id,
        $data['location']->id,
        $data['batch']->id,
    );

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index'))
        ->assertOk();

    $after = $stockService->getCurrentStockByBatch(
        $data['product']->id,
        $data['location']->id,
        $data['batch']->id,
    );

    expect($after)->toBe($before);
});

it('registers sidebar route for monthly closing pack', function () {
    expect(Route::has('inventory.reports.batch-monthly-closing.index'))->toBeTrue();

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-monthly-closing.index'))
        ->assertOk();
});
