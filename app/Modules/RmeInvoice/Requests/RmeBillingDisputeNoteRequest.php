<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Services\RmeBillingDisputeService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rule 203 — the note of a review start (optional), an escalation (required) or
 * a follow-up note (required). Shape only: the controller resolves the dispute
 * within the caller's scope (404 otherwise) and authorizes the ability, and the
 * service re-validates the text and re-authorizes under the invoice row lock.
 */
class RmeBillingDisputeNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->routeIs('rme.billing-disputes.review') ? 'nullable' : 'required';

        return [
            'note' => [$presence, 'string', 'min:'.RmeBillingDisputeService::TEXT_MIN, 'max:'.RmeBillingDisputeService::TEXT_MAX],
        ];
    }

    public function attributes(): array
    {
        return ['note' => 'catatan'];
    }
}
