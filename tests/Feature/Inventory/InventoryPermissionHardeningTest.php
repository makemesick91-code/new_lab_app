<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\InventoryBatch;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\PurchaseRequest;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockTransfer;
use Database\Seeders\BranchSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedAccessControl();
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
});

describe('backward compatibility with legacy inventory permissions', function () {
    it('keeps view_inventory as superset for all granular view abilities', function () {
        $viewer = userWith(['view_inventory']);
        $this->actingAs($viewer);

        expect($viewer->can('viewAny', StockOpname::class))->toBeTrue()
            ->and($viewer->can('viewAny', InventoryBatch::class))->toBeTrue()
            ->and($viewer->can('viewAlerts', InventoryMovement::class))->toBeTrue()
            ->and($viewer->can('viewAnalytics', InventoryMovement::class))->toBeTrue()
            ->and($viewer->can('viewAny', StockTransfer::class))->toBeTrue()
            ->and($viewer->can('viewAny', PurchaseRequest::class))->toBeTrue()
            ->and($viewer->can('viewAny', PurchaseOrder::class))->toBeTrue()
            ->and($viewer->can('viewAny', GoodsReceipt::class))->toBeTrue();
    });

    it('keeps manage_inventory as superset for granular manage abilities', function () {
        $manager = userWith(['manage_inventory']);
        $this->actingAs($manager);

        $draftPr = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);
        $submittedPr = PurchaseRequest::factory()->submitted()->create(['branch_id' => $this->branch->id]);
        $draftPo = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id]);
        $submittedPo = PurchaseOrder::factory()->submitted()->create(['branch_id' => $this->branch->id]);
        $transfer = StockTransfer::factory()->create(['branch_id' => $this->branch->id]);
        $opname = StockOpname::factory()->create(['branch_id' => $this->branch->id]);
        $purchaseOrder = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branch->id]);
        $draftGr = GoodsReceipt::factory()->forPurchaseOrder($purchaseOrder)->draft()->create([
            'branch_id' => $this->branch->id,
        ]);

        expect($manager->can('create', StockOpname::class))->toBeTrue()
            ->and($manager->can('create', StockTransfer::class))->toBeTrue()
            ->and($manager->can('create', PurchaseRequest::class))->toBeTrue()
            ->and($manager->can('create', PurchaseOrder::class))->toBeTrue()
            ->and($manager->can('create', GoodsReceipt::class))->toBeTrue()
            ->and($manager->can('update', $draftPr))->toBeTrue()
            ->and($manager->can('approve', $submittedPr))->toBeTrue()
            ->and($manager->can('approve', $submittedPo))->toBeTrue()
            ->and($manager->can('submit', $transfer))->toBeTrue()
            ->and($manager->can('finalize', $opname))->toBeTrue()
            ->and($manager->can('post', $draftGr))->toBeTrue();
    });

    it('preserves Admin Lab role access to procurement and inventory', function () {
        $admin = User::factory()->create();
        $admin->assignRole('Admin Lab');
        $this->actingAs($admin);

        expect($admin->can('viewAny', Product::class))->toBeTrue()
            ->and($admin->can('viewAny', PurchaseRequest::class))->toBeTrue()
            ->and($admin->can('viewAny', GoodsReceipt::class))->toBeTrue()
            ->and($admin->can('create', Product::class))->toBeTrue();
    });

    it('preserves Technician view_inventory access to core inventory', function () {
        $technician = User::factory()->create();
        $technician->assignRole('Technician');
        $this->actingAs($technician);

        expect(Role::findByName('Technician')->hasPermissionTo('view_inventory'))->toBeTrue()
            ->and($technician->can('viewAny', Product::class))->toBeTrue()
            ->and($technician->can('viewAlerts', InventoryMovement::class))->toBeTrue();
    });
});

