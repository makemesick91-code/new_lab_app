<?php

namespace App\Modules\LabOrder\Requests;

use App\Modules\LabOrder\Services\LabWorkflowRequestService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LAB-WORKFLOW-V2 — re-upload of branch-stage evidence on a DRAFT order.
 */
class UploadLabWorkflowEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission middleware + service branch/type guards
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(LabWorkflowRequestService::BRANCH_EVIDENCE_TYPES)],
            'file' => ['required', 'file', 'image', 'max:10240'],
        ];
    }
}
