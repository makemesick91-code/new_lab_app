<?php

namespace App\Modules\Satusehat\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SATUSEHAT-4C — internal pilot lifecycle action input (suspend reason,
 * rehearsal dry-run flag). Authorization is enforced in the controller via the
 * branch pilot policy. No PII fields.
 */
class InternalPilotActionRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:500'],
            'dry_run' => ['nullable', 'boolean'],
        ];
    }
}
