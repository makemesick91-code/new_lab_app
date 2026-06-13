<?php

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeReceivableFollowUp;
use App\Modules\RmeInvoice\Requests\StoreRmeReceivableFollowUpRequest;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

// Sprint 24 Phase 24.8 — RME Receivable Follow-up / Reminder Foundation.
// Tracking only — never mutates invoice/payment status.
class RmeReceivableFollowUpController extends Controller
{
    use AuthorizesRequests;

    public function create(RmeInvoice $rmeInvoice): View
    {
        $this->authorize('view', $rmeInvoice);
        $this->ensureActiveReceivable($rmeInvoice);

        return view('rme.cashier.follow-ups.create', [
            'invoice' => $rmeInvoice->load(['branch', 'patient', 'clinicVisit']),
            'followUps' => $rmeInvoice->followUps()->with('user')->latest()->get(),
            'statuses' => RmeReceivableFollowUp::STATUSES,
            'channels' => RmeReceivableFollowUp::CHANNELS,
        ]);
    }

    public function store(StoreRmeReceivableFollowUpRequest $request, RmeInvoice $rmeInvoice): RedirectResponse
    {
        $this->authorize('view', $rmeInvoice);
        $this->ensureActiveReceivable($rmeInvoice);

        $data = $request->validated();

        $rmeInvoice->followUps()->create([
            'branch_id' => $rmeInvoice->branch_id,
            'user_id' => $request->user()?->id,
            'status' => $data['status'],
            'channel' => $data['channel'] ?? null,
            'contacted_at' => $data['contacted_at'] ?? null,
            'next_follow_up_date' => $data['next_follow_up_date'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        return redirect()
            ->route('rme.cashier.receivables')
            ->with('status', 'Follow-up piutang berhasil dicatat.');
    }

    /** Follow-up actions are only allowed for active receivables (UNPAID/PARTIAL). */
    private function ensureActiveReceivable(RmeInvoice $invoice): void
    {
        abort_unless($invoice->isPayable(), 403, 'Follow-up hanya untuk piutang aktif (UNPAID/PARTIAL).');
    }
}
