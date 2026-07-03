<?php

use App\Modules\Branch\Models\Branch;
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
        'code' => 'T6843',
        'name' => 'Cabang Report 6843',
        'is_active' => true,
        'is_rme_enabled' => true,
        'is_inventory_enabled' => true,
    ]);

    test()->reportUser = userWith(['view_inventory', 'view_inventory_cross_branch_analytics']);
    test()->branchUser = userWith(['view_inventory']);
    test()->approver = userWith(['manage_inventory', 'view_inventory_batch_lot']);
    test()->operator = userWith(['manage_inventory_batch_lot']);
    test()->outsider = userWith(['view dashboard']);
});

afterEach(function () {
    Carbon::setTestNow();
});

function sprint6843BatchWithStock(Branch $branch, array $batchOverrides = [], float $quantity = 10): array
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

function sprint6843CreateDisposalRequest(
    Branch $branch,
    array $batchOverrides = [],
    string $status = InventoryBatchDisposalRequestStatus::SUBMITTED,
    array $requestOverrides = [],
): InventoryBatchDisposalRequest {
    $requestFields = array_intersect_key($batchOverrides, array_flip([
        'request_type',
        'quantity_requested',
        'evidence_reference',
        'submitted_at',
    ]));
    $batchOnlyOverrides = array_diff_key($batchOverrides, $requestFields);

    $data = sprint6843BatchWithStock($branch, $batchOnlyOverrides);

    return InventoryBatchDisposalRequest::query()->create(array_merge([
        'branch_id' => $branch->id,
        'inventory_batch_id' => $data['batch']->id,
        'inventory_location_id' => $data['location']->id,
        'product_id' => $data['product']->id,
        'request_type' => InventoryBatchDisposalRequestType::DISPOSAL,
        'status' => $status,
        'quantity_requested' => 5,
        'available_quantity_snapshot' => 10,
        'evidence_note' => 'Audit note',
        'evidence_reference' => 'BA-6843',
        'submitted_by' => test()->operator->id,
        'submitted_at' => now(),
    ], $requestFields, $requestOverrides));
}

it('allows authorized inventory report user to view batch disposal report index', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-VIEW']);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index'))
        ->assertOk()
        ->assertSee('Laporan Disposal & Adjustment Batch')
        ->assertSee('B6843-VIEW');
});

it('denies unauthorized user from viewing batch disposal report', function () {
    $this->actingAs(test()->outsider)
        ->get(route('inventory.reports.batch-disposals.index'))
        ->assertForbidden();
});

it('scopes normal branch user to current branch disposal requests only', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-MAIN']);
    sprint6843CreateDisposalRequest(test()->otherBranch, ['batch_number' => 'B6843-OTHER']);

    $this->actingAs(test()->branchUser)
        ->get(route('inventory.reports.batch-disposals.index'))
        ->assertOk()
        ->assertSee('B6843-MAIN')
        ->assertDontSee('B6843-OTHER');
});

it('allows cross branch authorized user to filter selected branch', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-XMAIN']);
    sprint6843CreateDisposalRequest(test()->otherBranch, ['batch_number' => 'B6843-XOTHER']);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index', ['branch_id' => test()->otherBranch->id]))
        ->assertOk()
        ->assertSee('B6843-XOTHER')
        ->assertDontSee('B6843-XMAIN');
});

it('ignores branch_id filter for unauthorized cross branch user', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-IGN-MAIN']);
    sprint6843CreateDisposalRequest(test()->otherBranch, ['batch_number' => 'B6843-IGN-OTHER']);

    $this->actingAs(test()->branchUser)
        ->get(route('inventory.reports.batch-disposals.index', ['branch_id' => test()->otherBranch->id]))
        ->assertOk()
        ->assertSee('B6843-IGN-MAIN')
        ->assertDontSee('B6843-IGN-OTHER');
});

it('calculates summary counts for submitted approved rejected and adjustment recorded', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-S1'], InventoryBatchDisposalRequestStatus::SUBMITTED);
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-S2'], InventoryBatchDisposalRequestStatus::APPROVED);
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-S3'], InventoryBatchDisposalRequestStatus::REJECTED);
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-S4'], InventoryBatchDisposalRequestStatus::ADJUSTMENT_RECORDED);

    $response = $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk();

    $html = $response->getContent();
    expect($html)->toContain('Total Request')
        ->and($html)->toContain('Menunggu Approval')
        ->and($html)->toContain('Adjustment Dicatat');
});

