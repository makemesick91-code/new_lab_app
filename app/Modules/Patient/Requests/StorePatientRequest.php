<?php

namespace App\Modules\Patient\Requests;

use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
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
            'clinic_id' => ['required', 'integer', 'exists:mst_clinics,id'],
            'doctor_id' => ['required', 'integer', 'exists:mst_doctors,id'],
            'branch_id' => [
                'nullable',
                'required_with:manual_rm_number',
                'integer',
                Rule::exists('mst_branches', 'id')->where('is_active', true)->where('is_rme_enabled', true),
            ],
            'registered_at' => ['nullable', 'date'],
            'manual_rm_number' => ['nullable', 'required_with:branch_id', 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'medical_record_number' => ['nullable', 'string', 'max:50', 'unique:mst_patients,medical_record_number'],
            'name' => ['required', 'string', 'max:150'],
            'gender' => ['nullable', 'string', 'in:Male,Female,Other'],
            'date_of_birth' => ['nullable', 'date'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'manual_rm_number.regex' => 'Nomor RM manual hanya boleh berisi angka.',
            'branch_id.required_with' => 'Cabang RME wajib dipilih saat mengisi Nomor RM manual.',
            'manual_rm_number.required_with' => 'Nomor RM manual wajib diisi saat memilih Cabang RME.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateComposedNumberUniqueness($validator);
        });
    }

    /**
     * When the final medical record number will be composed from
     * branch + year + manual number, ensure it is globally unique.
     */
    private function validateComposedNumberUniqueness(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $explicit = trim((string) $this->input('medical_record_number', ''));
        $branchId = $this->input('branch_id');
        $manual = trim((string) $this->input('manual_rm_number', ''));

        if ($explicit !== '' || ! $branchId || $manual === '') {
            return;
        }

        $branch = app(BranchRepositoryInterface::class)->findById((int) $branchId);

        if (! $branch) {
            return;
        }

        $rmNumbers = app(PatientMedicalRecordNumberService::class);
        $registeredAt = $this->input('registered_at') ? Carbon::parse($this->input('registered_at')) : Carbon::today();

        $composed = $rmNumbers->composeForRegistration($branch->code, $registeredAt, $manual);

        if ($rmNumbers->exists($composed)) {
            $validator->errors()->add('manual_rm_number', "Nomor RM final {$composed} sudah digunakan.");
        }
    }
}
