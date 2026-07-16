<?php

namespace App\Modules\Satusehat\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSatusehatMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage_satusehat_mappings') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'environment' => ['required', Rule::in((array) config('satusehat.allowed_environments', ['sandbox', 'production']))],
            'local_entity_type' => ['required', 'string', 'max:60'],
            'local_entity_id' => ['nullable', 'integer'],
            'local_code' => ['nullable', 'string', 'max:100'],
            'target_resource_type' => ['required', Rule::in(['Encounter', 'Condition', 'Procedure', 'Observation', 'Medication', 'Patient', 'Practitioner', 'Organization', 'Location'])],
            'target_path' => ['nullable', 'string', 'max:191'],
            'terminology_system' => ['nullable', 'string', 'max:191'],
            'target_code' => ['nullable', 'string', 'max:100'],
            'target_display' => ['nullable', 'string', 'max:191'],
            'effective_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (blank($this->input('local_entity_id')) && blank($this->input('local_code'))) {
                $validator->errors()->add('local_code', 'Isi salah satu: ID entitas lokal atau kode lokal.');
            }
        });
    }
}
