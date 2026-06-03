<?php

namespace App\Modules\Reporting\Requests;

use App\Modules\Invoice\Models\Invoice;
use Illuminate\Validation\Rule;

class InvoiceReportRequest extends ReportFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'invoice_status' => ['nullable', Rule::in(Invoice::STATUSES)],
            'include_void' => ['nullable', 'boolean'],
        ]);
    }
}
