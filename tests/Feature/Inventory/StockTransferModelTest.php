<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Models\InventoryLocation;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferItem;
use Illuminate\Support\Carbon;

it('relates a stock transfer to its branch locations and users', function () {
    $transfer = StockTransfer::factory()->completed()->create();

    expect($transfer->branch)->toBeInstanceOf(Branch::class)
        ->and($transfer->sourceInventoryLocation)->toBeInstanceOf(InventoryLocation::class)
        ->and($transfer->destinationInventoryLocation)->toBeInstanceOf(InventoryLocation::class)
        ->and($transfer->requestedBy)->toBeInstanceOf(User::class)
        ->and($transfer->approvedBy)->toBeInstanceOf(User::class)
        ->and($transfer->createdBy)->toBeInstanceOf(User::class);
});

it('relates a stock transfer to its items', function () {
    $transfer = StockTransfer::factory()
        ->has(StockTransferItem::factory()->count(2), 'items')
        ->create();

    expect($transfer->items)->toHaveCount(2)
        ->and($transfer->items->first())->toBeInstanceOf(StockTransferItem::class)
        ->and($transfer->items->first()->stockTransfer->id)->toBe($transfer->id);
});

it('relates a stock transfer item to its transfer and product', function () {
    $item = StockTransferItem::factory()->create();

    expect($item->stockTransfer)->toBeInstanceOf(StockTransfer::class)
        ->and($item->product)->toBeInstanceOf(Product::class);
});

it('keeps transfer locations and item products in the same branch as the transfer', function () {
    $item = StockTransferItem::factory()->create();
    $transfer = $item->stockTransfer;

    expect($transfer->sourceInventoryLocation->branch_id)->toBe($transfer->branch_id)
        ->and($transfer->destinationInventoryLocation->branch_id)->toBe($transfer->branch_id)
        ->and($item->product->branch_id)->toBe($transfer->branch_id);
});

it('casts transfer dates and item quantity decimals', function () {
    $transfer = StockTransfer::factory()->completed()->create();
    $item = StockTransferItem::factory()->create(['quantity' => 5]);

    expect($transfer->transfer_date)->toBeInstanceOf(Carbon::class)
        ->and($transfer->completed_at)->toBeInstanceOf(Carbon::class)
        ->and($item->quantity)->toBe('5.00');
});

it('defines lowercase transfer statuses requested by Sprint 14.2', function () {
    expect(StockTransfer::STATUS_DRAFT)->toBe('draft')
        ->and(StockTransfer::STATUS_SUBMITTED)->toBe('submitted')
        ->and(StockTransfer::STATUS_COMPLETED)->toBe('completed')
        ->and(StockTransfer::STATUS_CANCELLED)->toBe('cancelled')
        ->and(StockTransfer::STATUSES)->toBe([
            'draft',
            'submitted',
            'completed',
            'cancelled',
        ]);
});

it('defaults a new transfer to draft and supports status factory states', function () {
    expect(StockTransfer::factory()->create()->status)->toBe(StockTransfer::STATUS_DRAFT)
        ->and(StockTransfer::factory()->submitted()->create()->status)->toBe(StockTransfer::STATUS_SUBMITTED)
        ->and(StockTransfer::factory()->completed()->create()->status)->toBe(StockTransfer::STATUS_COMPLETED)
        ->and(StockTransfer::factory()->cancelled()->create()->status)->toBe(StockTransfer::STATUS_CANCELLED);
});

it('mass-assigns all stock transfer fillable attributes', function () {
    $branch = Branch::factory()->create();
    $source = InventoryLocation::factory()->create(['branch_id' => $branch->id]);
    $destination = InventoryLocation::factory()->create(['branch_id' => $branch->id]);
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $creator = User::factory()->create();

    $transfer = StockTransfer::create([
        'branch_id' => $branch->id,
        'transfer_number' => 'TRF-TEST-0001',
        'source_inventory_location_id' => $source->id,
        'destination_inventory_location_id' => $destination->id,
        'transfer_date' => now()->toDateString(),
        'status' => StockTransfer::STATUS_SUBMITTED,
        'notes' => 'Move material between branch locations',
        'requested_by' => $requester->id,
        'approved_by' => $approver->id,
        'completed_at' => now(),
        'created_by' => $creator->id,
    ]);

    expect($transfer->transfer_number)->toBe('TRF-TEST-0001')
        ->and($transfer->branch_id)->toBe($branch->id)
        ->and($transfer->source_inventory_location_id)->toBe($source->id)
        ->and($transfer->destination_inventory_location_id)->toBe($destination->id)
        ->and($transfer->requested_by)->toBe($requester->id)
        ->and($transfer->approved_by)->toBe($approver->id)
        ->and($transfer->created_by)->toBe($creator->id);
});

it('mass-assigns all stock transfer item fillable attributes', function () {
    $transfer = StockTransfer::factory()->create();
    $product = Product::factory()->create(['branch_id' => $transfer->branch_id]);

    $item = StockTransferItem::create([
        'stock_transfer_id' => $transfer->id,
        'product_id' => $product->id,
        'quantity' => 12.5,
        'notes' => 'Transfer enough for production',
    ]);

    expect($item->stock_transfer_id)->toBe($transfer->id)
        ->and($item->product_id)->toBe($product->id)
        ->and($item->quantity)->toBe('12.50')
        ->and($item->notes)->toBe('Transfer enough for production');
});
