<?php

declare(strict_types=1);

namespace App\Modules\ClinicOversight\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\RmeInvoice\Interfaces\RmeRefundRequestRepositoryInterface;
use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215; owner RF-8/RF-8a) —
 * READ-ONLY refund requests in the billing-correction oversight area. GET only, no
 * export (RF-8a: Owner reads, does not export), out of scope ⇒ 404.
 *
 * "Disetujui — belum dibayar" is reported as its own figure, never deducted from
 * revenue nor added to receivables, cash or bank: no money has moved.
 */
class OversightRefundRequestController extends Controller
{
    use AuthorizesRequests;

    private const AREA = ClinicOversightScope::PERMISSION_BILLING_CORRECTION;

    public function __construct(
        private readonly RmeRefundRequestRepositoryInterface $refunds,
        private readonly ClinicOversightScope $scope,
        private readonly OversightAuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('clinic-oversight.view-billing-corrections');

        $user = $request->user();
        $filters = [
            'branch_id' => $request->query('branch_id'),
            'status' => in_array($request->query('status'), RmeRefundRequestStatus::ALL, true) ? $request->query('status') : null,
            'search' => is_string($request->query('search')) ? mb_substr(trim($request->query('search')), 0, 64) : null,
        ];

        $branchIds = $this->scope->narrow($this->scope->branchIdsFor($user, self::AREA), $filters['branch_id']);

        return view('oversight.billing-corrections.refund-requests-index', [
            'rows' => $this->refunds->paginateForBranches($branchIds, $filters),
            'approvedUnsettled' => $this->refunds->approvedUnsettledByBranch($branchIds),
            'filters' => $filters,
            'branchOptions' => $this->scope->branchesFor($user, self::AREA)
                ->map(fn ($branch) => ['id' => (int) $branch->id, 'name' => (string) $branch->name])
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, int $refundRequest): View
    {
        $this->authorize('clinic-oversight.view-billing-corrections');

        $record = $this->refunds->find($refundRequest);
        abort_if($record === null, 404);
        abort_unless($this->scope->allowsBranch($request->user(), self::AREA, (int) $record->branch_id), 404);

        $this->audit->log(OversightAuditService::REFUND_REQUEST_VIEWED, (int) $record->id, [
            'surface' => 'refund_request_detail',
            'record_type' => 'rme_refund_request',
            'record_id' => (int) $record->id,
            'branch_id' => (int) $record->branch_id,
            'patient_id' => (int) $record->patient_id,
        ], $request->user());

        return view('oversight.billing-corrections.refund-requests-show', [
            'refund' => $record->loadMissing(['branch', 'invoice', 'obligation', 'requester', 'decider', 'canceller']),
            'events' => $this->refunds->events((int) $record->id),
        ]);
    }
}
