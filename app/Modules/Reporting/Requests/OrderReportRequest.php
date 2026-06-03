<?php

namespace App\Modules\Reporting\Requests;

use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Validation\Rule;

class OrderReportRequest extends ReportFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'doctor_id' => ['nullable', 'integer', 'exists:mst_doctors,id'],
            'service_id' => ['nullable', 'integer', 'exists:mst_lab_services,id'],
            'status' => ['nullable', Rule::in(LabOrder::STATUSES)],
        ]);
    }
}
