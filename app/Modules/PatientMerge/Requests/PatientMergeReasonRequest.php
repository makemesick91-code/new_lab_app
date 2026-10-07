<?php

namespace App\Modules\PatientMerge\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A written reason (cancel, reject, reversal) — 10..2000 characters, the same
 * floor the service re-asserts. Authorization is the controller's policy
 * check for the specific action.
 */
class PatientMergeReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['reason.min' => 'Alasan minimal 10 karakter.', 'reason.required' => 'Alasan wajib diisi.'];
    }
}
