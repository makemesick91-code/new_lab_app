<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Analytics Summary Tables (Sprint 16.8.4)
    |--------------------------------------------------------------------------
    |
    | When enabled, InventoryAnalyticsRepositoryInterface resolves to
    | InventorySummaryAnalyticsRepository (reads rpt_* read models).
    | Default false — live ledger repository remains active until opted in.
    |
    */
    'analytics_summary_enabled' => env('INVENTORY_ANALYTICS_SUMMARY_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Analytics Summary Retention (Sprint 16.8.7)
    |--------------------------------------------------------------------------
    |
    | Days to retain daily/procurement summary rows before optional prune.
    | Applies only to rpt_inventory_daily_summaries and
    | rpt_procurement_daily_summaries — never ledger or snapshot tables.
    |
    */
    'analytics_summary_retention_days' => env('INVENTORY_ANALYTICS_SUMMARY_RETENTION_DAYS', 730),
];
