<?php

namespace App\Modules\Reporting\Requests;

use App\Modules\Production\Models\LabOrderAssignment;
use Illuminate\Validation\Rule;

class ProductionReportRequest extends ReportFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'technician_id' => ['nullable', 'integer', 'exists:mst_technicians,id'],
            'status' => ['nullable', Rule::in(LabOrderAssignment::STATUSES)],
        ]);
    }
}
