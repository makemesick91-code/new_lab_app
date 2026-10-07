<?php

namespace App\Modules\PatientMerge\Requests;

use App\Modules\PatientMerge\Models\PatientMergeCase;
use Illuminate\Foundation\Http\FormRequest;

/** Shape only. Scope, merged state and open-case rules live in the service. */
class StorePatientMergeCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PatientMergeCase::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'patient_a_id' => ['required', 'integer', 'min:1'],
            'patient_b_id' => ['required', 'integer', 'min:1', 'different:patient_a_id'],
            'request_reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'patient_b_id.different' => 'Pasien A dan Pasien B tidak boleh pasien yang sama.',
            'request_reason.min' => 'Alasan pengajuan minimal 10 karakter.',
        ];
    }
}
