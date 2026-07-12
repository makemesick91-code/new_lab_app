<?php

/**
 * LAB-PROD-2 — Operational Analytics & KPI (canonical metric registry).
 *
 * Single source of truth for the Lab Workflow V2 operational KPI contract:
 * every metric's canonical data source, formula, denominator, exclusion rule,
 * branch/permission scope, and support status. The analytics service, the
 * audit command, and the GO/NO-GO command all read from here so a metric can
 * never drift between the dashboard, the export, and the governance gate.
 *
 * Rules locked here (see docs/architecture/lab-operational-analytics-kpi-contract.md):
 *  - KPIs are derived ONLY from canonical runtime data (workflow_version = V2):
 *    trx_lab_orders, the append-only trx_lab_order_status_logs timeline
 *    (changed_at only, never updated_at), trx_lab_order_assignments,
 *    trx_lab_external_dispatches, trx_lab_delivery_tasks, trx_lab_model_analyses.
 *  - The ONLY SLA deadline is trx_lab_orders.due_date (nullable). Orders without
 *    a due_date are EXCLUDED from SLA compliance and surfaced in data-quality —
 *    never silently counted as on-time or as zero.
 *  - Missing/incomplete data is reported as EXCLUDED / WATCH, never as a fake 0.
 *  - No fabricated, dummy, or hard-coded metric values.
 *  - No PII (KTP/NIK/WhatsApp/clinical notes/scans/prescriptions) in any KPI,
 *    drill-down, or export.
 *  - Historical workflow state/logs are immutable; analytics never writes them.
 */
