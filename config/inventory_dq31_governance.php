<?php

/**
 * DQ-3.1 manual review & approved repair governance registry.
 */
return [
    'version' => 'DQ-3.1',
    'sprint' => 'DQ-3.1',

    'review_command' => 'inventory:ambiguous-batch-review-pack',
    'repair_command' => 'inventory:repair-ambiguous-batch-links',
    'repair_strategy' => 'manual_approved_repair',

    'checks' => [
        'DQ31-REVIEW-001' => [
            'title' => 'Unresolved transfer ambiguous rows enumerated',
        ],
        'DQ31-REVIEW-002' => [
            'title' => 'Unresolved opname ambiguous rows enumerated',
        ],
        'DQ31-REVIEW-003' => [
            'title' => 'Candidate batch evidence available for ambiguous rows',
        ],
        'DQ31-REVIEW-004' => [
            'title' => 'Mapping template path documented',
        ],
        'DQ31-REVIEW-005' => [
            'title' => 'Review pack generation is read-only',
        ],
    ],

    'mapping_template_csv' => 'docs/templates/dq-3-1-ambiguous-batch-repair-mapping-template.csv',
    'mapping_template_json' => 'docs/templates/dq-3-1-ambiguous-batch-repair-mapping-template.json',

    'source_type_tables' => [
        'transfer' => 'trx_stock_transfer_items',
        'opname' => 'trx_stock_opname_items',
    ],
];
