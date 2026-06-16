<?php

namespace App\Modules\ClinicVisit\Requests;

use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
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

        if (! $this->filled('visit_type')) {
            $this->merge(['visit_type' => ClinicVisit::VISIT_TYPE_NEW]);
        }

        if ($this->has('new_patient.ktp_number')) {
            $ktp = trim((string) $this->input('new_patient.ktp_number'));

            $this->merge([
                'new_patient' => array_merge($this->input('new_patient', []), [
                    'ktp_number' => $ktp === '' ? null : $ktp,
                ]),
            ]);
        }
    }

    public function rules(): array
    {
        $isNew = $this->input('patient_mode') === 'new';

        return [
            'patient_mode' => ['required', 'in:existing,new'],

            // RME "Klinik" = "Cabang RME": required for existing-patient visits so
            // creation never falls back to BranchContext/MAIN (Sprint 23 Phase 23.10).
            'branch_id' => [
                Rule::requiredIf(! $isNew),
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

            'visit_type' => ['required', 'string', Rule::in(ClinicVisit::VISIT_TYPES)],
            'follow_up_of_visit_id' => ['nullable', 'integer', Rule::exists('trx_clinic_visits', 'id')],

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
            'new_patient.whatsapp_number' => ['nullable', 'string', 'max:50'],
            'new_patient.ktp_number' => ['nullable', 'string', 'max:16', Rule::unique('mst_patients', 'ktp_number')],
            'new_patient.email' => ['nullable', 'email', 'max:150'],
            'new_patient.occupation' => ['nullable', 'string', 'max:150'],
            'new_patient.address' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_patient.manual_rm_number.regex' => 'Nomor RM manual hanya boleh berisi angka.',
            'new_patient.name.required' => 'Nama pasien baru wajib diisi.',
            'new_patient.branch_id.required' => 'Cabang RME pasien baru wajib dipilih.',
            'new_patient.branch_id.same' => 'Cabang RME pasien baru harus sama dengan Klinik/Cabang kunjungan.',
            'branch_id.required' => 'Cabang RME wajib dipilih untuk kunjungan pasien terdaftar.',
            'branch_id.exists' => 'Klinik/Cabang yang dipilih harus cabang RME aktif.',
            'new_patient.manual_rm_number.required' => 'Nomor RM manual pasien baru wajib diisi.',
            'new_patient.ktp_number.unique' => 'Nomor KTP sudah terdaftar pada pasien lain.',
            'patient_id.required' => 'Pilih pasien terdaftar.',
            'visit_type.in' => 'Jenis kunjungan tidak valid.',
            'follow_up_of_visit_id.required' => 'Kunjungan sebelumnya wajib dipilih untuk kunjungan kontrol atau lanjutan tindakan.',
            'follow_up_of_visit_id.exists' => 'Kunjungan sebelumnya tidak ditemukan.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateNewPatientRm($validator);
            $this->validateFollowUpVisit($validator);
        });
    }

    private function validateNewPatientRm(Validator $validator): void
    {
        if ($this->input('patient_mode') !== 'new' || $validator->errors()->isNotEmpty()) {
            return;
        }

        $visitType = $this->input('visit_type', ClinicVisit::VISIT_TYPE_NEW);
        if (in_array($visitType, ClinicVisit::FOLLOW_UP_VISIT_TYPES, true)) {
            $validator->errors()->add(
                'visit_type',
                'Kunjungan kontrol hanya dapat dibuat untuk pasien terdaftar.'
            );

            return;
        }

        $visitBranchId = $this->input('branch_id');
        $newPatientBranchId = $this->input('new_patient.branch_id');

        if ($visitBranchId && $newPatientBranchId && (int) $visitBranchId !== (int) $newPatientBranchId) {
            $validator->errors()->add(
                'new_patient.branch_id',
                'Cabang RME pasien baru harus sama dengan Klinik/Cabang kunjungan.'
            );

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
    }

    private function validateFollowUpVisit(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $visitType = $this->input('visit_type', ClinicVisit::VISIT_TYPE_NEW);
        $followUpId = $this->input('follow_up_of_visit_id');
        $patientId = $this->input('patient_id');
        $isNewPatient = $this->input('patient_mode') === 'new';

        if (in_array($visitType, ClinicVisit::FOLLOW_UP_VISIT_TYPES, true)) {
            if ($isNewPatient) {
                $validator->errors()->add(
                    'visit_type',
                    'Kunjungan kontrol hanya dapat dibuat untuk pasien terdaftar.'
                );

                return;
            }

            if (! $followUpId) {
                $validator->errors()->add('follow_up_of_visit_id', 'Kunjungan sebelumnya wajib dipilih untuk kunjungan kontrol atau lanjutan tindakan.');

                return;
            }

            if (! $patientId) {
                $validator->errors()->add('patient_id', 'Pilih pasien terdaftar.');

                return;
            }

            $rmeBranchIds = app(BranchService::class)->rmeEnabledIds();
            $parentVisit = ClinicVisit::query()
                ->whereIn('branch_id', $rmeBranchIds)
                ->find((int) $followUpId);

            if ($parentVisit === null) {
                $validator->errors()->add('follow_up_of_visit_id', 'Kunjungan sebelumnya harus berasal dari cabang RME yang valid.');

                return;
            }

            if ((int) $parentVisit->patient_id !== (int) $patientId) {
                $validator->errors()->add('follow_up_of_visit_id', 'Kunjungan sebelumnya harus milik pasien yang sama.');

                return;
            }

            return;
        }

        if ($followUpId && in_array($visitType, [ClinicVisit::VISIT_TYPE_NEW, ClinicVisit::VISIT_TYPE_EMERGENCY], true)) {
            // follow_up_of_visit_id is optional and ignored for new/emergency — no error.
        }
    }
}
