<?php

namespace App\Modules\MedicalRecord\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** SATUSEHAT-4B — deprecate terminology, optionally naming an ACTIVE replacement. */
class DeprecateClinicalDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // review_clinical_terminology middleware gates the route
    }

    public function rules(): array
    {
        return [
            'replacement_diagnosis_id' => ['nullable', 'integer', 'exists:mst_clinical_diagnoses,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
