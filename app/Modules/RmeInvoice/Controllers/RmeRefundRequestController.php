<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeRefundRequest;
use App\Modules\RmeInvoice\Requests\DecideRmeRefundRequestRequest;
use App\Modules\RmeInvoice\Requests\StoreRmeRefundRequestRequest;
use App\Modules\RmeInvoice\Services\RmeRefundRequestService;
use App\Modules\RmeInvoice\Services\RmeRefundSettlementService;
use App\Modules\RmeInvoice\Support\RmeRefundRequestIntent;
use App\Modules\RmeInvoice\Support\RmeRefundRequestRefused;
use App\Modules\RmeInvoice\Support\RmeRefundRequestSnapshot;
use App\Modules\RmeInvoice\Support\RmeRefundRequestStatus;
use Closure;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — thin: resolve the
 * actor, delegate to RmeRefundRequestService, map a refusal to a 404 or a coded
 * message. Every rule lives in the service.
 */
class RmeRefundRequestController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmeRefundRequestService $refunds,
        private readonly RmeRefundSettlementService $settlements,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($this->refunds->capabilityEnabled(), 404);
        $this->authorize('viewAny', RmeRefundRequest::class);

        $filters = [
            'status' => in_array($request->query('status'), RmeRefundRequestStatus::ALL, true) ? $request->query('status') : null,
            'search' => is_string($request->query('search')) ? mb_substr(trim($request->query('search')), 0, 64) : null,
        ];

        return view('rme.refund-requests.index', [
            'rows' => $this->refunds->paginateFor($request->user(), $filters),
            'counts' => $this->refunds->statusCountsFor($request->user()),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, int $refundRequest): View
    {
        abort_unless($this->refunds->capabilityEnabled(), 404);
        $record = $this->findVisibleOr404($refundRequest, $request);

        return view('rme.refund-requests.show', [
            'refund' => $record->loadMissing(['branch', 'invoice', 'obligation', 'requester', 'decider', 'canceller']),
            'events' => $this->refunds->eventsFor($record),
            'actions' => $this->refunds->actionsFor($record, $request->user()),
            // FEATURE-RME-REFUND-SETTLEMENT-1: the payout status beside the decision,
            // so an APPROVED request is never read as money already paid.
            'settlementPanel' => $this->settlements->panelFor($record, $request->user()),
        ]);
    }

    public function create(Request $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): View
    {
        abort_unless($this->refunds->capabilityEnabled(), 404);
        abort_unless($rmeInvoice->belongsToVisit($clinicVisit), 404);

        $panel = $this->refunds->panelFor($rmeInvoice, $request->user());
        abort_unless($panel['can_request'], 404);

        return view('rme.refund-requests.create', [
            'visit' => $clinicVisit,
            'invoice' => $rmeInvoice,
            'obligation' => $panel['obligation'],
            'intent' => RmeRefundRequestIntent::issue((int) $panel['obligation']->id),
            'declaredAmount' => RmeRefundRequestSnapshot::decimal(RmeRefundRequestSnapshot::cents($panel['obligation']->amount)),
        ]);
    }

    public function store(StoreRmeRefundRequestRequest $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): RedirectResponse
    {
        return $this->guarded(function () use ($request, $rmeInvoice) {
            $refund = $this->refunds->submit($rmeInvoice, $request->user(), $request->validated());

            // A replayed submission returns the original, which may already be decided.
            $message = $refund->status === RmeRefundRequestStatus::REQUESTED
                ? 'Permintaan refund '.$refund->request_number.' diajukan dan menunggu persetujuan.'
                : 'Permintaan refund '.$refund->request_number.' sudah tercatat sebelumnya (status: '.RmeRefundRequestStatus::label($refund->status).').';

            return redirect()->route('rme.refund-requests.show', $refund->id)->with('success', $message);
        });
    }

    public function approve(DecideRmeRefundRequestRequest $request, int $refundRequest): RedirectResponse
    {
        return $this->guarded(function () use ($request, $refundRequest) {
            // Account authority first, then scope: neither answer depends on whether the id exists.
            $this->refunds->assertReviewerAccount($request->user());
            $record = $this->findVisibleOr404($refundRequest, $request);
            $this->refunds->approve($record, $request->user(), $request->validated());

            return redirect()->route('rme.refund-requests.show', $record->id)
                ->with('success', 'Permintaan refund disetujui. Belum ada uang yang dibayarkan.');
        });
    }

    public function reject(DecideRmeRefundRequestRequest $request, int $refundRequest): RedirectResponse
    {
        return $this->guarded(function () use ($request, $refundRequest) {
            // Account authority first, then scope: neither answer depends on whether the id exists.
            $this->refunds->assertReviewerAccount($request->user());
            $record = $this->findVisibleOr404($refundRequest, $request);
            $this->refunds->reject($record, $request->user(), $request->validated());

            return redirect()->route('rme.refund-requests.show', $record->id)
                ->with('success', 'Permintaan refund ditolak. Kewajiban pengembalian tetap terbuka.');
        });
    }

    public function cancel(DecideRmeRefundRequestRequest $request, int $refundRequest): RedirectResponse
    {
        return $this->guarded(function () use ($request, $refundRequest) {
            $record = $this->findVisibleOr404($refundRequest, $request);
            $this->refunds->cancel($record, $request->user(), $request->validated());

            return redirect()->route('rme.refund-requests.show', $record->id)
                ->with('success', 'Permintaan refund dibatalkan. Kewajiban pengembalian tetap terbuka.');
        });
    }

    /** Missing and out of scope are the same answer: 404 (rule 215 §B.6). */
    private function findVisibleOr404(int $id, Request $request): RmeRefundRequest
    {
        $record = $this->refunds->find($id);
        abort_if($record === null || ! $this->refunds->mayView($request->user(), (int) $record->branch_id), 404);

        return $record;
    }

    /** @param  Closure(): RedirectResponse  $work */
    private function guarded(Closure $work): RedirectResponse
    {
        try {
            return $work();
        } catch (RmeRefundRequestRefused $refused) {
            abort_if($refused->notFound, 404);

            return back()->withInput()->withErrors([$refused->field => $refused->displayMessage()]);
        }
    }
}
