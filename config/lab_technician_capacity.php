<?php

/*
|--------------------------------------------------------------------------
| LAB-PROD-3 — Technician Capacity Planning
|--------------------------------------------------------------------------
|
| Read-only decision-support configuration for lab technician capacity
| planning. Kept SEPARATE from config/lab_operational_analytics.php so the
| LAB-PROD-2 KPI audit contract stays unchanged. Capacity planning REUSES the
| LAB-PROD-2 analytics service + SLA baseline for completion/cycle context;
| the values here only parameterise the capacity-specific engine.
|
| Rules locked here (never fabricated in the service):
|   - planning_unit is explicit ('minutes' | 'units')
|   - utilization thresholds are planning policy, NOT employee performance
|   - missing profiles => UNCONFIGURED / UNPLANNABLE, never a fake zero
|   - capacity can never be negative
|
*/

return [
    // Master feature switch. Disable safely without breaking Lab workflow.
    'enabled' => (bool) env('LAB_TECHNICIAN_CAPACITY_PLANNING_ENABLED', true),

    // Planning horizon (days). custom range is validated + capped.
    'horizons' => [7, 14, 30],
    'default_horizon' => 14,
    'max_horizon_days' => (int) env('LAB_TECHNICIAN_CAPACITY_MAX_HORIZON_DAYS', 90),

    // Default planning unit for the whole plan when profiles agree.
    // minutes = planned workload minutes; units = relative capacity units.
    'planning_unit' => env('LAB_TECHNICIAN_CAPACITY_PLANNING_UNIT', 'minutes'),
    'allowed_planning_units' => ['minutes', 'units'],

    // Default working week (ISO-8601 weekday ints; 1=Mon .. 7=Sun) when a
    // capacity profile does not declare its own working_days.
    'default_working_days' => [1, 2, 3, 4, 5],

    // Fallback daily capacity used only for coverage projection when a
    // technician has NO capacity profile (labelled UNCONFIGURED — never used
    // as a real available capacity number in utilization).
    'fallback_daily_capacity' => [
        'minutes' => 420, // 7h reference only
        'units' => 8,
    ],

    // Utilization planning bands (percent). Ordering is validated by the audit.
    'utilization' => [
        'watch_at' => 80.0,          // >= watch_at and <= over_at => WATCH
        'over_at' => 100.0,          // > over_at => OVER_CAPACITY
    ],

    // Planning band identifiers (locked; surfaced to UI + export).
    'bands' => [
        'normal' => 'NORMAL',
        'watch' => 'WATCH',
        'over_capacity' => 'OVER_CAPACITY',
        'unavailable' => 'UNAVAILABLE',
        'unconfigured' => 'UNCONFIGURED',
        'partial_data' => 'PARTIAL_DATA',
    ],

    // Due-date risk states (uses the LAB-PROD-2 due_date-only contract).
    'due_risk_states' => [
        'on_track' => 'ON_TRACK',
        'at_risk' => 'AT_RISK',
        'projected_late' => 'PROJECTED_LATE',
        'overdue' => 'OVERDUE',
        'no_due_date' => 'NO_DUE_DATE',
        'unplannable' => 'UNPLANNABLE',
    ],
    // An order is AT_RISK when projected completion lands within this many
    // days of its due date (still on time, but tight).
    'at_risk_buffer_days' => 1,

    // Recommendation engine reason codes (explainable, read-only).
    'recommendation_reason_codes' => [
        'no_eligible_technician' => 'NO_ELIGIBLE_TECHNICIAN',
        'no_capacity' => 'NO_CAPACITY',
        'missing_workload_profile' => 'MISSING_WORKLOAD_PROFILE',
        'missing_capacity_profile' => 'MISSING_CAPACITY_PROFILE',
        'outside_branch_scope' => 'OUTSIDE_BRANCH_SCOPE',
        'service_unsupported' => 'SERVICE_UNSUPPORTED',
    ],
    // External-support consideration reason codes.
    'external_reason_codes' => [
        'no_internal_capacity' => 'NO_INTERNAL_CAPACITY',
        'no_eligible_technician' => 'NO_ELIGIBLE_TECHNICIAN',
        'projected_late' => 'PROJECTED_LATE',
        'service_not_supported' => 'SERVICE_NOT_SUPPORTED',
    ],
    // Max technician candidates returned per unassigned order.
    'recommendation_candidate_limit' => 5,

    // Unplannable classification codes for demand with no valid basis.
    'unplannable_reasons' => [
        'missing_workload_profile' => 'MISSING_WORKLOAD_PROFILE',
        'unknown_service' => 'UNKNOWN_SERVICE',
        'zero_workload' => 'ZERO_WORKLOAD',
    ],

    // Historical fallback (LAB-PROD-2 completion medians) used ONLY as an
    // ESTIMATED workload/capacity signal when explicit profiles are absent.
    // Labelled ESTIMATED with sample size; never a hidden employee score and
    // never silently overrides an explicit profile.
    'historical_fallback' => [
        'enabled' => (bool) env('LAB_TECHNICIAN_CAPACITY_HISTORICAL_FALLBACK', true),
        'min_sample' => 5,
    ],

    // Query / result safety caps (bounded planning; no unbounded scans).
    'max_scan_orders' => (int) env('LAB_TECHNICIAN_CAPACITY_MAX_SCAN_ORDERS', 5000),
    'max_technician_rows' => 500,
    'max_service_rows' => 500,
    'max_unassigned_rows' => 500,
    'export_row_cap' => 5000,

    // Permissions (server-side authorization; sidebar is not a boundary).
    'permissions' => [
        'view' => 'view_lab_technician_capacity',
        'view_own' => 'view_own_lab_technician_capacity',
        'manage' => 'manage_lab_technician_capacity',
        'export' => 'export_lab_technician_capacity',
    ],

    // Generic operational availability categories (NEVER health/diagnosis).
    'availability_reason_categories' => [
        'unavailable',
        'leave',
        'training',
        'half_day',
        'reassigned_duty',
        'other',
    ],

    // Which V2 order statuses count as "assigned to a technician" for load.
    // (Everything non-terminal with an active assignment row; the repository
    // re-asserts against trx_lab_order_assignments.ACTIVE_STATUSES.)
    'assigned_load_uses_active_assignment' => true,

    // Remaining technician-production workload as a fraction of an item's full
    // planned workload, by production band (bands are mapped from canonical
    // LabWorkflowState constants in the service — NOT string literals). This is
    // a declared, configurable planning policy, never a hidden number. Orders
    // whose status is non-terminal but maps to no band use default_band_fraction
    // (conservative full assumption; surfaced in data coverage as assumed_full).
    'remaining_workload_band_fraction' => [
        'pre_production' => 1.0,   // decided/undecided but not yet in a production step
        'step_1' => 0.85,
        'step_2' => 0.65,
        'step_3' => 0.45,
        'step_4' => 0.20,
        'qc' => 0.10,
        'rework' => 0.75,          // QC_FAILED / REWORK_REQUIRED — more work re-opened
        'near_done' => 0.05,       // QC_PASSED
        'post_production' => 0.0,  // MODEL_DONE + delivery states — technician labor complete
    ],
    'default_band_fraction' => 1.0,
];
