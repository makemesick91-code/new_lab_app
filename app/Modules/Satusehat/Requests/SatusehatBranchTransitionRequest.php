<?php

namespace App\Modules\Satusehat\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SATUSEHAT-4D — branch transition + bulk issue governance inputs. Route
 * `permission:` middleware + server-side branch scope are the authorization
 * boundary; the service enforces gates (internal_ready, known trigger, reason).
 */
class SatusehatBranchTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'string', 'max:500'],
            'trigger' => ['nullable', 'string', 'max:60'],
            // bulk issue assignment
            'issue_ids' => ['sometimes', 'array', 'max:100'],
            'issue_ids.*' => ['integer'],
            'assignee_id' => ['sometimes', 'integer'],
            'priority' => ['nullable', 'string', 'max:20'],
            'assigned_role' => ['nullable', 'string', 'max:60'],
        ];
    }
}
