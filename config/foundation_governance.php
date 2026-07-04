<?php

/**
 * FG-1 foundation governance classifications and deferred backlog metadata.
 * Consumed by FoundationGovernanceSummaryService — does not silence real blockers.
 */
return [
    'sprint' => 'FG-1',
    'version' => 'FG-1',

    'evidence_docs' => [
        'fg-1' => 'docs/sprints/fg-1-foundation-watch-burndown-combined-go-closure-evidence.md',
        'dq-1' => 'docs/sprints/dq-1-acid-constraint-data-quality-audit-evidence.md',
        'dq-2' => 'docs/sprints/dq-2-batch-tracked-movement-backfill-inventory-batch-governance-evidence.md',
        'dq-3' => 'docs/sprints/dq-3-source-document-batch-linkage-closure-evidence.md',
        'dq-3-1' => 'docs/sprints/dq-3-1-manual-review-repair-ambiguous-batch-rows-evidence.md',
    ],

    'rule_classifications' => [
        'NSF-R009' => 'environment',
        'NSF-R011' => 'evidence_only',
        'NSF-R012' => 'evidence_only',
        'NSF-M001' => 'deferred_backlog',
        'NSF-M002' => 'deferred_backlog',
        'DMO-M001' => 'deferred_backlog',
        'DMO-M003' => 'deferred_backlog',
        'DMO-M006' => 'deferred_backlog',
        'DMO-M007' => 'deferred_backlog',
    ],

    'deferred_backlog' => [
        'NSF-M001' => [
            'owner' => 'Engineering',
            'risk' => 'low',
            'target_sprint' => 'NSF-7',
            'summary' => 'Full suite and build gates require manual CI/sprint evidence',
        ],
        'NSF-M002' => [
            'owner' => 'Platform',
            'risk' => 'low',
            'target_sprint' => 'NSF-7',
            'summary' => 'pg_stat_statements validated on VPS; local SQLite may be not_applicable',
        ],
        'DMO-M001' => [
            'owner' => 'Product/Finance',
            'risk' => 'medium',
            'target_sprint' => 'DMO-3',
            'summary' => 'net_revenue blocked — pilot uses paid_amount as revenue KPI',
        ],
        'DMO-M003' => [
            'owner' => 'Finance',
            'risk' => 'medium',
            'target_sprint' => 'DMO-3',
            'summary' => 'receivable_aging_bucket has no persisted aging table',
        ],
        'DMO-M006' => [
            'owner' => 'Product',
            'risk' => 'medium',
            'target_sprint' => 'DMO-3',
            'summary' => 'Treatment/tariff multi-branch price boundary',
        ],
        'DMO-M007' => [
            'owner' => 'Lab/Delivery',
            'risk' => 'medium',
            'target_sprint' => 'DMO-3',
            'summary' => 'pod_count blocked pending POD field standardization',
        ],
    ],

    'fg1_checks' => [
        'FG1-NSF-001' => 'NSF governance checks enumerate actionable causes',
        'FG1-NSF-002' => 'NSF non-blocking warnings documented',
        'FG1-DMO-001' => 'DMO governance checks enumerate actionable causes',
        'FG1-DMO-002' => 'DMO non-blocking warnings documented',
        'FG1-DQ-001' => 'DQ chain DQ-1/DQ-2/DQ-3/DQ-3.1 is GO',
        'FG1-COMBINED-001' => 'Combined governance decision is explainable',
        'FG1-EVIDENCE-001' => 'Latest sprint evidence documents final state',
    ],
];
