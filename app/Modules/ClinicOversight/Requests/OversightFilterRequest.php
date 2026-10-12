<?php

namespace App\Modules\ClinicOversight\Requests;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Support\UnifiedMedicalRecordSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — the filter SHAPE of every
 * oversight list, dashboard and export (GET query string).
 *
 * Validation is shape only. Authorization is the route permission plus the
 * ClinicOversightScope: in particular `branch_id` is NEVER authority — the
 * scope intersects it with the server-resolved branch set, so a forged id
 * yields an empty result, not a wider one.
 */
class OversightFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'room_id' => ['nullable', 'integer', 'min:1'],
            'treatment_id' => ['nullable', 'regex:/^(\d+|unclassified)$/'],
            'status' => ['nullable', 'string', Rule::in(array_merge(ClinicVisit::STATUSES, MedicalRecord::STATUSES))],
            'search' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', Rule::in(UnifiedMedicalRecordSource::ALLOWED)],
            'visit_date_from' => ['nullable', 'date_format:Y-m-d'],
            'visit_date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:visit_date_from'],
            'visit_number' => ['nullable', 'string', 'max:40'],
            'medical_record_number' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The validated filters, with empty strings dropped.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->validated(),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
