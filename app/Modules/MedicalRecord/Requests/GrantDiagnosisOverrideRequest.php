<?php

namespace App\Modules\MedicalRecord\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** SATUSEHAT-4B — reasoned emergency override of the diagnosis requirement. */
class GrantDiagnosisOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // override_diagnosis_requirement middleware + policy re-check in controller
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
