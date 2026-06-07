<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Inventory Analytics Summary Scheduler (Sprint 16.8.7)
|--------------------------------------------------------------------------
|
| Refreshes rpt_* read models from ledger/procurement. Safe when feature flag
| is false — summaries can be prepared before INVENTORY_ANALYTICS_SUMMARY_ENABLED=true.
|
*/
Schedule::command('inventory:analytics-summary:refresh --all')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->name('inventory-analytics-summary-refresh');

Schedule::command('inventory:analytics-summary:prune')
    ->monthlyOn(1, '02:30')
    ->withoutOverlapping()
    ->name('inventory-analytics-summary-prune');
