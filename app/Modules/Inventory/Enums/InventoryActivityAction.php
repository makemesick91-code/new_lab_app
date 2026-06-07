<?php

namespace App\Modules\Inventory\Enums;

/**
 * Inventory / procurement activity vocabulary (snake_case values).
 */
final class InventoryActivityAction
{
    public const PURCHASE_REQUEST_CREATED = 'purchase_request_created';

    public const PURCHASE_REQUEST_UPDATED = 'purchase_request_updated';

    public const PURCHASE_REQUEST_SUBMITTED = 'purchase_request_submitted';

    public const PURCHASE_REQUEST_APPROVED = 'purchase_request_approved';

    public const PURCHASE_REQUEST_REJECTED = 'purchase_request_rejected';

    public const PURCHASE_REQUEST_CANCELLED = 'purchase_request_cancelled';

    public const PURCHASE_ORDER_CREATED = 'purchase_order_created';

    public const PURCHASE_ORDER_UPDATED = 'purchase_order_updated';

    public const PURCHASE_ORDER_SUBMITTED = 'purchase_order_submitted';

    public const PURCHASE_ORDER_APPROVED = 'purchase_order_approved';

    public const PURCHASE_ORDER_REJECTED = 'purchase_order_rejected';

    public const PURCHASE_ORDER_CANCELLED = 'purchase_order_cancelled';

    public const GOODS_RECEIPT_CREATED = 'goods_receipt_created';

    public const GOODS_RECEIPT_UPDATED = 'goods_receipt_updated';

    public const GOODS_RECEIPT_COMPLETED = 'goods_receipt_completed';

    public const GOODS_RECEIPT_CANCELLED = 'goods_receipt_cancelled';

    public const STOCK_TRANSFER_CREATED = 'stock_transfer_created';

    public const STOCK_TRANSFER_UPDATED = 'stock_transfer_updated';

    public const STOCK_TRANSFER_SUBMITTED = 'stock_transfer_submitted';

    public const STOCK_TRANSFER_APPROVED = 'stock_transfer_approved';

    public const STOCK_TRANSFER_RECEIVED = 'stock_transfer_received';

    public const STOCK_TRANSFER_CANCELLED = 'stock_transfer_cancelled';

    public const STOCK_OPNAME_CREATED = 'stock_opname_created';

    public const STOCK_OPNAME_UPDATED = 'stock_opname_updated';

    public const STOCK_OPNAME_COMPLETED = 'stock_opname_completed';

    public const STOCK_OPNAME_CANCELLED = 'stock_opname_cancelled';

    public const INVENTORY_MOVEMENT_CREATED = 'inventory_movement_created';

    public const INVENTORY_BATCH_CREATED = 'inventory_batch_created';

    public const INVENTORY_BATCH_RECEIVED = 'inventory_batch_received';

    public const INVENTORY_BATCH_UPDATED = 'inventory_batch_updated';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::PURCHASE_REQUEST_CREATED,
            self::PURCHASE_REQUEST_UPDATED,
            self::PURCHASE_REQUEST_SUBMITTED,
            self::PURCHASE_REQUEST_APPROVED,
            self::PURCHASE_REQUEST_REJECTED,
            self::PURCHASE_REQUEST_CANCELLED,
            self::PURCHASE_ORDER_CREATED,
            self::PURCHASE_ORDER_UPDATED,
            self::PURCHASE_ORDER_SUBMITTED,
            self::PURCHASE_ORDER_APPROVED,
            self::PURCHASE_ORDER_REJECTED,
            self::PURCHASE_ORDER_CANCELLED,
            self::GOODS_RECEIPT_CREATED,
            self::GOODS_RECEIPT_UPDATED,
            self::GOODS_RECEIPT_COMPLETED,
            self::GOODS_RECEIPT_CANCELLED,
            self::STOCK_TRANSFER_CREATED,
            self::STOCK_TRANSFER_UPDATED,
            self::STOCK_TRANSFER_SUBMITTED,
            self::STOCK_TRANSFER_APPROVED,
            self::STOCK_TRANSFER_RECEIVED,
            self::STOCK_TRANSFER_CANCELLED,
            self::STOCK_OPNAME_CREATED,
            self::STOCK_OPNAME_UPDATED,
            self::STOCK_OPNAME_COMPLETED,
            self::STOCK_OPNAME_CANCELLED,
            self::INVENTORY_MOVEMENT_CREATED,
            self::INVENTORY_BATCH_CREATED,
            self::INVENTORY_BATCH_RECEIVED,
            self::INVENTORY_BATCH_UPDATED,
        ];
    }

    public static function isValid(string $action): bool
    {
        return in_array($action, self::all(), true);
    }
}