it('sums total quantity requested from disposal requests', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, ['quantity_requested' => 3]);
    sprint6843CreateDisposalRequest(test()->mainBranch, ['quantity_requested' => 7]);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('10');
});

it('includes adjustment recorded quantity only for adjustment recorded status', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, ['quantity_requested' => 4], InventoryBatchDisposalRequestStatus::SUBMITTED);
    sprint6843CreateDisposalRequest(test()->mainBranch, ['quantity_requested' => 6], InventoryBatchDisposalRequestStatus::ADJUSTMENT_RECORDED);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('Qty Adjustment Dicatat');
});

it('shows linked action log in report rows when present', function () {
    $data = sprint6843BatchWithStock(test()->mainBranch, ['batch_number' => 'B6843-ACT']);
    $actionLog = InventoryBatchActionLog::query()->create([
        'branch_id' => test()->mainBranch->id,
        'inventory_batch_id' => $data['batch']->id,
        'action_type' => 'disposal_planned',
        'note' => 'Rencana pemusnahan',
        'acted_by' => test()->operator->id,
        'acted_at' => now(),
    ]);

    InventoryBatchDisposalRequest::query()->create([
        'branch_id' => test()->mainBranch->id,
        'inventory_batch_id' => $data['batch']->id,
        'inventory_batch_action_log_id' => $actionLog->id,
        'inventory_location_id' => $data['location']->id,
        'product_id' => $data['product']->id,
        'request_type' => InventoryBatchDisposalRequestType::DISPOSAL,
        'status' => InventoryBatchDisposalRequestStatus::SUBMITTED,
        'quantity_requested' => 2,
        'available_quantity_snapshot' => 10,
        'evidence_note' => 'Action log linked',
        'evidence_reference' => 'BA-6843-ACT',
        'submitted_by' => test()->operator->id,
        'submitted_at' => now(),
    ]);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('Rencana Pemusnahan');
});

it('shows linked adjustment out movement when finalized', function () {
    $data = sprint6843BatchWithStock(test()->mainBranch, ['batch_number' => 'B6843-MOV'], 12);
    $movement = InventoryMovement::factory()->create([
        'branch_id' => test()->mainBranch->id,
        'inventory_location_id' => $data['location']->id,
        'product_id' => $data['product']->id,
        'inventory_batch_id' => $data['batch']->id,
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
        'quantity_in' => 0,
        'quantity_out' => 5,
    ]);

    InventoryBatchDisposalRequest::query()->create([
        'branch_id' => test()->mainBranch->id,
        'inventory_batch_id' => $data['batch']->id,
        'inventory_location_id' => $data['location']->id,
        'product_id' => $data['product']->id,
        'request_type' => InventoryBatchDisposalRequestType::DISPOSAL,
        'status' => InventoryBatchDisposalRequestStatus::ADJUSTMENT_RECORDED,
        'quantity_requested' => 5,
        'available_quantity_snapshot' => 12,
        'evidence_note' => 'Finalized disposal',
        'evidence_reference' => 'BA-6843-MOV',
        'inventory_movement_id' => $movement->id,
        'submitted_by' => test()->operator->id,
        'submitted_at' => now(),
        'finalized_by' => test()->approver->id,
        'finalized_at' => now(),
    ]);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->assertSee('ADJUSTMENT_OUT')
        ->assertSee('Kartu Stok');
});

it('does not show movement when request is not finalized', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-NOMOV'], InventoryBatchDisposalRequestStatus::APPROVED);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index', ['branch_id' => test()->mainBranch->id, 'has_movement' => 'no']))
        ->assertOk()
        ->assertSee('B6843-NOMOV');
});

it('does not create inventory movements when viewing report', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch);

    $before = InventoryMovement::query()->count();

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index'))
        ->assertOk();

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.export'))
        ->assertOk();

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.print'))
        ->assertOk();

    expect(InventoryMovement::query()->count())->toBe($before);
});

it('does not change ledger stock when viewing report', function () {
    $data = sprint6843BatchWithStock(test()->mainBranch, quantity: 15);
    sprint6843CreateDisposalRequest(test()->mainBranch);

    $stockService = app(InventoryStockService::class);
    $before = $stockService->getCurrentStockByBatch(
        $data['product']->id,
        $data['location']->id,
        $data['batch']->id,
    );

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index'))
        ->assertOk();

    $after = $stockService->getCurrentStockByBatch(
        $data['product']->id,
        $data['location']->id,
        $data['batch']->id,
    );

    expect($after)->toBe($before);
});

