<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Requests\ResolveRmeBillingDisputeRequest;
use App\Modules\RmeInvoice\Requests\RmeBillingDisputeNoteRequest;
use App\Modules\RmeInvoice\Requests\StoreRmeBillingDisputeRequest;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeEscalationService;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1 — owner OPS-OQ-1 (rule
 * 203): report an incorrect invoice, escalate it to the branch authority, and
 * resolve it through a verified outcome.
 *
 * Thin: authorize (policy), delegate (service), render. A dispute outside the
 * caller's branch scope is a 404 — never a 403 that would confirm it exists.
 * The service repeats every authorization under the invoice row lock.
 */
class RmeBillingDisputeController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmeBillingDisputeService $disputes,
        private readonly RmeBillingDisputeEscalationService $escalation,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', RmeBillingDispute::class);

        $status = $request->query('status');
        $status = is_string($status) && ($status === 'ALL' || in_array($status, RmeBillingDisputeStatus::ALL, true)) ? $status : 'ACTIVE';

        return view('rme.billing-disputes.index', [
            'disputes' => $this->disputes->paginateFor($request->user(), ['status' => $status]),
            'counts' => $this->disputes->statusCountsFor($request->user()),
            'status' => $status,
        ]);
    }

    public function show(Request $request, int $dispute): View
    {
        $record = $this->visible($request, $dispute);
        $record->load(['branch:id,code,name', 'invoice', 'reporter:id,name', 'reviewer:id,name', 'escalator:id,name', 'resolver:id,name', 'resolutionCorrection:id,correction_number']);
        $user = $request->user();

        return view('rme.billing-disputes.show', [
            'dispute' => $record,
            'timeline' => $this->disputes->timeline($record),
            'canViewInvoice' => $record->invoice !== null && $user->can('view', $record->invoice),
            'canNote' => $user->can('addNote', $record),
            'canReview' => $user->can('review', $record),
            'reviewerCount' => $record->isActive() ? $this->escalation->reviewersFor((int) $record->branch_id)->count() : null,
        ]);
    }

    public function create(Request $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): View|RedirectResponse
    {
        $this->assertReportable($request, $clinicVisit, $rmeInvoice);

        $active = $this->disputes->activeFor($rmeInvoice);
        if ($active !== null) {
            return redirect()->route('rme.billing-disputes.show', $active->id)
                ->with('error', 'Tagihan ini sudah memiliki laporan ketidaksesuaian yang aktif.');
        }

        return view('rme.billing-disputes.create', [
            'visit' => $clinicVisit,
            'invoice' => $rmeInvoice,
        ]);
    }

    public function store(StoreRmeBillingDisputeRequest $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): RedirectResponse
    {
        $this->assertReportable($request, $clinicVisit, $rmeInvoice);

        $dispute = $this->disputes->report($rmeInvoice, $request->user(), $request->validated());

        return redirect()->route('rme.billing-disputes.show', $dispute->id)
            ->with('success', 'Laporan '.$dispute->dispute_number.' tercatat. Pembayaran tagihan ditahan dan kasus diteruskan ke penanggung jawab cabang.');
    }

    public function review(RmeBillingDisputeNoteRequest $request, int $dispute): RedirectResponse
    {
        $record = $this->visible($request, $dispute);
        $this->authorize('review', $record);

        $this->disputes->startReview($record, $request->user(), $request->validated('note'));

        return back()->with('success', 'Laporan sedang ditinjau.');
    }

    public function escalate(RmeBillingDisputeNoteRequest $request, int $dispute): RedirectResponse
    {
        $record = $this->visible($request, $dispute);
        $this->authorize('escalate', $record);

        $this->disputes->escalate($record, $request->user(), $request->validated('note'));

        return back()->with('success', 'Laporan dieskalasi ke Owner. Pembayaran tagihan tetap ditahan.');
    }

    public function note(RmeBillingDisputeNoteRequest $request, int $dispute): RedirectResponse
    {
        $record = $this->visible($request, $dispute);
        $this->authorize('addNote', $record);

        $this->disputes->addNote($record, $request->user(), $request->validated('note'));

        return back()->with('success', 'Catatan tindak lanjut tersimpan.');
    }

    public function resolve(ResolveRmeBillingDisputeRequest $request, int $dispute): RedirectResponse
    {
        $record = $this->visible($request, $dispute);
        $this->authorize('resolve', $record);

        $this->disputes->resolve($record, $request->user(), $request->validated());

        return back()->with('success', 'Laporan diselesaikan. Penahanan pembayaran dari laporan ini dilepas; aturan pembayaran lain tetap berlaku.');
    }

    private function visible(Request $request, int $id): RmeBillingDispute
    {
        $record = $this->disputes->find($id);
        abort_if($record === null || $request->user()?->cannot('view', $record), 404);

        return $record;
    }

    private function assertReportable(Request $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): void
    {
        abort_unless($rmeInvoice->belongsToVisit($clinicVisit), 404);
        // Out of scope reads as "not found", never as "exists but forbidden".
        abort_unless($request->user()?->can('create', [RmeBillingDispute::class, $rmeInvoice]), 404);
    }
}
