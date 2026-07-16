<?php

namespace App\Modules\MedicalRecord\Requests;

use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMedicalRecordDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // MedicalRecordPolicy::update is enforced in the controller
    }

    public function rules(): array
    {
        return [
            'clinical_diagnosis_id' => ['required', 'integer', 'exists:mst_clinical_diagnoses,id'],
            'diagnosis_role' => ['required', 'string', Rule::in(MedicalRecordDiagnosis::ROLES)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
