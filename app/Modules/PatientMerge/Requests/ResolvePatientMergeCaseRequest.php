<?php

namespace App\Modules\PatientMerge\Requests;

use App\Modules\PatientMerge\Support\PatientMergeField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape only. Whether a source is legal for a field (the side is empty, the
 * values do not match, a manual value needs a reason) is decided by the
 * service against the locked patient rows.
 */
class ResolvePatientMergeCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('patientMergeCase')) ?? false;
    }

    public function rules(): array
    {
        return [
            'canonical_patient_id' => ['nullable', 'integer', 'min:1'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['array'],
            'fields.*.source' => ['nullable', 'string', Rule::in(PatientMergeField::SOURCES)],
            'fields.*.manual_value' => ['nullable', 'string', 'max:1000'],
            'fields.*.reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, array{source?: ?string, manual_value?: mixed, reason?: ?string}> */
    public function fieldChoices(): array
    {
        $choices = [];

        foreach ((array) $this->validated('fields', []) as $field => $choice) {
            if (is_string($field) && array_key_exists($field, PatientMergeField::FIELDS) && is_array($choice)) {
                $choices[$field] = $choice;
            }
        }

        return $choices;
    }
}
