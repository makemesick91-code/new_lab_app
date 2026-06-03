<?php

namespace App\Modules\Reporting\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base report filter request. Authorization is enforced by route policy/gate.
 */
abstract class ReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Shared filter rules (date range + clinic).
     *
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'clinic_id' => ['nullable', 'integer', 'exists:mst_clinics,id'],
        ];
    }

    /**
     * Normalized filter array for services/repository.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter($this->validated(), static fn ($v) => $v !== null && $v !== '');
    }
}
