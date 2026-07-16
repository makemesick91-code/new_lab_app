<?php

namespace App\Modules\Satusehat\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk approve/exclude/prepare. The controller re-resolves eligibility and
 * authorization server-side per candidate — this request only shapes input.
 * There is intentionally no "select all across all pages" and no "send all".
 */
class BulkSatusehatSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny([
            'review_satusehat_submissions',
            'send_satusehat_submissions',
        ]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['approve', 'exclude', 'prepare'])],
            'candidate_ids' => ['required', 'array', 'min:1', 'max:200'],
            'candidate_ids.*' => ['integer'],
            'exclusion_reason' => ['nullable', 'required_if:action,exclude', 'string', 'min:3', 'max:1000'],
        ];
    }
}
