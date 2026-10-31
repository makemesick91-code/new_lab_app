<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeRefundRequestService;
use App\Modules\RmeInvoice\Support\RmeRefundRequestIntent;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — SHAPE only. Every
 * business rule (role, branch, pilot, eligibility, references) is decided by
 * RmeRefundRequestService, which answers with a stable code. Fields are kept
 * permissive here on purpose so the service — not a generic validator message —
 * names what is missing. No amount, branch or payment field is read.
 */
class StoreRmeRefundRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('rmeInvoice');
        $visit = $this->route('clinicVisit');
        abort_unless($invoice instanceof RmeInvoice && $visit instanceof ClinicVisit && $invoice->belongsToVisit($visit), 404);
        abort_unless(app(RmeRefundRequestService::class)->capabilityEnabled(), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            RmeRefundRequestIntent::FIELD => ['required', 'string', 'max:200'],
            'obligation_id' => ['required', 'integer', 'min:1'],
            'obligation_amount' => ['required', 'string', 'max:20'],
            'invoice_version' => ['required', 'integer', 'min:0'],
            'reason_note' => ['nullable', 'string', 'max:2000'],
            'patient_request_reference' => ['nullable', 'string', 'max:200'],
            'owner_approval_reference' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function attributes(): array
    {
        return [
            'reason_note' => 'alasan',
            'patient_request_reference' => 'referensi permintaan pasien',
            'owner_approval_reference' => 'referensi persetujuan Owner',
        ];
    }
}
