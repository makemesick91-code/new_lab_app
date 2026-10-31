<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Services\RmeRefundSettlementService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — Report an uncertain payout result. SHAPE only: every rule
 * (authority, branch, settler ≠ approver, amount, evidence content, references)
 * is decided by RmeRefundSettlementService. While the capability is off the
 * request answers 404 before anything is validated.
 */
class ReportRmeRefundSettlementOutcomeRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(RmeRefundSettlementService::class)->capabilityEnabled(), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'outcome_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
