<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Support\RmeBillingDisputeText;
use Illuminate\Foundation\Http\FormRequest;

/** Rule 204 — revoke a branch reviewer designation. Shape only; the service decides. */
class RevokeRmeBillingDisputeReviewerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.RmeBillingDisputeText::MIN, 'max:'.RmeBillingDisputeText::MAX],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'alasan pencabutan'];
    }
}
