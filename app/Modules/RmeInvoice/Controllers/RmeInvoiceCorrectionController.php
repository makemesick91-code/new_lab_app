<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceCorrection;
use App\Modules\RmeInvoice\Models\RmeOverpaymentObligation;
use App\Modules\RmeInvoice\Requests\DecideRmeInvoiceCorrectionRequest;
use App\Modules\RmeInvoice\Requests\RmeInvoiceCorrectionFilterRequest;
use App\Modules\RmeInvoice\Requests\StoreRmeInvoiceCorrectionRequest;
use App\Modules\RmeInvoice\Services\RmeInvoiceCorrectionService;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceDocument;
use App\Modules\RmeInvoice\Support\RmeInvoiceLedger;
use App\Modules\RmeInvoice\Support\RmeInvoiceSnapshot;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — operational correction pages.
 *
 * Thin: authorize (policy), delegate (service), render. Every MUTATION route
 * answers 404 while the `rme.invoice_correction` flag is off — the surface does
 * not reveal an unfinished workflow — and the service refuses independently.
 * READ routes (list, detail, frozen document) stay available to permission
 * holders even with the flag off, so an emergency stop never hides history.
 */
class RmeInvoiceCorrectionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmeInvoiceCorrectionService $corrections,
    ) {}

    public function index(RmeInvoiceCorrectionFilterRequest $request): View
    {
        $this->authorize('viewAny', RmeInvoiceCorrection::class);

        $filters = $request->filters();

        return view('rme.invoice-corrections.index', [
            'mode' => 'list',
            'corrections' => $this->corrections->paginateFor($request->user(), $filters),
            'counts' => $this->corrections->statusCountsFor($request->user()),
            'filters' => $filters,
            'capabilityEnabled' => $this->corrections->capabilityEnabled(),
        ]);
    }

    public function approvals(RmeInvoiceCorrectionFilterRequest $request): View
    {
        $this->authorize('viewAny', RmeInvoiceCorrection::class);

        $filters = $request->filters();
        $filters['status'] ??= RmeInvoiceCorrectionStatus::SUBMITTED;

        return view('rme.invoice-corrections.index', [
            'mode' => 'approvals',
            'corrections' => $this->corrections->paginateFor($request->user(), $filters),
            'counts' => $this->corrections->statusCountsFor($request->user()),
            'filters' => $filters,
            'capabilityEnabled' => $this->corrections->capabilityEnabled(),
        ]);
    }

    public function show(int $correction): View
    {
        $record = $this->inScope($this->corrections->find($correction));
        $this->authorize('view', $record);

        return view('rme.invoice-corrections.show', [
            'correction' => $record,
            'versions' => $record->invoice !== null ? $this->corrections->documentVersionsFor($record->invoice) : collect(),
            'capabilityEnabled' => $this->corrections->capabilityEnabled(),
            // Rule 195 — approve (which applies, D1a) and the recovery apply are
            // offered only inside the pilot; reject/cancel stay available so an
            // active correction can always be resolved.
            'pilotAdmitted' => $this->corrections->capabilityEnabledForBranch((int) $record->branch_id),
            // Rule 197 (owner D1e) — the controls this ACCOUNT may be offered.
            // Explicit because Gate::before makes every @can true for a Super
            // Admin; the service re-asserts each under the lock.
            'actions' => $this->corrections->actionsFor($record, request()->user()),
            'ledger' => $this->ledgerFigures($record),
        ]);
    }

    /**
     * FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the ledger figures the checker
     * reviews for a POST_PAYMENT correction: money received at request, and the
     * outcome the approval would produce (or, once applied, the recorded outcome).
     * Derived with the same helper the service uses; display only — approval
     * re-derives everything under the lock. Null for a PRE_PAYMENT correction.
     *
     * @return array{paid_cents: int, original_outstanding_cents: int, projected_outstanding_cents: int, projected_overpaid_cents: int, projected_status: string, status_before: string|null, applied: bool, obligation: RmeOverpaymentObligation|null}|null
     */
    private function ledgerFigures(RmeInvoiceCorrection $correction): ?array
    {
        if (! $correction->isPostPayment()) {
            return null;
        }

        $paid = RmeInvoiceDocument::toCents($correction->paid_total_at_request);
        $original = RmeInvoiceDocument::toCents($correction->original_grand_total);
        $proposed = RmeInvoiceDocument::toCents($correction->proposed_grand_total);
        $applied = $correction->isApplied();

        return [
            'paid_cents' => $paid,
            'original_outstanding_cents' => RmeInvoiceLedger::outstandingCents($original, $paid),
            'projected_outstanding_cents' => $applied ? RmeInvoiceDocument::toCents($correction->outstanding_after) : RmeInvoiceLedger::outstandingCents($proposed, $paid),
            'projected_overpaid_cents' => $applied ? RmeInvoiceDocument::toCents($correction->overpaid_after) : RmeInvoiceLedger::overpaidCents($proposed, $paid),
            'projected_status' => $applied ? (string) $correction->invoice_status_after : RmeInvoiceLedger::statusAfterMoneyReceived($proposed, $paid),
            'status_before' => $correction->invoice_status_at_request,
            'applied' => $applied,
            'obligation' => $correction->overpaymentObligation,
        ];
    }

    public function create(ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): View
    {
        // Rule 195 — flag ON *and* the invoice's branch on the pilot allowlist.
        // Outside the pilot the capability does not exist, exactly as when the
        // flag is off; the service asserts the same predicate under the lock.
        abort_unless($this->corrections->capabilityEnabledForBranch((int) $rmeInvoice->branch_id), 404);
        abort_unless($rmeInvoice->belongsToVisit($clinicVisit), 404);
        // Route middleware already requires the request permission, so the
        // policy can only fail on branch scope — answered like a missing invoice.
        abort_unless(request()->user()?->can('create', [RmeInvoiceCorrection::class, $rmeInvoice]), 404);
        // Rule 197 (owner D1e) — only the designated maker account may file a
        // correction. Asked AFTER the branch scope, so an out-of-branch caller
        // still gets the 404 that reveals nothing; the service re-asserts it.
        abort_unless($this->corrections->mayRequest(request()->user()), 403);

        $panel = $this->corrections->panelFor($rmeInvoice, request()->user());

        $invoice = $rmeInvoice->load(['items.treatment', 'branch']);

        return view('rme.invoice-corrections.create', [
            'visit' => $clinicVisit->load('patient'),
            'invoice' => $invoice,
            'panel' => $panel,
            'treatments' => $this->corrections->treatmentOptionsFor($invoice),
            'initialLines' => $this->corrections->initialFormLines($invoice),
            'idempotencyKey' => (string) Str::uuid(),
            // FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 — the ledger the cashier
            // sees on a post-payment form (display only; the service re-reads it
            // under the lock).
            'paidCents' => RmeInvoiceLedger::paidCents($invoice),
            'outstandingCents' => RmeInvoiceLedger::outstandingCents(RmeInvoiceLedger::grandTotalCents($invoice), RmeInvoiceLedger::paidCents($invoice)),
        ]);
    }

    public function store(StoreRmeInvoiceCorrectionRequest $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): RedirectResponse
    {
        abort_unless($this->corrections->capabilityEnabledForBranch((int) $rmeInvoice->branch_id), 404);
        abort_unless($rmeInvoice->belongsToVisit($clinicVisit), 404);
        // Route middleware already requires the request permission, so the
        // policy can only fail on branch scope — answered like a missing invoice.
        abort_unless(request()->user()?->can('create', [RmeInvoiceCorrection::class, $rmeInvoice]), 404);
        // Rule 197 (owner D1e) — only the designated maker account may file a
        // correction. Asked AFTER the branch scope, so an out-of-branch caller
        // still gets the 404 that reveals nothing; the service re-asserts it.
        abort_unless($this->corrections->mayRequest(request()->user()), 403);

        $correction = $this->corrections->submit($rmeInvoice, $request->user(), $request->correctionData());

        return redirect()
            ->route('rme.invoice-corrections.show', $correction->id)
            ->with('success', 'Pengajuan koreksi '.$correction->correction_number.' terkirim dan menunggu persetujuan akun lain.');
    }

    public function approve(DecideRmeInvoiceCorrectionRequest $request, int $correction): RedirectResponse
    {
        $record = $this->mutable($correction);
        $this->authorize('approve', $record);

        $applied = $this->corrections->approve($record, $request->user(), $request->note());

        // Owner D1a (rule 195): approving APPLIES the correction in the same
        // transaction. The message reports what the service actually did.
        return back()->with('success', $applied->status === RmeInvoiceCorrectionStatus::APPLIED
            ? 'Koreksi disetujui dan langsung diterapkan. Dokumen asli tersimpan sebagai versi v0 dan revisi baru diterbitkan.'
            : 'Koreksi disetujui.');
    }

    public function reject(DecideRmeInvoiceCorrectionRequest $request, int $correction): RedirectResponse
    {
        $record = $this->mutable($correction);
        $this->authorize('reject', $record);

        $this->corrections->reject($record, $request->user(), $request->note());

        return back()->with('success', 'Koreksi ditolak.');
    }

    public function cancel(DecideRmeInvoiceCorrectionRequest $request, int $correction): RedirectResponse
    {
        $record = $this->mutable($correction);
        $this->authorize('cancel', $record);

        $this->corrections->cancel($record, $request->user(), $request->note());

        return back()->with('success', 'Pengajuan koreksi dibatalkan.');
    }

    public function apply(int $correction): RedirectResponse
    {
        $record = $this->mutable($correction);
        $this->authorize('apply', $record);

        $this->corrections->apply($record, request()->user());

        return back()->with('success', 'Koreksi diterapkan. Dokumen asli tersimpan sebagai versi v0 dan revisi baru diterbitkan.');
    }

    public function documentVersion(int $version): View
    {
        $record = $this->corrections->findDocumentVersion($version);
        // Out of the caller's branch scope is indistinguishable from absent.
        abort_if($record === null || request()->user()?->cannot('viewDocumentVersion', $record), 404);

        $latest = $record->invoice !== null
            ? $this->corrections->documentVersionsFor($record->invoice)->max('version_number')
            : null;

        return view('rme.invoice-corrections.document-version', [
            'version' => $record,
            'snapshot' => (array) $record->snapshot,
            'complete' => RmeInvoiceSnapshot::isComplete((array) $record->snapshot),
            'integrity' => $record->integrityHolds(),
            'supersededBy' => $latest !== null && (int) $latest > (int) $record->version_number ? (int) $latest : null,
        ]);
    }

    private function mutable(int $id): RmeInvoiceCorrection
    {
        abort_unless($this->corrections->capabilityEnabled(), 404);

        return $this->inScope($this->corrections->find($id));
    }

    /**
     * A correction outside the caller's working branch scope answers 404, exactly
     * like one that does not exist, so a crafted id cannot probe another branch's
     * corrections. Every caller here already holds an operational correction
     * permission (route middleware), so `view` fails only on branch scope.
     */
    private function inScope(?RmeInvoiceCorrection $record): RmeInvoiceCorrection
    {
        abort_if($record === null || request()->user()?->cannot('view', $record), 404);

        return $record;
    }
}
