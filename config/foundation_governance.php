<?php

/**
 * FG-1 / DMO-3 foundation governance classifications and deferred backlog metadata.
 * Consumed by FoundationGovernanceSummaryService — does not silence real blockers.
 */
return [
    'sprint' => 'DMO-3',
    'version' => 'DMO-3',

    'evidence_docs' => [
        'fg-1' => 'docs/sprints/fg-1-foundation-watch-burndown-combined-go-closure-evidence.md',
        'dmo-3' => 'docs/sprints/dmo-3-deferred-metric-backlog-closure-evidence.md',
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
        'DMO-M001' => 'resolved_metric',
        'DMO-M003' => 'resolved_metric',
        'DMO-M006' => 'resolved_metric',
        'DMO-M007' => 'resolved_metric',
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
    ],

    'resolved_metrics' => [
        'DMO-M001' => [
            'source' => 'DmoMetricService::netRevenue',
            'summary' => 'net_revenue = collected RME + Lab payments excluding VOID; no receivable remaining counted',
        ],
        'DMO-M003' => [
            'source' => 'DmoMetricService::receivableAgingBuckets',
            'summary' => 'Invoice-remaining aging buckets computed at read time',
        ],
        'DMO-M006' => [
            'source' => 'TariffBoundaryService::resolveActiveTariff',
            'summary' => 'Branch-specific tariff lookup without cross-branch fallback',
        ],
        'DMO-M007' => [
            'source' => 'DmoMetricService::podCount',
            'summary' => 'POD count from confirmed delivery signature proof on trx_lab_deliveries',
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
