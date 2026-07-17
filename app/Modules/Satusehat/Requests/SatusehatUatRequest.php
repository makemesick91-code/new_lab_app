<?php

namespace App\Modules\Satusehat\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SATUSEHAT-4D — human UAT write inputs (run / scenario / sign-off). Route
 * `permission:record_satusehat_uat_signoff` is the authorization boundary; the
 * service enforces role/outcome/decision enums and the sign-off completeness gate.
 * Evidence must be synthetic / PII-safe — never NIK or real patient data.
 */
class SatusehatUatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:200'],
            'rollout_wave_id' => ['nullable', 'integer'],
            'scheduled_at' => ['nullable', 'date'],
            // scenario
            'scenario_code' => ['sometimes', 'string', 'max:60'],
            'role' => ['sometimes', 'string', 'max:60'],
            'branch_id' => ['nullable', 'integer'],
            'precondition' => ['nullable', 'string', 'max:1000'],
            'steps' => ['nullable', 'string', 'max:2000'],
            'expected_result' => ['nullable', 'string', 'max:1000'],
            'actual_result' => ['nullable', 'string', 'max:1000'],
            'outcome' => ['nullable', 'string', 'max:20'],
            'finding_severity' => ['nullable', 'string', 'max:20'],
            'evidence_reference' => ['nullable', 'string', 'max:500'],
            'operator_name' => ['nullable', 'string', 'max:150'],
            'operator_role' => ['nullable', 'string', 'max:60'],
            // sign-off
            'decision' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
