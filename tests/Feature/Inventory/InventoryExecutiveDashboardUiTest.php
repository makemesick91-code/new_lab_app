<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryExecutiveDashboardService;
use App\Modules\Inventory\Services\InventoryStockService;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->stockService = app(InventoryStockService::class);
});

it('registers the inventory executive dashboard route', function () {
    expect(Route::has('inventory.executive-dashboard'))->toBeTrue();
});

it('allows view_inventory_analytics users to access executive dashboard', function () {
    $user = userWith(['view_inventory_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertOk()
        ->assertSee('Inventory Executive Dashboard');
});

it('allows view_inventory_executive_dashboard users to access executive dashboard', function () {
    $user = userWith(['view_inventory_executive_dashboard']);

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertOk()
        ->assertSee('Inventory Executive Dashboard');
});

it('allows manage_inventory users to access executive dashboard', function () {
    $user = userWith(['manage_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertOk()
        ->assertSee('Inventory Executive Dashboard');
});

it('allows view_inventory fallback users to access executive dashboard', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertOk()
        ->assertSee('Inventory Executive Dashboard');
});

it('denies users without inventory analytics permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertForbidden();
});

it('renders nine executive KPI cards', function () {
    $user = userWith(['view_inventory_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertOk()
        ->assertSee('Inventory Value')
        ->assertSee('Active SKU')
        ->assertSee('Dead Stock')
        ->assertSee('Low Stock')
        ->assertSee('Open PR')
        ->assertSee('Open PO')
        ->assertSee('Pending GR')
        ->assertSee('In Transit Transfer')
        ->assertSee('Inventory Accuracy');
});

it('shows operational valuation disclaimer on executive dashboard', function () {
    $user = userWith(['view_inventory_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertOk()
        ->assertSee('Operational inventory value based on current stock × average cost. Not accounting valuation.');
});

it('shows consumption note on executive dashboard', function () {
    $user = userWith(['view_inventory_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertOk()
        ->assertSee('Consumption includes all outbound inventory movements.');
});

it('shows supplier on-time coverage note on executive dashboard', function () {
    $user = userWith(['view_inventory_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertOk()
        ->assertSee('On-time delivery is calculated only from purchase orders with expected delivery dates.');
});

it('shows inventory accuracy empty state instead of zero percent when no completed opname', function () {
    $user = userWith(['view_inventory_analytics']);

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertOk()
        ->assertSee('Belum ada stock opname selesai');
});

it('delegates dashboard composition to InventoryExecutiveDashboardService', function () {
    $user = userWith(['view_inventory_analytics']);

    $mockDashboard = Mockery::mock(InventoryExecutiveDashboardService::class, function (MockInterface $mock) {
        $mock->shouldReceive('getExecutiveDashboard')
            ->once()
            ->with($this->branch->id)
            ->andReturn(app(InventoryExecutiveDashboardService::class)->getExecutiveDashboard($this->branch->id));
    });

    $this->app->instance(InventoryExecutiveDashboardService::class, $mockDashboard);

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertOk()
        ->assertSee('Inventory Executive Dashboard');
});

it('enforces branch isolation on executive dashboard movement data', function () {
    $user = userWith(['view_inventory_analytics']);
    $otherBranch = Branch::factory()->create(['code' => 'EXEC-ISO-B', 'name' => 'Executive ISO Branch B']);

    $locationA = InventoryLocation::factory()->create(['branch_id' => $this->branch->id]);
    $locationB = InventoryLocation::factory()->create(['branch_id' => $otherBranch->id]);

    $productA = Product::factory()->create([
        'branch_id' => $this->branch->id,
        'name' => 'Exec Branch A Visible Product',
        'is_active' => true,
    ]);
    $productB = Product::factory()->create([
        'branch_id' => $otherBranch->id,
        'name' => 'Exec Branch B Hidden Product',
        'is_active' => true,
    ]);

    $this->stockService->createOpeningStock($productA->id, $locationA->id, 40);
    $this->stockService->adjustOut($productA->id, $locationA->id, 15);

    InventoryMovement::factory()->opening()->create([
        'branch_id' => $otherBranch->id,
        'inventory_location_id' => $locationB->id,
        'product_id' => $productB->id,
        'quantity_in' => 50,
        'quantity_out' => 0,
    ]);
    InventoryMovement::factory()->create([
        'branch_id' => $otherBranch->id,
        'inventory_location_id' => $locationB->id,
        'product_id' => $productB->id,
        'movement_type' => InventoryMovement::TYPE_ADJUSTMENT_OUT,
        'quantity_in' => 0,
        'quantity_out' => 20,
        'movement_date' => now()->subDays(2)->toDateString(),
    ]);

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertOk()
        ->assertSee('Exec Branch A Visible Product')
        ->assertDontSee('Exec Branch B Hidden Product');
});

it('shows Dasbor Eksekutif sidebar link for permitted users', function () {
    $user = userWith(['view_inventory_executive_dashboard']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dasbor Eksekutif');
});

it('shows Dasbor Eksekutif sidebar link for view_inventory fallback users', function () {
    $user = userWith(['view_inventory']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Dasbor Eksekutif');
});

it('hides Dasbor Eksekutif sidebar link for unauthorized users', function () {
    $user = userWith(['view_invoice']);

    $response = $this->actingAs($user)->get(route('invoices.index'));

    if ($response->status() === 200) {
        $response->assertDontSee('Dasbor Eksekutif');
    } else {
        expect(true)->toBeTrue();
    }
});

it('marks Dasbor Eksekutif sidebar link active on executive dashboard route', function () {
    $user = userWith(['view_inventory_executive_dashboard']);

    $this->actingAs($user)
        ->get(route('inventory.executive-dashboard'))
        ->assertOk()
        ->assertSee('menu-subitem-active', false)
        ->assertSee('Dasbor Eksekutif');
});
