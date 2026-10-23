<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use App\Modules\RmeInvoice\Support\RmeBillingDisputeOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rule 203 — resolve a billing dispute. Shape only; the evidence behind each
 * outcome (an applied correction producing the current version, or a second
 * account's determination against the unchanged invoice) is verified by the
 * service under the invoice row lock, never accepted from input. In particular
 * no correction id is accepted: the service finds the applied correction itself.
 */
class ResolveRmeBillingDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outcome' => ['required', 'string', Rule::in(RmeBillingDisputeOutcome::all())],
            'resolution_reason' => ['required', 'string', 'min:'.RmeBillingDisputeService::TEXT_MIN, 'max:'.RmeBillingDisputeService::TEXT_MAX],
            'resolution_reference' => [
                Rule::requiredIf(fn () => $this->input('outcome') === RmeBillingDisputeOutcome::ORIGINAL_CONFIRMED_CORRECT),
                'nullable',
                'string',
                'min:'.RmeBillingDisputeService::REFERENCE_MIN,
                'max:'.RmeBillingDisputeService::REFERENCE_MAX,
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'outcome' => 'hasil penyelesaian',
            'resolution_reason' => 'alasan penyelesaian',
            'resolution_reference' => 'referensi penyelesaian',
        ];
    }
}