describe('granular permissions without legacy supersets', function () {
    it('allows view_stock_transfer only for stock transfer read access', function () {
        $user = userWith(['view_stock_transfer']);
        $this->actingAs($user);

        expect($user->can('viewAny', StockTransfer::class))->toBeTrue()
            ->and($user->can('viewAny', Product::class))->toBeFalse()
            ->and($user->can('viewAlerts', InventoryMovement::class))->toBeFalse()
            ->and($user->can('create', StockTransfer::class))->toBeFalse();
    });

    it('allows manage_stock_transfer for transfer workflow without manage_inventory', function () {
        $user = userWith(['view_stock_transfer', 'manage_stock_transfer']);
        $transfer = StockTransfer::factory()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($user);

        expect($user->can('create', StockTransfer::class))->toBeTrue()
            ->and($user->can('submit', $transfer))->toBeTrue()
            ->and($user->can('create', Product::class))->toBeFalse();
    });

    it('allows view_purchase_request without view_inventory', function () {
        $user = userWith(['view_purchase_request']);
        $this->actingAs($user);

        expect($user->can('viewAny', PurchaseRequest::class))->toBeTrue()
            ->and($user->can('viewAny', Product::class))->toBeFalse()
            ->and($user->can('create', PurchaseRequest::class))->toBeFalse();
    });

    it('allows manage_purchase_request without manage_inventory for draft workflow', function () {
        $user = userWith(['manage_purchase_request']);
        $draft = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($user);

        expect($user->can('create', PurchaseRequest::class))->toBeTrue()
            ->and($user->can('update', $draft))->toBeTrue()
            ->and($user->can('approve', PurchaseRequest::factory()->submitted()->create(['branch_id' => $this->branch->id])))->toBeFalse();
    });

    it('keeps approval separate from manage_purchase_request', function () {
        $approver = userWith(['approve_inventory_purchase_request', 'view_purchase_request']);
        $submitted = PurchaseRequest::factory()->submitted()->create(['branch_id' => $this->branch->id]);
        $draft = PurchaseRequest::factory()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($approver);

        expect($approver->can('approve', $submitted))->toBeTrue()
            ->and($approver->can('reject', $submitted))->toBeTrue()
            ->and($approver->can('create', PurchaseRequest::class))->toBeFalse()
            ->and($approver->can('update', $draft))->toBeFalse();
    });

    it('keeps approval separate from manage_purchase_order', function () {
        $approver = userWith(['approve_inventory_purchase_order', 'view_purchase_order']);
        $submitted = PurchaseOrder::factory()->submitted()->create(['branch_id' => $this->branch->id]);
        $draft = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($approver);

        expect($approver->can('approve', $submitted))->toBeTrue()
            ->and($approver->can('create', PurchaseOrder::class))->toBeFalse()
            ->and($approver->can('update', $draft))->toBeFalse();
    });

    it('allows view_stock_alert for alerts page without view_inventory', function () {
        $user = userWith(['view_stock_alert']);
        $this->actingAs($user);

        expect($user->can('viewAlerts', InventoryMovement::class))->toBeTrue()
            ->and($user->can('viewAny', Product::class))->toBeFalse();

        $this->get(route('inventory.alerts.index'))
            ->assertOk()
            ->assertSee('Peringatan Persediaan');
    });

    it('denies alerts page when user lacks stock alert and legacy view permissions', function () {
        $user = userWith(['view_stock_transfer']);
        $this->actingAs($user);

        $this->get(route('inventory.alerts.index'))
            ->assertForbidden();
    });

    it('allows view_inventory_analytics for analytics page without view_inventory', function () {
        $user = userWith(['view_inventory_analytics']);
        $this->actingAs($user);

        expect($user->can('viewAnalytics', InventoryMovement::class))->toBeTrue()
            ->and($user->can('viewAny', Product::class))->toBeFalse()
            ->and($user->can('exportAnalytics', InventoryMovement::class))->toBeFalse();

        $this->get(route('inventory.analytics.index'))
            ->assertOk()
            ->assertSee('Analitik Persediaan');
    });

    it('allows manage_inventory_analytics for export ability without manage_inventory', function () {
        $user = userWith(['view_inventory_analytics', 'manage_inventory_analytics']);
        $this->actingAs($user);

        expect($user->can('exportAnalytics', InventoryMovement::class))->toBeTrue()
            ->and($user->can('create', Product::class))->toBeFalse();
    });

    it('allows view_stock_opname without view_inventory', function () {
        $user = userWith(['view_stock_opname']);
        $this->actingAs($user);

        expect($user->can('viewAny', StockOpname::class))->toBeTrue()
            ->and($user->can('create', StockOpname::class))->toBeFalse()
            ->and($user->can('viewAny', Product::class))->toBeFalse();
    });

    it('allows view_inventory_batch_lot without view_inventory', function () {
        $user = userWith(['view_inventory_batch_lot']);
        $batch = InventoryBatch::factory()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($user);

        expect($user->can('viewAny', InventoryBatch::class))->toBeTrue()
            ->and($user->can('view', $batch))->toBeTrue()
            ->and($user->can('viewAny', Product::class))->toBeFalse();
    });

    it('allows view_goods_receipt without view_inventory', function () {
        $user = userWith(['view_goods_receipt']);
        $this->actingAs($user);

        expect($user->can('viewAny', GoodsReceipt::class))->toBeTrue()
            ->and($user->can('create', GoodsReceipt::class))->toBeFalse()
            ->and($user->can('viewAny', Product::class))->toBeFalse();
    });

    it('allows manage_goods_receipt without manage_inventory', function () {
        $user = userWith(['manage_goods_receipt']);
        $purchaseOrder = PurchaseOrder::factory()->sent()->create(['branch_id' => $this->branch->id]);
        $draft = GoodsReceipt::factory()->forPurchaseOrder($purchaseOrder)->draft()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->actingAs($user);

        expect($user->can('create', GoodsReceipt::class))->toBeTrue()
            ->and($user->can('post', $draft))->toBeTrue()
            ->and($user->can('create', Product::class))->toBeFalse();
    });
});

describe('sidebar visibility with granular permissions', function () {
    it('shows inventory group for granular stock transfer viewer without core inventory links', function () {
        $user = userWith(['view_stock_transfer']);
        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Persediaan')
            ->assertSee('Transfer Stok')
            ->assertDontSee('>Produk</a>', false)
            ->assertDontSee('>Dasbor</a>', false);
    });

    it('shows procurement group for granular purchase request viewer', function () {
        $user = userWith(['view_purchase_request']);
        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Pengadaan')
            ->assertSee('Permintaan Pembelian')
            ->assertDontSee('Pesanan Pembelian');
    });

    it('shows core inventory links for view_inventory users', function () {
        $user = userWith(['view_inventory']);
        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Produk')
            ->assertSee('Peringatan Stok')
            ->assertSee('Analitik Persediaan');
    });
});
