<?php

namespace App\Modules\PatientMerge\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving runs the merge. The explicit confirmation is a courtesy against a
 * mis-click, never the control: the policy, the maker-checker rule and every
 * blocker are re-checked in the service.
 */
class ApprovePatientMergeCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review', $this->route('patientMergeCase')) ?? false;
    }

    public function rules(): array
    {
        return [
            'confirm_merge' => ['accepted'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['confirm_merge.accepted' => 'Centang konfirmasi bahwa preview telah ditinjau sebelum menyetujui penggabungan.'];
    }
}
