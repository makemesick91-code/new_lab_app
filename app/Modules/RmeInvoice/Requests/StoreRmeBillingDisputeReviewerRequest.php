<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Support\RmeBillingDisputeText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rule 204 — designate a branch billing dispute reviewer. SHAPE ONLY: the route
 * middleware requires the governance permission, and RmeBillingDisputeReviewerService
 * decides everything else on the persisted accounts (usable governor, no
 * self-designation, not the Owner, not redundant, active RME branch, one active
 * designation per account and branch).
 */
class StoreRmeBillingDisputeReviewerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'branch_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:'.RmeBillingDisputeText::MIN, 'max:'.RmeBillingDisputeText::MAX],
        ];
    }

    public function attributes(): array
    {
        return ['user_id' => 'akun', 'branch_id' => 'cabang', 'reason' => 'alasan penunjukan'];
    }
}
