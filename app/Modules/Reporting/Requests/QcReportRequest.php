<?php

namespace App\Modules\Reporting\Requests;

use App\Modules\QualityControl\Models\QualityControl;
use Illuminate\Validation\Rule;

class QcReportRequest extends ReportFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'qc_status' => ['nullable', Rule::in(QualityControl::RESULTS)],
            'technician_id' => ['nullable', 'integer', 'exists:mst_technicians,id'],
        ]);
    }
}
