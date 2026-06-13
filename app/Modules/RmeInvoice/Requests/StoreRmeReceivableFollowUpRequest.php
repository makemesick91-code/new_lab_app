<?php

namespace App\Modules\RmeInvoice\Requests;

use App\Modules\RmeInvoice\Models\RmeReceivableFollowUp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// Sprint 24 Phase 24.8 — RME Receivable Follow-up / Reminder Foundation.
class StoreRmeReceivableFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(RmeReceivableFollowUp::STATUSES)],
            'channel' => ['nullable', 'string', Rule::in(RmeReceivableFollowUp::CHANNELS)],
            'contacted_at' => ['nullable', 'date'],
            'next_follow_up_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