it('filters report rows by status', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-ST-SUB'], InventoryBatchDisposalRequestStatus::SUBMITTED);
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-ST-REJ'], InventoryBatchDisposalRequestStatus::REJECTED);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index', [
            'branch_id' => test()->mainBranch->id,
            'status' => InventoryBatchDisposalRequestStatus::REJECTED,
        ]))
        ->assertOk()
        ->assertSee('B6843-ST-REJ')
        ->assertDontSee('B6843-ST-SUB');
});

it('filters report rows by request type', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, [
        'batch_number' => 'B6843-TYPE-EXP',
        'request_type' => InventoryBatchDisposalRequestType::EXPIRED,
    ]);
    sprint6843CreateDisposalRequest(test()->mainBranch, [
        'batch_number' => 'B6843-TYPE-DIS',
        'request_type' => InventoryBatchDisposalRequestType::DISPOSAL,
    ]);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index', [
            'branch_id' => test()->mainBranch->id,
            'request_type' => InventoryBatchDisposalRequestType::EXPIRED,
        ]))
        ->assertOk()
        ->assertSee('B6843-TYPE-EXP')
        ->assertDontSee('B6843-TYPE-DIS');
});

it('filters report rows by date range', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, [
        'batch_number' => 'B6843-DATE-OLD',
        'submitted_at' => Carbon::parse('2026-06-01 10:00:00'),
    ]);
    sprint6843CreateDisposalRequest(test()->mainBranch, [
        'batch_number' => 'B6843-DATE-NEW',
        'submitted_at' => Carbon::parse('2026-07-02 10:00:00'),
    ]);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index', [
            'branch_id' => test()->mainBranch->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]))
        ->assertOk()
        ->assertSee('B6843-DATE-NEW')
        ->assertDontSee('B6843-DATE-OLD');
});

it('filters report rows by product and batch search', function () {
    $product = Product::factory()->create([
        'branch_id' => test()->mainBranch->id,
        'name' => 'Produk Report Alpha',
        'code' => 'PRA-6843',
    ]);
    sprint6843CreateDisposalRequest(test()->mainBranch, [
        'product' => $product,
        'batch_number' => 'BATCH-ALPHA-6843',
    ]);
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'BATCH-OTHER-6843']);

    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index', [
            'branch_id' => test()->mainBranch->id,
            'product' => 'Alpha',
            'batch' => 'ALPHA',
        ]))
        ->assertOk()
        ->assertSee('BATCH-ALPHA-6843')
        ->assertDontSee('BATCH-OTHER-6843');
});

it('exports csv with expected headers and branch scope', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-CSV-MAIN']);
    sprint6843CreateDisposalRequest(test()->otherBranch, ['batch_number' => 'B6843-CSV-OTHER']);

    $content = $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.export', ['branch_id' => test()->mainBranch->id]))
        ->assertOk()
        ->streamedContent();

    expect($content)
        ->toContain('batch_number')
        ->toContain('movement_type')
        ->toContain('B6843-CSV-MAIN')
        ->not->toContain('B6843-CSV-OTHER');
});

it('renders print page scoped to branch', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch, ['batch_number' => 'B6843-PRINT-MAIN']);
    sprint6843CreateDisposalRequest(test()->otherBranch, ['batch_number' => 'B6843-PRINT-OTHER']);

    $this->actingAs(test()->branchUser)
        ->get(route('inventory.reports.batch-disposals.print'))
        ->assertOk()
        ->assertSee('B6843-PRINT-MAIN')
        ->assertDontSee('B6843-PRINT-OTHER')
        ->assertSee('Admin Warehouse');
});

it('registers sidebar guarded batch disposal report route', function () {
    $this->actingAs(test()->reportUser)
        ->get(route('inventory.reports.batch-disposals.index'))
        ->assertOk()
        ->assertSee('Disposal & Adjustment Batch', false);
});

it('smokes index export and print routes', function () {
    sprint6843CreateDisposalRequest(test()->mainBranch);

    $this->actingAs(test()->reportUser)->get(route('inventory.reports.batch-disposals.index'))->assertOk();
    $this->actingAs(test()->reportUser)->get(route('inventory.reports.batch-disposals.export'))->assertOk();
    $this->actingAs(test()->reportUser)->get(route('inventory.reports.batch-disposals.print'))->assertOk();
});
