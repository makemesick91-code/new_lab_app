<?php

/**
 * SPRINT-68.45 Scope D — inventory:procurement-workflow-audit.
 *
 * Read-only. UNSAFE anomalies (Kepala Cabang holding a PO-creation permission)
 * are FAIL and fail --strict (exit 2); data-quality notes (missing pr_type, GR
 * batch gaps) are WARN and never fail --strict.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\GoodsReceiptItem;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseOrderItem;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Services\Inventory\ProcurementWorkflowAuditService;
use Database\Seeders\BranchSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
});

function s6845Audit(): array
{
    return app(ProcurementWorkflowAuditService::class)->audit();
}

function s6845Check(array $report, string $checkId): array
{
    return collect($report['checks'])->firstWhere('check_id', $checkId);
}

it('reports GO with a clean setup and exits 0 under --strict', function () {
    $report = s6845Audit();

    expect($report['summary']['decision'])->toBe('GO');
    expect($report['summary']['errors'])->toBe(0);

    $this->artisan('inventory:procurement-workflow-audit', ['--strict' => true])->assertExitCode(0);
});

it('flags a missing pr_type as a WARN that does NOT fail --strict', function () {
    PurchaseRequest::factory()->create(['branch_id' => $this->branch->id, 'status' => 'submitted', 'pr_type' => null]);

    $report = s6845Audit();
    $check = s6845Check($report, 'pr_missing_type');

    expect($check['status'])->toBe('WARN');
    expect($check['count'])->toBeGreaterThan(0);
    expect($report['summary']['decision'])->toBe('WATCH');
    expect($report['summary']['errors'])->toBe(0);

    // WARN is informational — strict still exits 0.
    $this->artisan('inventory:procurement-workflow-audit', ['--strict' => true])->assertExitCode(0);
});

it('FAILs and exits 2 when the Kepala Cabang role leaks a PO-creation permission', function () {
    Role::findByName('Kepala Cabang')->givePermissionTo('manage_purchase_order');

    $report = s6845Audit();
    $check = s6845Check($report, 'kepala_cabang_role_po_permission_leak');

    expect($check['status'])->toBe('FAIL');
    expect($report['summary']['errors'])->toBeGreaterThan(0);
    expect($report['summary']['decision'])->toBe('NO-GO');

    $this->artisan('inventory:procurement-workflow-audit', ['--strict' => true])->assertExitCode(2);
    // Without --strict the command still exits 0 (reporting mode).
    $this->artisan('inventory:procurement-workflow-audit')->assertExitCode(0);
});

it('flags a posted batch-tracked GR item missing its batch as a WARN', function () {
    $product = Product::factory()->requiresBatchTracking()->create(['branch_id' => $this->branch->id]);
    $po = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branch->id]);
    $poItem = PurchaseOrderItem::factory()->create(['purchase_order_id' => $po->id, 'product_id' => $product->id]);
    $gr = GoodsReceipt::factory()->posted()->create(['branch_id' => $this->branch->id, 'purchase_order_id' => $po->id]);
    GoodsReceiptItem::factory()->create([
        'goods_receipt_id' => $gr->id,
        'purchase_order_item_id' => $poItem->id,
        'product_id' => $product->id,
        'inventory_batch_id' => null,
    ]);

    $check = s6845Check(s6845Audit(), 'gr_batch_tracked_missing_batch');

    expect($check['status'])->toBe('WARN');
    expect($check['count'])->toBeGreaterThan(0);
});

it('emits JSON with decision, summary counts, and grouped checks', function () {
    $report = s6845Audit();

    expect($report)->toHaveKeys(['generated_at', 'environment', 'summary', 'checks']);
    expect($report['summary'])->toHaveKeys(['checks', 'passed', 'warnings', 'errors', 'anomalies', 'decision']);
    expect($report['checks'])->toHaveCount(9);
    foreach ($report['checks'] as $check) {
        expect($check)->toHaveKeys(['check_id', 'category', 'status', 'message', 'count', 'details']);
    }

    $this->artisan('inventory:procurement-workflow-audit', ['--json' => true])
        ->expectsOutputToContain('"decision"')
        ->assertExitCode(0);
});
