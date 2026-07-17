<?php

namespace App\Modules\Satusehat\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SATUSEHAT-4D — rollout wave write inputs. Route `permission:` middleware is
 * the authorization boundary; the service layer performs strict domain
 * validation (name uniqueness, single-active-wave, RME-branch, reason length).
 */
class SatusehatRolloutWaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'sequence' => ['sometimes', 'integer', 'min:1', 'max:9999'],
            'scope' => ['nullable', 'string', 'max:500'],
            'target_date' => ['nullable', 'date'],
            'operational_owner_id' => ['nullable', 'integer'],
            'clinical_owner_id' => ['nullable', 'integer'],
            'technical_owner_id' => ['nullable', 'integer'],
            'branch_id' => ['sometimes', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'string', 'max:40'],
        ];
    }
}
