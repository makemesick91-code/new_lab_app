<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Services\RmeRefundSettlementService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — Confirm a verified payout with its proof. SHAPE only: every rule
 * (authority, branch, settler ≠ approver, amount, evidence content, references)
 * is decided by RmeRefundSettlementService. While the capability is off the
 * request answers 404 before anything is validated.
 */
class ConfirmRmeRefundSettlementRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(RmeRefundSettlementService::class)->capabilityEnabled(), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'disbursement_reference' => ['nullable', 'string', 'max:200'],
            'destination_bank_name' => ['nullable', 'string', 'max:200'],
            'destination_account_last4' => ['nullable', 'string', 'max:20'],
            'recipient_relation' => ['nullable', 'string', 'max:30'],
            'attest_disbursed' => ['nullable'],
            'evidence' => ['nullable', 'file'],
        ];
    }
}
