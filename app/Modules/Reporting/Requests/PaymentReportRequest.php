<?php

namespace App\Modules\Reporting\Requests;

use App\Modules\Invoice\Models\Payment;
use Illuminate\Validation\Rule;

class PaymentReportRequest extends ReportFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'payment_method' => ['nullable', Rule::in(Payment::METHODS)],
            'received_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);
    }
}
