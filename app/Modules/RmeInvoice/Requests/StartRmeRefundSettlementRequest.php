<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Services\RmeRefundSettlementService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — Start a payout claim. Any branch, actor or approver field is ignored. SHAPE only: every rule
 * (authority, branch, settler ≠ approver, amount, evidence content, references)
 * is decided by RmeRefundSettlementService. While the capability is off the
 * request answers 404 before anything is validated.
 */
class StartRmeRefundSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(RmeRefundSettlementService::class)->capabilityEnabled(), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'settlement_intent' => ['nullable', 'string', 'max:200'],
            'method' => ['nullable', 'string', 'max:30'],
            'amount' => ['nullable', 'string', 'max:30'],
        ];
    }
}
