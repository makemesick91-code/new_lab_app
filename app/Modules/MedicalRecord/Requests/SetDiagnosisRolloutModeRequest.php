<?php

namespace App\Modules\MedicalRecord\Requests;

use App\Modules\MedicalRecord\Models\DiagnosisRolloutSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** SATUSEHAT-4B — set one branch's rollout mode (reasoned + audited). */
class SetDiagnosisRolloutModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // configure_diagnosis_rollout middleware gates the route
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(DiagnosisRolloutSetting::MODES)],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
