<?php

/**
 * NSF-7 / FG-1 foundation governance classifications and CI evidence gate metadata.
 * Consumed by FoundationGovernanceSummaryService — does not silence real blockers.
 */
return [
    'sprint' => 'NSF-8',
    'version' => 'NSF-8',

    'evidence_docs' => [
        'fg-1' => 'docs/sprints/fg-1-foundation-watch-burndown-combined-go-closure-evidence.md',
        'dmo-3' => 'docs/sprints/dmo-3-deferred-metric-backlog-closure-evidence.md',
        'nsf-7' => 'docs/sprints/nsf-7-evidence-gate-automation-r011-r012-ci-evidence.md',
        'nsf-8' => 'docs/sprints/nsf-8-node20-observability-raw-go-closure-evidence.md',
        'dq-1' => 'docs/sprints/dq-1-acid-constraint-data-quality-audit-evidence.md',
        'dq-2' => 'docs/sprints/dq-2-batch-tracked-movement-backfill-inventory-batch-governance-evidence.md',
        'dq-3' => 'docs/sprints/dq-3-source-document-batch-linkage-closure-evidence.md',
        'dq-3-1' => 'docs/sprints/dq-3-1-manual-review-repair-ambiguous-batch-rows-evidence.md',
    ],

    'ci_evidence_gates' => [
        'workflow' => '.github/workflows/foundation-evidence-gates.yml',
        'workflow_name' => 'Foundation Evidence Gates',
        'script' => 'scripts/ci/foundation-evidence-gates.sh',
        'artifacts_root' => 'storage/ci-evidence',
        'base_branch' => 'feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report',
        'github_api_required' => false,
        'gates' => [
            'NSF-R011' => [
                'classification' => 'automated_ci_gate',
                'pr_job' => 'critical_test_gate',
                'full_job' => 'full_suite_gate',
                'artifacts' => [
                    'storage/ci-evidence/nsf-r011-critical-tests.txt',
                    'storage/ci-evidence/nsf-r011-full-suite.txt',
                ],
                'local_commands' => [
                    'php artisan test --filter=FoundationGovernance',
                    'bash scripts/ci/foundation-evidence-gates.sh --critical-only',
                ],
            ],
            'NSF-R012' => [
                'classification' => 'automated_ci_gate',
                'pr_job' => 'quality_gate',
                'artifacts' => [
                    'storage/ci-evidence/nsf-r012-build-pint.txt',
                ],
                'local_commands' => [
                    'npm run build',
                    './vendor/bin/pint --test',
                ],
            ],
        ],
    ],

    'rule_classifications' => [
        'NSF-R009' => 'environment',
        'NSF-R011' => 'automated_ci_gate',
        'NSF-R012' => 'automated_ci_gate',
        'DMO-M001' => 'resolved_metric',
        'DMO-M003' => 'resolved_metric',
        'DMO-M006' => 'resolved_metric',
        'DMO-M007' => 'resolved_metric',
    ],

    'resolved_ci_gates' => [
        'NSF-M001' => [
            'closed_in' => 'NSF-7',
            'summary' => 'Full suite and build gates automated via Foundation Evidence Gates workflow',
            'workflow' => '.github/workflows/foundation-evidence-gates.yml',
        ],
        'NSF-M002' => [
            'closed_in' => 'NSF-8',
            'summary' => 'NSF-R009 pg_stat observability validated via --include-observability on VPS deploy; pg_stat_database is sufficient for raw GO',
            'reclassified_as' => 'environment',
        ],
    ],

    'deferred_backlog' => [],

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
        'FG1-CI-001' => 'NSF-R011/R012 automated CI evidence gates are configured',
    ],
];
