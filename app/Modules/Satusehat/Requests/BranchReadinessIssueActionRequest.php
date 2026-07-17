<?php

namespace App\Modules\Satusehat\Requests;

use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * SATUSEHAT-4C — issue assignment / escalation / review input.
 *
 * Authorization is enforced in the controller via the branch pilot policy +
 * branch-scoped issue lookup; this request only validates shape. No PII fields.
 */
class BranchReadinessIssueActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assigned_to' => ['sometimes', 'integer', 'exists:users,id'],
            'priority' => ['nullable', Rule::in(SatusehatDataQualityIssue::PRIORITIES)],
            'assigned_role' => ['nullable', 'string', 'max:60'],
            'escalation_level' => ['nullable', Rule::in(SatusehatDataQualityIssue::ESCALATION_LEVELS)],
            'resolution_evidence' => ['nullable', 'string', 'max:500'],
        ];
    }
}
