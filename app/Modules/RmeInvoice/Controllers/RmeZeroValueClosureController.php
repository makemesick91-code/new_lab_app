<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Requests\CloseRmeZeroValueInvoiceRequest;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeInvoice\Support\RmeZeroValueClosureFeatureGuard;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/**
 * FEATURE-RME-RP0-CONTROL-CONSULTATION-1 (rule 217, Sprint 6/7) — thin: 404 while
 * the flag is off or the URL pairs the invoice with another visit, authorize the
 * invoice, delegate to RmePaymentService::closeZeroValueInvoice(). Every rule —
 * eligibility, holds, branch, account — lives in the service and is decided under
 * the invoice row lock; a refusal comes back as a validation error.
 */
class RmeZeroValueClosureController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RmePaymentService $payments,
        private readonly RmeZeroValueClosureFeatureGuard $flag,
    ) {}

    public function store(CloseRmeZeroValueInvoiceRequest $request, ClinicVisit $clinicVisit, RmeInvoice $rmeInvoice): RedirectResponse
    {
        abort_unless($this->flag->enabled(), 404);
        abort_unless($rmeInvoice->belongsToVisit($clinicVisit), 404);
        $this->authorize('closeZeroValue', $rmeInvoice);

        $this->payments->closeZeroValueInvoice($rmeInvoice, $request->user());

        return redirect()
            ->route('rme.cashier.show', [$clinicVisit, $rmeInvoice])
            ->with('status', 'Tagihan diselesaikan Tanpa Biaya. Tidak ada pembayaran yang dicatat; kunjungan selesai.');
    }
}
