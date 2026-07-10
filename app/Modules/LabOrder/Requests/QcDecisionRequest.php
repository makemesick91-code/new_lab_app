<?php

namespace App\Modules\LabOrder\Requests;

use App\Modules\LabOrder\Workflow\LabWorkflowState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LAB-WORKFLOW-V2 — QC decision. Used by both pass (notes optional) and fail
 * (reason + rework target mandatory; enforced again in the service).
 */
class QcDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware + segregation-of-duty in service
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $failing = $this->routeIs('lab-v2-orders.qc-fail');

        return [
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => [$failing ? 'required' : 'nullable', 'string', 'max:2000'],
            'target_step' => [
                $failing ? 'required' : 'nullable',
                Rule::in(LabWorkflowState::REWORK_TARGETS),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Alasan QC gagal wajib diisi.',
            'target_step.required' => 'Target step rework wajib dipilih.',
        ];
    }
}
