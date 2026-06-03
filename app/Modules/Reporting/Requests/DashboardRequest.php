<?php

namespace App\Modules\Reporting\Requests;

class DashboardRequest extends ReportFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->baseRules();
    }
}
