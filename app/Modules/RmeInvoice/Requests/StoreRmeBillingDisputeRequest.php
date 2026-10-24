<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\RmeInvoice\Models\RmeBillingDispute;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rule 203 — report an incorrect invoice. Authorized BEFORE validation, and an
 * invoice outside the caller's branch scope is a 404 (never a validation error
 * that would confirm it exists). The service re-validates every value and
 * re-authorizes under the invoice row lock; nothing here is the authority.
 *
 * Only `reason_category` and `description` are accepted: branch, invoice,
 * status and totals are read from the locked invoice row, never from input.
 */
class StoreRmeBillingDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('rmeInvoice');
        $visit = $this->route('clinicVisit');

        abort_unless($invoice instanceof RmeInvoice && $visit instanceof ClinicVisit && $invoice->belongsToVisit($visit), 404);
        abort_unless($this->user()?->can('create', [RmeBillingDispute::class, $invoice]), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'reason_category' => ['required', 'string', Rule::in(RmeBillingDisputeReason::all())],
            'description' => ['required', 'string', 'min:'.RmeBillingDisputeService::TEXT_MIN, 'max:'.RmeBillingDisputeService::TEXT_MAX],
        ];
    }

    public function attributes(): array
    {
        return [
            'reason_category' => 'jenis ketidaksesuaian',
            'description' => 'keterangan',
        ];
    }
}
