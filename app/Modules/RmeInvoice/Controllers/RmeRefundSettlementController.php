<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\RmeInvoice\Models\RmeRefundSettlement;
use App\Modules\RmeInvoice\Requests\AbortRmeRefundSettlementRequest;
use App\Modules\RmeInvoice\Requests\ConfirmRmeRefundSettlementRequest;
use App\Modules\RmeInvoice\Requests\ReportRmeRefundSettlementOutcomeRequest;
use App\Modules\RmeInvoice\Requests\StartRmeRefundSettlementRequest;
use App\Modules\RmeInvoice\Services\RmeRefundRequestService;
use App\Modules\RmeInvoice\Services\RmeRefundSettlementService;
use App\Modules\RmeInvoice\Support\RmeRefundRequestSnapshot;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementEvidence;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementIntent;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementRefused;
use App\Modules\RmeInvoice\Support\RmeRefundSettlementStatus;
use Closure;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — thin: resolve the actor, delegate to
 * RmeRefundSettlementService, map a refusal to a 404 or a coded message. Every rule
 * lives in the service. While the capability is off every action answers 404.
 */
class RmeRefundSettlementController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmeRefundSettlementService $settlements,
        private readonly RmeRefundRequestService $refundRequests,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($this->settlements->capabilityEnabled(), 404);
        $this->authorize('viewAny', RmeRefundSettlement::class);

        $filters = [
            'status' => in_array($request->query('status'), RmeRefundSettlementStatus::ALL, true) ? $request->query('status') : null,
            'search' => is_string($request->query('search')) ? mb_substr(trim($request->query('search')), 0, 64) : null,
        ];

        return view('rme.refund-settlements.index', [
            'rows' => $this->settlements->paginateFor($request->user(), $filters),
            'counts' => $this->settlements->statusCountsFor($request->user()),
            'queue' => $this->settlements->approvedQueueFor($request->user()),
            // The pay button follows the settlement rule (designation included), not
            // the bare permission, which Gate::before grants every Super Admin.
            'isSettler' => $this->settlements->isSettlerAccount($request->user()),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, int $settlement): View
    {
        abort_unless($this->settlements->capabilityEnabled(), 404);
        $record = $this->findVisibleOr404($settlement, $request);

        return view('rme.refund-settlements.show', [
            'settlement' => $record->loadMissing(['branch', 'invoice', 'refundRequest', 'obligation', 'approver', 'starter', 'settler', 'aborter', 'unknownReporter']),
            'events' => $this->settlements->eventsFor($record),
            'actions' => $this->settlements->actionsFor($record, $request->user()),
        ]);
    }

    public function create(Request $request, int $refundRequest): View
    {
        abort_unless($this->settlements->capabilityEnabled(), 404);
        $record = $this->refundRequests->find($refundRequest);
        abort_if($record === null || ! $this->settlements->mayStart($record, $request->user()), 404);

        return view('rme.refund-settlements.create', [
            'refund' => $record->loadMissing(['branch', 'invoice', 'decider']),
            'intent' => RmeRefundSettlementIntent::issue((int) $record->id),
            'declaredAmount' => RmeRefundRequestSnapshot::decimal(RmeRefundRequestSnapshot::cents($record->amount)),
        ]);
    }

    public function store(StartRmeRefundSettlementRequest $request, int $refundRequest): RedirectResponse
    {
        return $this->guarded(function () use ($request, $refundRequest) {
            $record = $this->refundRequests->find($refundRequest);
            // Missing and not visible are the same answer, so a request id cannot be
            // probed by an account that cannot see its branch.
            abort_if($record === null || ! $this->settlements->mayView($request->user(), (int) $record->branch_id), 404);

            $settlement = $this->settlements->start($record, $request->user(), $request->validated());
            $message = $settlement->status === RmeRefundSettlementStatus::DISBURSING && (int) $settlement->started_by === (int) $request->user()->id
                ? 'Pembayaran refund '.$settlement->settlement_number.' dicatat sebagai SEDANG DIBAYARKAN. Lakukan pembayaran, lalu konfirmasi dengan bukti. Uang belum tercatat keluar.'
                : 'Pembayaran refund '.$settlement->settlement_number.' sudah tercatat sebelumnya (status: '.RmeRefundSettlementStatus::label($settlement->status).').';

            return redirect()->route('rme.refund-settlements.show', $settlement->id)->with('success', $message);
        });
    }

    public function confirm(ConfirmRmeRefundSettlementRequest $request, int $settlement): RedirectResponse
    {
        return $this->guarded(function () use ($request, $settlement) {
            $record = $this->findVisibleOr404($settlement, $request);
            $evidence = $request->file('evidence');
            $this->settlements->confirm($record, $request->user(), $request->validated(), is_array($evidence) ? null : $evidence);

            return redirect()->route('rme.refund-settlements.show', $record->id)
                ->with('success', 'Refund tercatat TELAH DIBAYARKAN. Kewajiban pengembalian dan permintaan refund ditutup.');
        });
    }

    public function outcomeUnknown(ReportRmeRefundSettlementOutcomeRequest $request, int $settlement): RedirectResponse
    {
        return $this->guarded(function () use ($request, $settlement) {
            $record = $this->findVisibleOr404($settlement, $request);
            $this->settlements->reportUnknown($record, $request->user(), $request->validated());

            return redirect()->route('rme.refund-settlements.show', $record->id)
                ->with('success', 'Hasil pembayaran dicatat TIDAK PASTI. Jangan membayar ulang: rekonsiliasi dengan bukti bank terlebih dahulu.');
        });
    }

    public function abort(AbortRmeRefundSettlementRequest $request, int $settlement): RedirectResponse
    {
        return $this->guarded(function () use ($request, $settlement) {
            $record = $this->findVisibleOr404($settlement, $request);
            $evidence = $request->file('evidence');
            $this->settlements->abort($record, $request->user(), $request->validated(), is_array($evidence) ? null : $evidence);

            return redirect()->route('rme.refund-settlements.show', $record->id)
                ->with('success', 'Pembayaran refund dibatalkan; tercatat tidak ada uang yang keluar. Permintaan refund tetap Disetujui.');
        });
    }

    public function evidence(Request $request, int $settlement): StreamedResponse
    {
        abort_unless($this->settlements->capabilityEnabled(), 404);
        $record = $this->findVisibleOr404($settlement, $request);

        try {
            $file = $this->settlements->evidenceFor($record, $request->user());
        } catch (RmeRefundSettlementRefused) {
            abort(404);
        }

        abort_unless(Storage::disk(RmeRefundSettlementEvidence::DISK)->exists($file['path']), 404);

        return Storage::disk(RmeRefundSettlementEvidence::DISK)->download($file['path'], $file['download_name'], [
            'Content-Type' => $file['mime'],
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** Missing and out of scope are the same answer: 404. */
    private function findVisibleOr404(int $id, Request $request): RmeRefundSettlement
    {
        $record = $this->settlements->find($id);
        abort_if($record === null || ! $this->settlements->mayView($request->user(), (int) $record->branch_id), 404);

        return $record;
    }

    /** @param  Closure(): RedirectResponse  $work */
    private function guarded(Closure $work): RedirectResponse
    {
        try {
            return $work();
        } catch (RmeRefundSettlementRefused $refused) {
            abort_if($refused->notFound, 404);

            return back()->withInput()->withErrors([$refused->field => $refused->displayMessage()]);
        }
    }
}
