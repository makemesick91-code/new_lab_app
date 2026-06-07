<?php

use App\Modules\Inventory\DTOs\InventoryExecutiveSnapshot;

it('maps snake_case array keys to camelCase readonly properties via fromArray', function () {
    $snapshot = InventoryExecutiveSnapshot::fromArray([
        'inventory_value' => 1250000.50,
        'active_sku' => 42,
        'dead_stock_count' => 3,
        'low_stock_count' => 7,
        'open_pr' => 2,
        'open_po' => 5,
        'pending_gr' => 1,
        'in_transit_transfer' => 4,
        'inventory_accuracy' => 97.5,
    ]);

    expect($snapshot->inventoryValue)->toBe(1250000.50)
        ->and($snapshot->activeSku)->toBe(42)
        ->and($snapshot->deadStockCount)->toBe(3)
        ->and($snapshot->lowStockCount)->toBe(7)
        ->and($snapshot->openPr)->toBe(2)
        ->and($snapshot->openPo)->toBe(5)
        ->and($snapshot->pendingGr)->toBe(1)
        ->and($snapshot->inTransitTransfer)->toBe(4)
        ->and($snapshot->inventoryAccuracy)->toBe(97.5);
});

it('applies safe defaults when optional keys are missing', function () {
    $snapshot = InventoryExecutiveSnapshot::fromArray([]);

    expect($snapshot->inventoryValue)->toBe(0.0)
        ->and($snapshot->activeSku)->toBe(0)
        ->and($snapshot->deadStockCount)->toBe(0)
        ->and($snapshot->lowStockCount)->toBe(0)
        ->and($snapshot->openPr)->toBe(0)
        ->and($snapshot->openPo)->toBe(0)
        ->and($snapshot->pendingGr)->toBe(0)
        ->and($snapshot->inTransitTransfer)->toBe(0)
        ->and($snapshot->inventoryAccuracy)->toBeNull();
});

it('allows inventoryAccuracy to be null when no completed stock opname exists', function () {
    $snapshot = InventoryExecutiveSnapshot::fromArray([
        'inventory_value' => 500000,
        'inventory_accuracy' => null,
    ]);

    expect($snapshot->inventoryAccuracy)->toBeNull();
});

it('returns snake_case keys from toArray', function () {
    $snapshot = InventoryExecutiveSnapshot::fromArray([
        'inventory_value' => 800000,
        'active_sku' => 10,
        'dead_stock_count' => 1,
        'low_stock_count' => 2,
        'open_pr' => 3,
        'open_po' => 4,
        'pending_gr' => 5,
        'in_transit_transfer' => 6,
        'inventory_accuracy' => 88.0,
    ]);

    expect($snapshot->toArray())->toBe([
        'inventory_value' => 800000.0,
        'active_sku' => 10,
        'dead_stock_count' => 1,
        'low_stock_count' => 2,
        'open_pr' => 3,
        'open_po' => 4,
        'pending_gr' => 5,
        'in_transit_transfer' => 6,
        'inventory_accuracy' => 88.0,
    ]);
});

it('returns nine cards from toCards', function () {
    $cards = InventoryExecutiveSnapshot::fromArray([
        'inventory_value' => 1000,
        'active_sku' => 1,
        'dead_stock_count' => 2,
        'low_stock_count' => 3,
        'open_pr' => 4,
        'open_po' => 5,
        'pending_gr' => 6,
        'in_transit_transfer' => 7,
        'inventory_accuracy' => 90.0,
    ])->toCards();

    expect($cards)->toHaveCount(9)
        ->and(collect($cards)->pluck('key')->all())->toBe([
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

it('includes operational valuation note on inventory value card', function () {
    $card = collect(InventoryExecutiveSnapshot::fromArray([
        'inventory_value' => 250000,
    ])->toCards())->firstWhere('key', 'inventory_value');

    expect($card)->not->toBeNull()
        ->and($card['label'])->toBe('Inventory Value')
        ->and($card['type'])->toBe('currency')
        ->and($card['note'])->toBe('Operational valuation');
});

it('marks inventory accuracy card as percentage type', function () {
    $card = collect(InventoryExecutiveSnapshot::fromArray([
        'inventory_accuracy' => 95.5,
    ])->toCards())->firstWhere('key', 'inventory_accuracy');

    expect($card)->not->toBeNull()
        ->and($card['type'])->toBe('percentage')
        ->and($card['value'])->toBe(95.5);
});

it('keeps inventory accuracy card value null when accuracy is unavailable', function () {
    $card = collect(InventoryExecutiveSnapshot::fromArray([])->toCards())
        ->firstWhere('key', 'inventory_accuracy');

    expect($card['value'])->toBeNull()
        ->and($card['type'])->toBe('percentage');
});

it('falls back to safe defaults for non-numeric string values', function () {
    $snapshot = InventoryExecutiveSnapshot::fromArray([
        'inventory_value' => 'not-a-number',
        'active_sku' => 'invalid',
        'inventory_accuracy' => 'n/a',
    ]);

    expect($snapshot->inventoryValue)->toBe(0.0)
        ->and($snapshot->activeSku)->toBe(0)
        ->and($snapshot->inventoryAccuracy)->toBeNull();
});
