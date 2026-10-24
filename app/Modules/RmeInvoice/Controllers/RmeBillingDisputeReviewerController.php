<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\RmeInvoice\Requests\RevokeRmeBillingDisputeReviewerRequest;
use App\Modules\RmeInvoice\Requests\StoreRmeBillingDisputeReviewerRequest;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeReviewerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1 (owner OPS-OQ-1a,
 * rule 204) — administration of explicit branch billing dispute reviewers.
 *
 * Thin: the route middleware requires the governance permission, the service
 * re-asserts it on the persisted account and decides every eligibility rule. The
 * branch and the account are the request's claim, validated by the service —
 * never trusted.
 */
class RmeBillingDisputeReviewerController extends Controller
{
    public function __construct(
        private readonly RmeBillingDisputeReviewerService $reviewers,
        private readonly BranchService $branches,
    ) {}

    public function index(Request $request): View
    {
        return view('rme.billing-disputes.reviewers.index', [
            'designations' => $this->reviewers->listing(),
            'candidates' => $this->reviewers->candidatesFor($request->user()),
            'branches' => $this->branches->listRmeEnabled(),
        ]);
    }

    public function store(StoreRmeBillingDisputeReviewerRequest $request): RedirectResponse
    {
        $target = User::query()->findOrFail((int) $request->validated('user_id'));

        $designation = $this->reviewers->designate(
            $request->user(),
            $target,
            (int) $request->validated('branch_id'),
            $request->validated('reason'),
        );

        return redirect()->route('rme.billing-dispute-reviewers.index')
            ->with('success', 'Peninjau tagihan cabang ditunjuk (penunjukan #'.$designation->id.').');
    }

    public function revoke(RevokeRmeBillingDisputeReviewerRequest $request, int $designation): RedirectResponse
    {
        $record = $this->reviewers->find($designation) ?? abort(404);

        $this->reviewers->revoke($request->user(), $record, $request->validated('reason'));

        return redirect()->route('rme.billing-dispute-reviewers.index')
            ->with('success', 'Penunjukan peninjau #'.$designation.' dicabut. Tindakan berikutnya akun tersebut pada laporan cabang ini ditolak.');
    }
}
