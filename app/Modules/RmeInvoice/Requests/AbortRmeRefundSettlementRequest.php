<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Services\RmeRefundSettlementService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — Record that no money left. SHAPE only: every rule
 * (authority, branch, settler ≠ approver, amount, evidence content, references)
 * is decided by RmeRefundSettlementService. While the capability is off the
 * request answers 404 before anything is validated.
 */
class AbortRmeRefundSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(RmeRefundSettlementService::class)->capabilityEnabled(), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'abort_reason' => ['nullable', 'string', 'max:2000'],
            'attest_not_disbursed' => ['nullable'],
            'evidence' => ['nullable', 'file'],
        ];
    }
}
