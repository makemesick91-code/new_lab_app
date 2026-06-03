<?php

namespace App\Modules\Reporting\Requests;

use App\Modules\Delivery\Models\Delivery;
use Illuminate\Validation\Rule;

class DeliveryReportRequest extends ReportFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'courier_id' => ['nullable', 'integer', 'exists:users,id'],
            'delivery_status' => ['nullable', Rule::in(Delivery::STATUSES)],
        ]);
    }
}
