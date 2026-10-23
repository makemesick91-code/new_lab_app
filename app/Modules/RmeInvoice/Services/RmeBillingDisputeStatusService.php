<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Modules\Branch\Services\BranchService;
use App\Modules\RmeInvoice\Interfaces\RmeBillingDisputeRepositoryInterface;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeAuditEvent;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use Illuminate\Support\Carbon;

/**
 * IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 (rule 203) — the
 * read-only operator status of billing disputes, per RME branch: how many are
 * active in each state, how old the oldest active one is, and whether the branch
 * has at least one usable account able to resolve them. Counts and ids only —
 * never a patient, a description, a note or a reference.
 *
 * Verdict:
 *   FAIL  — an ACTIVE dispute sits in a branch no usable reviewer can reach: its
 *           payment hold cannot be lifted by anyone today.
 *   WATCH — a branch has no reachable reviewer (no dispute yet), or an active
 *           dispute is older than STALE_DAYS.
 *   GO    — otherwise. Active disputes alone are a normal operational state.
 */
class RmeBillingDisputeStatusService
{
    public const STALE_DAYS = 7;

    public function __construct(
        private readonly RmeBillingDisputeRepositoryInterface $disputes,
        private readonly BranchService $branches,
        private readonly RmeBillingDisputeEscalationService $escalation,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $now = Carbon::now();
        $summary = $this->disputes->summaryByBranchAndStatus();
        $findings = [];
        $rows = [];

        foreach ($this->branches->listRmeEnabled() as $branch) {
            $branchId = (int) $branch->id;
            $counts = array_fill_keys(RmeBillingDisputeStatus::ALL, 0);
            $oldestActive = null;

            foreach ($summary->where('branch_id', $branchId) as $line) {
                $counts[(string) $line->status] = (int) $line->total;
                if (RmeBillingDisputeStatus::isActive((string) $line->status) && $line->oldest_reported_at !== null) {
                    $at = Carbon::parse((string) $line->oldest_reported_at);
                    $oldestActive = $oldestActive === null || $at->lt($oldestActive) ? $at : $oldestActive;
                }
            }

            $active = array_sum(array_intersect_key($counts, array_flip(RmeBillingDisputeStatus::ACTIVE)));
            $reviewers = $this->escalation->reviewersFor($branchId)->count();
            $oldestDays = $oldestActive !== null ? (int) floor($oldestActive->diffInDays($now)) : null;

            if ($active > 0 && $reviewers === 0) {
                $findings[] = ['level' => 'FAIL', 'code' => 'ACTIVE_DISPUTE_WITHOUT_REVIEWER', 'branch' => (string) $branch->code];
            } elseif ($reviewers === 0) {
                $findings[] = ['level' => 'WATCH', 'code' => 'BRANCH_WITHOUT_REVIEWER', 'branch' => (string) $branch->code];
            }
            if ($oldestDays !== null && $oldestDays > self::STALE_DAYS) {
                $findings[] = ['level' => 'WATCH', 'code' => 'STALE_ACTIVE_DISPUTE', 'branch' => (string) $branch->code];
            }

            $rows[] = [
                'branch_id' => $branchId,
                'branch_code' => (string) $branch->code,
                'counts' => $counts,
                'active' => $active,
                'oldest_active_days' => $oldestDays,
                'reviewers' => $reviewers,
            ];
        }

        $since = $now->copy()->subDay();
        $recent = $this->disputes->auditActionCountsSince([
            RmeFinancialAuditEvent::ACTION_PAYMENT_REFUSED_ACTIVE_DISPUTE,
            RmeBillingDisputeAuditEvent::ACTION_REPORTED,
            RmeBillingDisputeAuditEvent::ACTION_RESOLVED,
            RmeBillingDisputeAuditEvent::ACTION_REFUSED,
            RmeBillingDisputeAuditEvent::ACTION_ESCALATION_UNROUTED,
            RmeBillingDisputeAuditEvent::ACTION_NOTIFICATION_FAILED,
        ], $since);

        $levels = array_column($findings, 'level');
        $verdict = in_array('FAIL', $levels, true) ? 'FAIL' : (in_array('WATCH', $levels, true) ? 'WATCH' : 'GO');

        return [
            'verdict' => $verdict,
            'generated_at' => $now->toIso8601String(),
            'branches' => $rows,
            'totals' => [
                'active' => array_sum(array_column($rows, 'active')),
            ],
            'last_24h' => $recent,
            'findings' => $findings,
        ];
    }
}
