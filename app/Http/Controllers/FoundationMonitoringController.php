<?php

namespace App\Http\Controllers;

use App\Services\Foundation\FoundationMonitoringStatusService;
use Illuminate\View\View;

/**
 * MON-1 — read-only Foundation Monitoring & Observability surface.
 *
 * Thin controller. All aggregation lives in FoundationMonitoringStatusService.
 * Gated by `view_developer_console` (Super Admin only via Gate::before). GET
 * only — the page never mutates runtime state and never runs heavy audits on a
 * web request (include_audits is left false; audit signals show cached
 * evidence metadata only).
 */
class FoundationMonitoringController extends Controller
{
    public function __construct(private readonly FoundationMonitoringStatusService $status) {}

    public function index(): View
    {
        return view('foundation.monitoring.index', [
            'report' => $this->status->collect(),
            'registry' => (array) config('foundation_monitoring.signals', []),
        ]);
    }
}
