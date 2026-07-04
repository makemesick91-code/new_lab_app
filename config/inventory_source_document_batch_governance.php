<?php

/**
 * DQ-3 source-document batch linkage governance registry.
 * Validated by inventory:source-document-batch-audit (read-only).
 */
return [
    'version' => 'DQ-3',
    'sprint' => 'DQ-3',

    'audit_command' => 'inventory:source-document-batch-audit',
    'backfill_command' => 'inventory:backfill-source-document-batches',

    'checks' => [
        'DQ3-SRC-001' => [
            'title' => 'Goods receipt items for batch-tracked products have inventory_batch_id',
            'severity' => 'warning',
        ],
        'DQ3-SRC-002' => [
            'title' => 'Stock transfer items for batch-tracked products have inventory_batch_id',
            'severity' => 'warning',
        ],
        'DQ3-SRC-003' => [
            'title' => 'Stock opname items for batch-tracked products have inventory_batch_id',
            'severity' => 'warning',
        ],
        'DQ3-SRC-004' => [
            'title' => 'Source item batch belongs to the same product',
            'severity' => 'error',
        ],
        'DQ3-SRC-005' => [
            'title' => 'Source item batch scope is compatible with branch',
            'severity' => 'error',
        ],
        'DQ3-SRC-006' => [
            'title' => 'Source item maps to movement batch consistently',
            'severity' => 'warning',
        ],
        'DQ3-SRC-007' => [
            'title' => 'Transfer outbound/inbound source item batch lineage is coherent',
            'severity' => 'warning',
        ],
        'DQ3-SRC-008' => [
            'title' => 'Source-document rows do not point to orphan inventory_batch_id',
            'severity' => 'error',
        ],
        'DQ3-SRC-009' => [
            'title' => 'DQ-2 compatibility check for BATCH-007/008/009',
            'severity' => 'warning',
        ],
        'DQ3-SRC-010' => [
            'title' => 'Source-document batch guard service is registered',
            'severity' => 'error',
        ],
    ],

    'source_tables' => [
        'goods_receipt_item' => 'trx_goods_receipt_items',
        'stock_transfer_item' => 'trx_stock_transfer_items',
        'stock_opname_item' => 'trx_stock_opname_items',
    ],
];
