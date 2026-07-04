<?php

/**
 * DQ-2 inventory batch governance registry.
 * Validated by inventory:batch-governance-audit (read-only).
 */
return [
    'version' => 'DQ-2',
    'sprint' => 'DQ-2',

    'legacy_batch_prefix' => 'LEGACY-DQ2',
    'backfill_command' => 'inventory:backfill-missing-batches',
    'audit_command' => 'inventory:batch-governance-audit',

    'checks' => [
        'DQ2-BATCH-001' => [
            'title' => 'Batch governance schema and product flags',
            'severity' => 'error',
        ],
        'DQ2-BATCH-002' => [
            'title' => 'Batch-tracked movements have inventory_batch_id',
            'severity' => 'warning',
        ],
        'DQ2-BATCH-003' => [
            'title' => 'Movement batch matches product',
            'severity' => 'error',
        ],
        'DQ2-BATCH-004' => [
            'title' => 'Movement batch branch scope compatible',
            'severity' => 'error',
        ],
        'DQ2-BATCH-005' => [
            'title' => 'No orphan inventory_batch_id on movements',
            'severity' => 'error',
        ],
        'DQ2-BATCH-006' => [
            'title' => 'Movement quantity direction valid',
            'severity' => 'error',
        ],
        'DQ2-BATCH-007' => [
            'title' => 'Stock transfer batch linkage coherent',
            'severity' => 'warning',
        ],
        'DQ2-BATCH-008' => [
            'title' => 'Goods receipt movements preserve batch identity',
            'severity' => 'warning',
        ],
        'DQ2-BATCH-009' => [
            'title' => 'Stock opname adjustments preserve batch identity',
            'severity' => 'warning',
        ],
        'DQ2-BATCH-010' => [
            'title' => 'DQ-1 DQ1-DATA-006 compatibility',
            'severity' => 'warning',
        ],
    ],

    'source_tables' => [
        'goods_receipt' => 'trx_goods_receipts',
        'goods_receipt_item' => 'trx_goods_receipt_items',
        'stock_transfer' => 'trx_stock_transfers',
        'stock_transfer_item' => 'trx_stock_transfer_items',
        'stock_opname' => 'trx_stock_opnames',
        'stock_opname_item' => 'trx_stock_opname_items',
    ],
];
