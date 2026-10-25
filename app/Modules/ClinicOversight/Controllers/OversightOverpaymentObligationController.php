<?php

declare(strict_types=1);

namespace App\Modules\ClinicOversight\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicOversight\Services\OversightAuditService;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\RmeInvoice\Interfaces\RmeOverpaymentObligationRepositoryInterface;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — overpayment obligations in the
 * BILLING CORRECTION oversight area (owner decision PP-9): Owner and the oversight
 * roles see the list read-only.
 *
 * GET-only. No action posts anywhere: no role holds a write permission over an
 * obligation in this release, and settling one (a refund) is a later sprint. The
 * branch set is the caller's ClinicOversightScope for the billing-correction area;
 * an obligation outside it is 404 — its existence is not confirmed.
 */
class OversightOverpaymentObligationController extends Controller
{
    use AuthorizesRequests;

    private const AREA = ClinicOversightScope::PERMISSION_BILLING_CORRECTION;

    public function __construct(
        private readonly RmeOverpaymentObligationRepositoryInterface $obligations,
        private readonly ClinicOversightScope $scope,
        private readonly OversightAuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('clinic-oversight.view-billing-corrections');

        $user = $request->user();
        $filters = [
            'branch_id' => $request->query('branch_id'),
            'status' => in_array($request->query('status'), RmeOverpaymentObligation::STATUSES, true) ? $request->query('status') : null,
            'search' => is_string($request->query('search')) ? mb_substr(trim($request->query('search')), 0, 64) : null,
        ];

        $branchIds = $this->scope->narrow($this->scope->branchIdsFor($user, self::AREA), $filters['branch_id']);

        return view('oversight.billing-corrections.obligations-index', [
            'rows' => $this->obligations->paginateForBranches($branchIds, $filters),
            'filters' => $filters,
            'branchOptions' => $this->scope->branchesFor($user, self::AREA)
                ->map(fn ($branch) => ['id' => (int) $branch->id, 'name' => (string) $branch->name])
                ->values()
                ->all(),
        ]);
    }

    public function show(Request $request, int $obligation): View
    {
        $this->authorize('clinic-oversight.view-billing-corrections');

        $record = $this->obligations->find($obligation);
        abort_if($record === null, 404);
        abort_unless($this->scope->allowsBranch($request->user(), self::AREA, (int) $record->branch_id), 404);

        $this->audit->log(OversightAuditService::OVERPAYMENT_OBLIGATION_VIEWED, (int) $record->id, [
            'surface' => 'overpayment_obligation_detail',
            'record_type' => 'rme_overpayment_obligation',
            'record_id' => (int) $record->id,
            'branch_id' => (int) $record->branch_id,
            'patient_id' => (int) $record->patient_id,
        ], $request->user());

        return view('oversight.billing-corrections.obligations-show', [
            'obligation' => $record,
        ]);
    }
}
