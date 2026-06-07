<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\Inventory\DTOs\InventoryExecutiveSnapshot;
use App\Modules\Inventory\Services\InventoryAnalyticsService;
use App\Modules\Inventory\Services\InventoryExecutiveDashboardService;
use Database\Seeders\BranchSeeder;
use Mockery\MockInterface;

beforeEach(function () {
    test()->seed(BranchSeeder::class);

    $this->branch = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();
    $this->dashboard = app(InventoryExecutiveDashboardService::class);
});

it('resolves inventory executive dashboard service from the container', function () {
    expect(app(InventoryExecutiveDashboardService::class))->toBeInstanceOf(InventoryExecutiveDashboardService::class);
});

it('returns InventoryExecutiveSnapshot from getExecutiveSnapshot', function () {
    $snapshot = $this->dashboard->getExecutiveSnapshot($this->branch->id);

    expect($snapshot)->toBeInstanceOf(InventoryExecutiveSnapshot::class)
        ->and($snapshot->toArray())->toHaveKeys([
            'inventory_value',
            'active_sku',
            'dead_stock_count',
            'low_stock_count',
            'open_pr',
            'open_po',
            'pending_gr',
            'in_transit_transfer',
            'inventory_accuracy',
        ]);
});

it('returns nine enriched dashboard cards', function () {
    $cards = $this->dashboard->getDashboardCards($this->branch->id);

    expect($cards)->toHaveCount(9)
        ->and($cards[0])->toHaveKeys(['key', 'label', 'value', 'type', 'display_value', 'tone', 'href', 'empty_state']);
});

it('shows accuracy fallback display when inventory accuracy is null', function () {
    $mockAnalytics = Mockery::mock(InventoryAnalyticsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('getKpiSummary')->once()->with(77)->andReturn([
            'inventory_value' => 0,
            'active_sku' => 0,
            'dead_stock_count' => 0,
            'low_stock_count' => 0,
            'open_pr' => 0,
            'open_po' => 0,
            'pending_gr' => 0,
            'in_transit_transfer' => 0,
            'inventory_accuracy' => null,
        ]);
    });

    $service = new InventoryExecutiveDashboardService($mockAnalytics, app(BranchContext::class));

    $accuracyCard = collect($service->getDashboardCards(77))->firstWhere('key', 'inventory_accuracy');

    expect($accuracyCard['display_value'])->toBe('Belum ada stock opname selesai')
        ->and($accuracyCard['tone'])->toBe('neutral')
        ->and($accuracyCard['empty_state'])->toBe('Belum ada stock opname selesai');
});

it('returns full executive dashboard payload with snapshot cards sections and meta', function () {
    $payload = $this->dashboard->getExecutiveDashboard($this->branch->id);

    expect($payload)->toHaveKeys(['snapshot', 'cards', 'sections', 'meta'])
        ->and($payload['snapshot'])->toBeInstanceOf(InventoryExecutiveSnapshot::class)
        ->and($payload['cards'])->toHaveCount(9)
        ->and($payload['meta']['branch_id'])->toBe($this->branch->id)
        ->and($payload['meta']['generated_at'])->not->toBeNull();
});

it('returns ordered dashboard sections for trends movement valuation supplier and reorder', function () {
    $sections = $this->dashboard->getDashboardSections($this->branch->id);

    expect($sections)->toHaveKeys(['trends', 'movement', 'valuation', 'supplier', 'reorder'])
        ->and($sections['trends'])->toHaveKeys(['purchase_trend', 'consumption_trend'])
        ->and($sections['movement'])->toHaveKeys(['fast_moving', 'slow_moving', 'dead_stock'])
        ->and($sections['valuation'])->toHaveKeys(['stock_aging'])
        ->and($sections['supplier'])->toHaveKeys(['supplier_performance'])
        ->and($sections['reorder'])->toHaveKeys(['reorder_recommendations']);
});

it('includes valuation accuracy and consumption notes in dashboard meta', function () {
    $meta = $this->dashboard->getExecutiveDashboard($this->branch->id)['meta'];

    expect($meta)->toHaveKeys(['valuation_note', 'accuracy_note', 'consumption_note'])
        ->and($meta['valuation_note'])->toBe('Operational inventory value, not accounting valuation.')
        ->and($meta['accuracy_note'])->toBe('Inventory accuracy is null when no completed stock opname exists.')
        ->and($meta['consumption_note'])->toBe('Consumption includes all outbound inventory movements.');
});

it('includes operational valuation note on inventory value card', function () {
    $card = collect($this->dashboard->getDashboardCards($this->branch->id))
        ->firstWhere('key', 'inventory_value');

    expect($card['note'])->toBe('Operational valuation');
});