return [

    // Feature flag — lets the surface be disabled without removing the route.
    'enabled' => env('LAB_OPERATIONAL_ANALYTICS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Permissions (authorization tiers)
    |--------------------------------------------------------------------------
    | full  — management/owner tier: all RME branches, branch filter honoured,
    |         every technician. Held by Admin Lab, Owner, Supervisor RME
    |         (Super Admin passes via Gate::before).
    | own   — technician self-scope: forced to the caller's own linked
    |         mst_technicians row; a crafted technician_id is ignored (IDOR-safe).
    | manage— existing broad lab management permission, also grants the full tier.
    */
    'permissions' => [
        'full' => 'view_lab_operational_analytics',
        'own' => 'view_own_lab_operational_analytics',
        'manage' => 'manage_lab_orders',
    ],

    /*
    |--------------------------------------------------------------------------
    | Period presets + custom-range guard
    |--------------------------------------------------------------------------
    | Previous-period comparison always uses the SAME duration as the selected
    | period (never a hard-coded window).
    */
    'periods' => ['today', '7d', 'month', '30d', 'custom'],
    'default_period' => 'month',
    'max_custom_range_days' => 366,

    // Hard cap on rows scanned by any single bounded analytics query.
    'max_scan_orders' => 5000,

    // A minimum valid sample size before a percentile (P90) is shown.
    'percentile_min_sample' => 8,

    // Active (non-terminal) order idle longer than this many days = "stuck".
    // Mirrors the LAB-WORKFLOW-V2 operational dashboard / SLA baseline threshold.
    'stuck_idle_days' => 3,

    /*
    |--------------------------------------------------------------------------
    | Canonical KPI registry
    |--------------------------------------------------------------------------
    | key => [label, group, source, formula, denominator, exclusions, support]
    | support: 'supported' (core, GO-gated) | 'watch' (optional, no canonical
    |          source → never blocks the sprint acceptance gate).
    */
    'metrics' => [

        // ---- Workload / WIP ------------------------------------------------
        'orders_received' => [
            'label' => 'Order Masuk (periode)',
            'group' => 'workload',
            'source' => 'trx_lab_orders',
            'formula' => 'count(V2 orders with order_date within the period)',
            'denominator' => null,
            'exclusions' => 'soft-deleted orders',
            'support' => 'supported',
        ],
        'open_wip' => [
            'label' => 'WIP Terbuka',
            'group' => 'workload',
            'source' => 'trx_lab_orders',
            'formula' => 'count(V2 orders whose current status is NOT terminal)',
            'denominator' => null,
            'exclusions' => 'terminal orders (DELIVERED, CANCELLED)',
            'support' => 'supported',
        ],
        'wip_by_stage' => [
            'label' => 'WIP per Tahap',
            'group' => 'workload',
            'source' => 'trx_lab_orders',
            'formula' => 'count(V2 orders) grouped by current workflow status',
            'denominator' => null,
            'exclusions' => 'none (all current statuses shown)',
            'support' => 'supported',
        ],
        'rework_active' => [
            'label' => 'Rework Aktif',
            'group' => 'workload',
            'source' => 'trx_lab_orders',
            'formula' => 'count(V2 orders currently in QC_FAILED or REWORK_REQUIRED)',
            'denominator' => null,
            'exclusions' => 'none',
            'support' => 'supported',
        ],
        'open_overdue' => [
            'label' => 'Overdue Terbuka',
            'group' => 'workload',
            'source' => 'trx_lab_orders.due_date',
            'formula' => 'count(non-terminal V2 orders with due_date < today)',
            'denominator' => null,
            'exclusions' => 'orders without a due_date; terminal orders',
            'support' => 'supported',
        ],

        // ---- Throughput ----------------------------------------------------
        'throughput' => [
            'label' => 'Throughput Selesai',
            'group' => 'throughput',
            'source' => 'trx_lab_order_status_logs (new_status=DELIVERED)',
            'formula' => 'count(first DELIVERED transition per order, changed_at within period)',
            'denominator' => null,
            'exclusions' => 'orders never delivered',
            'support' => 'supported',
        ],
        'throughput_prev_delta' => [
            'label' => 'Tren vs Periode Sebelumnya',
            'group' => 'throughput',
            'source' => 'trx_lab_order_status_logs',
            'formula' => 'throughput(period) - throughput(previous equal-length period)',
            'denominator' => null,
            'exclusions' => 'same as throughput',
            'support' => 'supported',
        ],

        // ---- Cycle time (reuses LabWorkflowSlaBaselineService stages) -------
        'cycle_time' => [
            'label' => 'Cycle Time / Turnaround',
            'group' => 'cycle_time',
            'source' => 'trx_lab_order_status_logs timeline',
            'formula' => 'median + P90 minutes per canonical stage (first from-state → first to-state)',
            'denominator' => 'orders with both stage timestamps present',
            'exclusions' => 'missing/negative stage timestamps',
            'support' => 'supported',
        ],

        // ---- SLA -----------------------------------------------------------
        'sla_compliance' => [
            'label' => 'Kepatuhan SLA',
            'group' => 'sla',
            'source' => 'trx_lab_orders.due_date + first DELIVERED status log',
            'formula' => 'on_time_completed / all_completed_eligible_SLA_cases * 100',
            'denominator' => 'completed (delivered in period) orders that HAD a due_date',
            'exclusions' => 'orders without due_date; cancelled orders',
            'support' => 'supported',
        ],
        'sla_lateness' => [
            'label' => 'Rata-rata/Median Keterlambatan',
            'group' => 'sla',
            'source' => 'delivered date - due_date',
            'formula' => 'median lateness (days) across LATE completed eligible cases',
            'denominator' => 'late completed eligible cases',
            'exclusions' => 'on-time cases; orders without due_date',
            'support' => 'supported',
        ],

        // ---- QC / quality --------------------------------------------------
        'qc_first_pass_yield' => [
            'label' => 'First-Pass Yield QC',
            'group' => 'quality',
            'source' => 'trx_lab_order_status_logs (QC_PASSED/QC_FAILED)',
            'formula' => 'orders whose FIRST QC attempt was QC_PASSED / orders with a first QC attempt',
            'denominator' => 'orders with at least one QC attempt in period',
            'exclusions' => 'orders never sent to QC',
            'support' => 'supported',
        ],
        'qc_rework_rate' => [
            'label' => 'Tingkat Rework QC',
            'group' => 'quality',
            'source' => 'trx_lab_order_status_logs (QC_FAILED)',
            'formula' => 'orders with >=1 QC_FAILED / orders with a QC attempt',
            'denominator' => 'orders with at least one QC attempt in period',
            'exclusions' => 'orders never sent to QC',
            'support' => 'supported',
        ],

        // ---- Technician operational ---------------------------------------
        'technician_operational' => [
            'label' => 'KPI Operasional Teknisi',
            'group' => 'technician',
            'source' => 'trx_lab_order_assignments',
            'formula' => 'per technician: active WIP, assigned, completed, median completion minutes, sample size',
            'denominator' => 'assignments per technician',
            'exclusions' => 'assignments without both start+complete timestamps (for cycle only)',
            'support' => 'supported',
        ],

        // ---- Internal vs External -----------------------------------------
        'internal_vs_external' => [
            'label' => 'Internal vs Lab Eksternal',
            'group' => 'sourcing',
            'source' => 'trx_lab_model_analyses.decision + trx_lab_external_dispatches',
            'formula' => 'count(orders routed INTERNAL) vs count(orders routed EXTERNAL) in period',
            'denominator' => 'orders with a recorded analysis decision in period',
            'exclusions' => 'orders without an analysis decision',
            'support' => 'supported',
        ],
        'external_turnaround' => [
            'label' => 'Turnaround Lab Eksternal',
            'group' => 'sourcing',
            'source' => 'trx_lab_external_dispatches (sent_at, returned_at)',
            'formula' => 'median days between sent_at and returned_at',
            'denominator' => 'dispatches with both sent_at and returned_at',
            'exclusions' => 'dispatches missing sent_at or returned_at',
            'support' => 'supported',
        ],

        // ---- Data quality coverage ----------------------------------------
        'data_quality' => [
            'label' => 'Cakupan Kualitas Data',
            'group' => 'data_quality',
            'source' => 'trx_lab_orders + trx_lab_order_status_logs',
            'formula' => 'eligible vs complete-timestamp vs excluded record counts + reasons',
            'denominator' => 'all V2 orders in period',
            'exclusions' => 'reported explicitly (never hidden as 0)',
            'support' => 'supported',
        ],
    ],
];
