<?php

namespace App\Modules\RmeInvoice\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * FIX-PRE-68-45 Scope C — validates the doctor performance report filters.
 * Authorization is enforced by the route permission middleware + the service's
 * access-tier resolution (a doctor is always forced to their own doctor_id).
 */
class DoctorPerformanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'exists:mst_branches,id'],
            'doctor_id' => ['nullable', 'integer', 'exists:mst_doctors,id'],
            'treatment_id' => ['nullable', 'integer', 'exists:mst_treatments,id'],
            'status' => ['nullable', 'string', 'in:PAID,PARTIAL,UNPAID'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'branch_id' => $this->input('branch_id'),
            'doctor_id' => $this->input('doctor_id'),
            'treatment_id' => $this->input('treatment_id'),
            'status' => $this->input('status'),
        ];
    }
}