it('delegates to analytics service without direct repository access', function () {
    $kpiSummary = [
        'inventory_value' => 5000,
        'active_sku' => 3,
        'dead_stock_count' => 1,
        'low_stock_count' => 2,
        'open_pr' => 1,
        'open_po' => 2,
        'pending_gr' => 1,
        'in_transit_transfer' => 1,
        'inventory_accuracy' => 92.5,
    ];

    $mockAnalytics = Mockery::mock(InventoryAnalyticsService::class, function (MockInterface $mock) use ($kpiSummary) {
        $mock->shouldReceive('getKpiSummary')->once()->with(55)->andReturn($kpiSummary);
        $mock->shouldReceive('getPurchaseTrend')->once()->with(55)->andReturn([]);
        $mock->shouldReceive('getConsumptionTrend')->once()->with(55)->andReturn([]);
        $mock->shouldReceive('getFastMovingItems')->once()->with(55, 90, 5)->andReturn(collect());
        $mock->shouldReceive('getSlowMovingItems')->once()->with(55, 90, 5)->andReturn(collect());
        $mock->shouldReceive('getDeadStockItems')->once()->with(55, 90, 5)->andReturn(collect());
        $mock->shouldReceive('getStockAging')->once()->with(55)->andReturn(['granularity' => 'product', 'buckets' => [], 'items' => collect()]);
        $mock->shouldReceive('getSupplierPerformance')->once()->with(55)->andReturn(collect());
        $mock->shouldReceive('getReorderRecommendations')->once()->with(55)->andReturn(collect());
    });

    $service = new InventoryExecutiveDashboardService($mockAnalytics, app(BranchContext::class));
    $payload = $service->getExecutiveDashboard(55);

    expect($payload['snapshot']->inventoryValue)->toBe(5000.0)
        ->and($payload['snapshot']->inventoryAccuracy)->toBe(92.5)
        ->and(collect($payload['cards'])->firstWhere('key', 'inventory_accuracy')['tone'])->toBe('success');
});

it('applies warning tone when low stock or dead stock counts are positive', function () {
    $mockAnalytics = Mockery::mock(InventoryAnalyticsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('getKpiSummary')->once()->andReturn([
            'inventory_value' => 0,
            'active_sku' => 0,
            'dead_stock_count' => 2,
            'low_stock_count' => 4,
            'open_pr' => 0,
            'open_po' => 0,
            'pending_gr' => 0,
            'in_transit_transfer' => 0,
            'inventory_accuracy' => 85.0,
        ]);
    });

    $service = new InventoryExecutiveDashboardService($mockAnalytics, app(BranchContext::class));
    $cards = collect($service->getDashboardCards(1));

    expect($cards->firstWhere('key', 'low_stock_count')['tone'])->toBe('warning')
        ->and($cards->firstWhere('key', 'dead_stock_count')['tone'])->toBe('warning')
        ->and($cards->firstWhere('key', 'inventory_accuracy')['tone'])->toBe('warning');
});

it('applies info tone when pending gr or in transit transfer counts are positive', function () {
    $mockAnalytics = Mockery::mock(InventoryAnalyticsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('getKpiSummary')->once()->andReturn([
            'inventory_value' => 0,
            'active_sku' => 0,
            'dead_stock_count' => 0,
            'low_stock_count' => 0,
            'open_pr' => 0,
            'open_po' => 0,
            'pending_gr' => 3,
            'in_transit_transfer' => 2,
            'inventory_accuracy' => null,
        ]);
    });

    $service = new InventoryExecutiveDashboardService($mockAnalytics, app(BranchContext::class));
    $cards = collect($service->getDashboardCards(1));

    expect($cards->firstWhere('key', 'pending_gr')['tone'])->toBe('info')
        ->and($cards->firstWhere('key', 'in_transit_transfer')['tone'])->toBe('info');
});

it('uses BranchContext requireId for getExecutiveDashboardForCurrentBranch', function () {
    $branchContext = Mockery::mock(BranchContext::class, function (MockInterface $mock) {
        $mock->shouldReceive('requireId')->once()->andReturn(88);
    });

    $mockAnalytics = Mockery::mock(InventoryAnalyticsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('getKpiSummary')->once()->with(88)->andReturn([
            'inventory_value' => 0,
            'active_sku' => 0,
            'dead_stock_count' => 0,
            'low_stock_count' => 0,
            'open_pr' => 0,
            'open_po' => 0,
            'pending_gr' => 0,
            'in_transit_transfer' => 0,
            'inventory_accuracy' => null,
        ]);
        $mock->shouldReceive('getPurchaseTrend')->once()->with(88)->andReturn([]);
        $mock->shouldReceive('getConsumptionTrend')->once()->with(88)->andReturn([]);
        $mock->shouldReceive('getFastMovingItems')->once()->with(88, 90, 5)->andReturn(collect());
        $mock->shouldReceive('getSlowMovingItems')->once()->with(88, 90, 5)->andReturn(collect());
        $mock->shouldReceive('getDeadStockItems')->once()->with(88, 90, 5)->andReturn(collect());
        $mock->shouldReceive('getStockAging')->once()->with(88)->andReturn(['granularity' => 'product', 'buckets' => [], 'items' => collect()]);
        $mock->shouldReceive('getSupplierPerformance')->once()->with(88)->andReturn(collect());
        $mock->shouldReceive('getReorderRecommendations')->once()->with(88)->andReturn(collect());
    });

    $service = new InventoryExecutiveDashboardService($mockAnalytics, $branchContext);
    $payload = $service->getExecutiveDashboardForCurrentBranch();

    expect($payload['meta']['branch_id'])->toBe(88);
});
