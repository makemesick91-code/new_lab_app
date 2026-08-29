<?php

namespace App\Modules\ClinicVisit\Requests;

use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use App\Modules\Patient\Services\PatientSelectorSearchService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\Clinical\ClinicalClock;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

        $this->applyAdminClinicBranchContext();
        $this->applyAdminClinicDoctorContext();
        $this->clearExistingPatientForNewRegistration();
    }

    /**
     * REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — registering a brand-new
     * patient can never carry a previously selected existing patient.
     *
     * The combobox already clears itself when the operator switches to "Pasien
     * Baru", but a hidden panel still submits its fields, so the server drops the
     * value rather than trusting the browser to have done it.
     */
    private function clearExistingPatientForNewRegistration(): void
    {
        if ($this->input('patient_mode') === 'new') {
            $this->merge(['patient_id' => null]);
        }
    }

    /**
     * Sprint 66.1.4 — Admin Klinik does not pick a doctor at registration.
     * doctor_id is resolved when a treatment room is assigned from the queue.
     */
    private function applyAdminClinicDoctorContext(): void
    {
        if (! $this->isAdminClinicRegistration()) {
            return;
        }

        $this->merge(['doctor_id' => null]);
    }

    private function isAdminClinicRegistration(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return app(UserOnlineContextService::class)->resolveActiveBranchForAdmin($user) !== null;
    }

    /**
     * Sprint 66.1.3 — Admin Klinik visits always use the active online context
     * branch. Never trust branch_id from the form for this role.
     */
    private function applyAdminClinicBranchContext(): void
    {
        $user = $this->user();

        if ($user === null) {
            return;
        }

        $adminBranchId = app(UserOnlineContextService::class)
            ->resolveActiveBranchForAdmin($user);

        if ($adminBranchId === null) {
            return;
        }

        if ($this->input('patient_mode') === 'new') {
            $this->merge([
                'new_patient' => array_merge($this->input('new_patient', []), [
                    'branch_id' => $adminBranchId,
                ]),
                'branch_id' => $adminBranchId,
            ]);

            return;
        }

        $this->merge(['branch_id' => $adminBranchId]);
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
            'doctor_id' => [
                Rule::requiredIf(! $this->isAdminClinicRegistration()),
                'nullable',
                'integer',
                Rule::exists('mst_doctors', 'id'),
            ],
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

            // Sprint 61.1.1 — optional temp token for a KTP scan captured in the
            // RME "Pasien Baru" panel; promoted to a PatientDocument after the
            // patient is created. Missing/expired token never blocks creation.
            'ktp_scan_token' => ['nullable', 'string', 'max:64'],
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
            $this->validateExistingPatientSelectable($validator);
            $this->validateFollowUpVisit($validator);
            $this->validateOnlineDoctor($validator);
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
            // FIX-03 — closes the residual recorded in
            // docs/sprints/fix-clinic-ops-branch-context-wa-1.md: this fallback
            // composed a new patient's RM year from the UTC day while the visit
            // beside it already used ClinicalClock.
            : app(ClinicalClock::class)->today();

        $composed = $rmNumbers->composeForRegistration($branch->code, $registeredAt, $manual);

        if ($rmNumbers->exists($composed)) {
            $validator->errors()->add('new_patient.manual_rm_number', "Nomor RM final {$composed} sudah digunakan.");
        }
    }

    /**
     * REVISION-NEW-VISIT-PATIENT-SEARCH-COMBOBOX-1 — the server-side patient
     * boundary for visit creation.
     *
     * `Rule::exists` alone only proves the row is real, not that this operator
     * may register a visit for it. Being offered in the combobox is a UI
     * convenience; this is the authorization, so a hand-crafted `patient_id`
     * belonging to another working branch is rejected even though the dropdown
     * would never have shown it. The scope is the very same
     * {@see PatientSelectorSearchService} the search uses, so what is selectable
     * and what is submittable can never drift apart.
     */
    private function validateExistingPatientSelectable(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty() || $this->input('patient_mode') === 'new') {
            return;
        }

        $patientId = $this->input('patient_id');

        if (! $patientId) {
            return;
        }

        $selectable = app(PatientSelectorSearchService::class)
            ->isSelectable($this->user(), (int) $patientId);

        if (! $selectable) {
            $validator->errors()->add(
                'patient_id',
                'Pasien yang dipilih tidak tersedia pada cabang kerja Anda.'
            );
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

    private function validateOnlineDoctor(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty() || $this->isAdminClinicRegistration()) {
            return;
        }

        $doctorId = $this->input('doctor_id');

        if (! $doctorId) {
            return;
        }

        $branchId = ($this->input('patient_mode') === 'new')
            ? ($this->input('new_patient.branch_id') ?? $this->input('branch_id'))
            : $this->input('branch_id');

        if (! $branchId) {
            return;
        }

        try {
            app(UserOnlineContextService::class)->assertDoctorSelectableForVisit(
                (int) $doctorId,
                (int) $branchId,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }
    }
}
