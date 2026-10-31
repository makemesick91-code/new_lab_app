<?php

declare(strict_types=1);

namespace App\Modules\ClinicOversight\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicOversight\Support\ClinicalDateRange;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\RmeInvoice\Interfaces\RmeOverpaymentObligationRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeRefundRequestRepositoryInterface;
use App\Modules\RmeInvoice\Interfaces\RmeRefundSettlementRepositoryInterface;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216; RFS-6, RF-8a) — READ-ONLY refund
 * payouts in the billing-correction oversight area. GET only, no export, no evidence
 * download (the proof file stays with the operational surface), out of scope ⇒ 404.
 *
 * The period summary keeps three figures apart and never nets them silently:
 *   gross incoming (payments by paid_at), refund outflow (SETTLED by settled_at),
 *   net = gross − outflow — all for the chosen clinical (WITA) period, attributed to
 *   the INVOICE branch. Liabilities that are not money movements (open obligations,
 *   approved-but-unpaid requests, payouts in flight) are shown as point-in-time
 *   figures, never added to or deducted from any money total.
 */
class OversightRefundSettlementController extends Controller
{
    use AuthorizesRequests;

    private const AREA = ClinicOversightScope::PERMISSION_BILLING_CORRECTION;

    public function __construct(
        private readonly RmeRefundSettlementRepositoryInterface $settlements,
        private readonly RmeRefundRequestRepositoryInterface $refunds,
        private readonly RmeOverpaymentObligationRepositoryInterface $obligations,
        private readonly ClinicOversightScope $scope,
        private readonly OversightAuditService $audit,
        private readonly ClinicalClock $clock,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('clinic-oversight.view-billing-corrections');

        $user = $request->user();
        $filters = [
            'branch_id' => $request->query('branch_id'),
            'status' => in_array($request->query('status'), RmeRefundSettlementStatus::ALL, true) ? $request->query('status') : null,
            'search' => is_string($request->query('search')) ? mb_substr(trim($request->query('search')), 0, 64) : null,
        ];

        $range = ClinicalDateRange::tryFrom(
            is_string($request->query('date_from')) ? $request->query('date_from') : null,
            is_string($request->query('date_to')) ? $request->query('date_to') : null,
            $this->clock,
        ) ?? ClinicalDateRange::today($this->clock);

        $branchIds = $this->scope->narrow($this->scope->branchIdsFor($user, self::AREA), $filters['branch_id']);
        $branchOptions = $this->scope->branchesFor($user, self::AREA)
            ->map(fn ($branch) => ['id' => (int) $branch->id, 'name' => (string) $branch->name])
            ->values()
            ->all();

        return view('oversight.billing-corrections.refund-settlements-index', [
            'rows' => $this->settlements->paginateForBranches($branchIds, $filters),
            'summary' => $this->summary($branchIds, $range, $branchOptions),
            'range' => $range,
            'filters' => $filters,
            'branchOptions' => $branchOptions,
        ]);
    }

    public function show(Request $request, int $settlement): View
    {
        $this->authorize('clinic-oversight.view-billing-corrections');

        $record = $this->settlements->find($settlement);
        abort_if($record === null, 404);
        abort_unless($this->scope->allowsBranch($request->user(), self::AREA, (int) $record->branch_id), 404);

        $this->audit->log(OversightAuditService::REFUND_SETTLEMENT_VIEWED, (int) $record->id, [
            'surface' => 'refund_settlement_detail',
            'record_type' => 'rme_refund_settlement',
            'record_id' => (int) $record->id,
            'branch_id' => (int) $record->branch_id,
        ], $request->user());

        return view('oversight.billing-corrections.refund-settlements-show', [
            'settlement' => $record->loadMissing(['branch', 'invoice', 'obligation', 'refundRequest', 'approver', 'starter', 'settler', 'unknownReporter', 'aborter']),
            'events' => $this->settlements->events((int) $record->id),
            // Oversight never streams the proof file (no download surface here).
            'evidenceUrl' => null,
            'actions' => [],
        ]);
    }

    /**
     * @param  list<int>  $branchIds
     * @param  list<array{id: int, name: string}>  $branchOptions
     * @return list<array<string, mixed>>
     */
    private function summary(array $branchIds, ClinicalDateRange $range, array $branchOptions): array
    {
        $gross = $this->settlements->grossIncomingByBranch($branchIds, $range->startUtc, $range->endExclusiveUtc);
        $outflow = $this->settlements->settledOutflowByBranch($branchIds, $range->startUtc, $range->endExclusiveUtc);
        $inFlight = $this->settlements->inFlightByBranch($branchIds);
        $approved = $this->refunds->approvedUnsettledByBranch($branchIds);
        $open = $this->obligations->openSummaryByBranch();

        $rows = [];
        foreach ($branchOptions as $branch) {
            $id = $branch['id'];
            if (! in_array($id, $branchIds, true)) {
                continue;
            }

            $grossCents = (int) ($gross[$id] ?? 0);
            $outflowCents = (int) ($outflow[$id]['amount_cents'] ?? 0);

            $rows[] = [
                'branch_id' => $id,
                'branch_name' => $branch['name'],
                'gross_incoming_cents' => $grossCents,
                'refund_outflow_cents' => $outflowCents,
                'refund_outflow_count' => (int) ($outflow[$id]['count'] ?? 0),
                'net_received_cents' => $grossCents - $outflowCents,
                'open_obligation_cents' => isset($open[$id]) ? RmeInvoiceDocument::toCents($open[$id]['amount']) : 0,
                'approved_unsettled_cents' => (int) ($approved[$id]['amount_cents'] ?? 0),
                'in_flight_cents' => (int) ($inFlight[$id]['amount_cents'] ?? 0),
                'in_flight_count' => (int) ($inFlight[$id]['count'] ?? 0),
            ];
        }

        return $rows;
    }
}
