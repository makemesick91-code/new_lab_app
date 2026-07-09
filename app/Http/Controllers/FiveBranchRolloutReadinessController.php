<?php

namespace App\Http\Controllers;

use App\Services\Foundation\FiveBranchRolloutReadinessService;
use Illuminate\View\View;

/**
 * ROLL-5-1 — read-only Five Branch Controlled Rollout Readiness surface.
 *
 * Thin controller. All aggregation lives in FiveBranchRolloutReadinessService.
 * Gated by `view_developer_console` (Super Admin only via Gate::before). GET
 * only — the page never mutates runtime state, never runs heavy audits, and
 * never runs the capacity smoke on a web request (include_audits and
 * capacity_smoke are left false; audit signals show cached evidence metadata
 * only). Output is sanitized: no secrets, env values, KTP/NIK, or raw logs.
 */
class FiveBranchRolloutReadinessController extends Controller
{
    public function __construct(private readonly FiveBranchRolloutReadinessService $readiness) {}

    public function index(): View
    {
        return view('foundation.rollout.five-branch-readiness', [
            'report' => $this->readiness->collect(),
            'categories' => (array) config('rollout_readiness.categories', []),
            'requiredCommands' => (array) config('rollout_readiness.required_commands', []),
        ]);
    }
}
