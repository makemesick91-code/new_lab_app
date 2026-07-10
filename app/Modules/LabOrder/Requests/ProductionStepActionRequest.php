<?php

namespace App\Modules\LabOrder\Requests;

use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** LAB-WORKFLOW-V2 — start/complete a V2 production step. */
class ProductionStepActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware + service ownership guards
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'step' => ['required', Rule::in(array_keys(LabWorkflowState::V2_PRODUCTION_STEPS))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
