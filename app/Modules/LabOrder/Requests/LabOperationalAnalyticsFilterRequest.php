<?php

namespace App\Modules\LabOrder\Requests;

use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LAB-PROD-2 — Operational Analytics filter request.
 *
 * Read-only report filters. Authorization is enforced by the route middleware
 * (permission tier) + the service scope resolver; the branch/technician ids are
 * re-validated server-side and never trusted from here. The custom range max
 * length is enforced by the service (clamped), this request only validates
 * shape + safe bounds.
 */
class LabOperationalAnalyticsFilterRequest extends FormRequest
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
        $maxDays = (int) config('lab_operational_analytics.max_custom_range_days', 366);

        return [
            'period' => ['nullable', Rule::in(config('lab_operational_analytics.periods', []))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'before_or_equal:'.now()->addDay()->toDateString()],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'lab_service_id' => ['nullable', 'integer', 'min:1'],
            'technician_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(LabWorkflowState::all())],
            'sourcing' => ['nullable', Rule::in(['internal', 'external'])],
            'sla_state' => ['nullable', Rule::in(['on_time', 'late', 'overdue', 'eligible'])],
        ];
    }

    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'Tanggal akhir tidak boleh sebelum tanggal awal.',
        ];
    }

    /**
     * Normalised filter array consumed by the analytics service.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return [
            'period' => $this->validated('period'),
            'from' => $this->validated('from'),
            'to' => $this->validated('to'),
            'lab_service_id' => $this->validated('lab_service_id'),
            'status' => $this->validated('status'),
            'sourcing' => $this->validated('sourcing'),
            'sla_state' => $this->validated('sla_state'),
        ];
    }
}
