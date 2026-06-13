<?php

namespace App\Modules\ClinicVisit\Requests;

use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClinicVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Default to selecting an existing patient so legacy callers (and tests)
        // that only send patient_id keep working unchanged.
        if (! $this->filled('patient_mode')) {
            $this->merge(['patient_mode' => 'existing']);
        }
    }

    public function rules(): array
    {
        $isNew = $this->input('patient_mode') === 'new';

        return [
            'patient_mode' => ['required', 'in:existing,new'],

            // RME "Klinik" = "Cabang RME": the visit branch is chosen from active
            // RME-enabled branches (Sprint 23 Phase 23.9.1). Optional so legacy
            // callers without an explicit branch fall back to the active context.
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('mst_branches', 'id')->where('is_active', true)->where('is_rme_enabled', true),
            ],

            // Legacy clinic master reference — no longer the RME "Klinik" source.
            // Kept nullable for backward compatibility; new RME visits omit it.
            'clinic_id' => ['nullable', 'integer', Rule::exists('mst_clinics', 'id')],
            'doctor_id' => ['required', 'integer', Rule::exists('mst_doctors', 'id')],
            'clinic_room_id' => ['nullable', 'integer', Rule::exists('mst_clinic_rooms', 'id')],
            'chief_complaint' => ['nullable', 'string', 'max:5000'],
            'initial_treatment_id' => ['required', 'integer', Rule::exists('mst_treatments', 'id')->where('is_active', true)],
            'initial_service_note' => ['nullable', 'string', 'max:2000'],

            // Existing patient mode.
            'patient_id' => [Rule::requiredIf(! $isNew), 'nullable', 'integer', Rule::exists('mst_patients', 'id')],

            // New patient mode — compose the finalized RM number.
            'new_patient' => [Rule::requiredIf($isNew), 'array'],
            'new_patient.name' => [Rule::requiredIf($isNew), 'nullable', 'string', 'max:150'],
            'new_patient.branch_id' => [
                Rule::requiredIf($isNew),
                'nullable',
                'integer',
                Rule::exists('mst_branches', 'id')->where('is_active', true)->where('is_rme_enabled', true),
            ],
            'new_patient.registered_at' => ['nullable', 'date'],
            'new_patient.manual_rm_number' => [Rule::requiredIf($isNew), 'nullable', 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'new_patient.gender' => ['nullable', 'string', 'in:Male,Female,Other'],
            'new_patient.date_of_birth' => ['nullable', 'date'],
            'new_patient.phone' => ['nullable', 'string', 'max:50'],
            'new_patient.address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_patient.manual_rm_number.regex' => 'Nomor RM manual hanya boleh berisi angka.',
            'new_patient.name.required' => 'Nama pasien baru wajib diisi.',
            'new_patient.branch_id.required' => 'Cabang RME pasien baru wajib dipilih.',
            'branch_id.exists' => 'Klinik/Cabang yang dipilih harus cabang RME aktif.',
            'new_patient.manual_rm_number.required' => 'Nomor RM manual pasien baru wajib diisi.',
            'patient_id.required' => 'Pilih pasien terdaftar.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('patient_mode') !== 'new' || $validator->errors()->isNotEmpty()) {
                return;
            }

            $branchId = $this->input('new_patient.branch_id');
            $manual = trim((string) $this->input('new_patient.manual_rm_number', ''));

            if (! $branchId || $manual === '') {
                return;
            }

            $branch = app(BranchRepositoryInterface::class)->findById((int) $branchId);

            if (! $branch) {
                return;
            }

            $rmNumbers = app(PatientMedicalRecordNumberService::class);
            $registeredAt = $this->input('new_patient.registered_at')
                ? Carbon::parse($this->input('new_patient.registered_at'))
                : Carbon::today();

            $composed = $rmNumbers->composeForRegistration($branch->code, $registeredAt, $manual);

            if ($rmNumbers->exists($composed)) {
                $validator->errors()->add('new_patient.manual_rm_number', "Nomor RM final {$composed} sudah digunakan.");
            }
        });
    }
}
