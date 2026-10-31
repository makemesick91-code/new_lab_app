<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Services\RmeRefundRequestService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215) — approve, reject,
 * withdraw or revoke. SHAPE only; a reviewer supplies no reference (a reference
 * sent with this form is ignored — TDD RR-REF-15).
 */
class DecideRmeRefundRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(RmeRefundRequestService::class)->capabilityEnabled(), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'decision_note' => ['nullable', 'string', 'max:2000'],
            'cancel_reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
