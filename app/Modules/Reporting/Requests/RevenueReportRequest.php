<?php

namespace App\Modules\Reporting\Requests;

class RevenueReportRequest extends ReportFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->baseRules();
    }
}
