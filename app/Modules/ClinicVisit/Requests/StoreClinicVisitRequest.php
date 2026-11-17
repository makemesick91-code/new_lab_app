<?php

namespace App\Modules\ClinicVisit\Requests;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitRegistrationGuard;
use App\Modules\Patient\Services\PatientSelectorSearchService;
use App\Modules\Patient\Support\PatientCoreFields;
use App\Modules\PatientMerge\Services\PatientRegistrationDuplicateCheck;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Support\AccessControl\FrontOfficeBranchPinResolver;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StoreClinicVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 — a pinned front-desk
         * account with no resolvable working branch may not register a visit.
         *
         * WHY THIS IS HERE AND NOT LEFT TO THE MIDDLEWARE.
         *
         * When the working branch is null, `applyAdminClinicBranchContext()`
         * early-returns and the form's OWN `branch_id` survives, validating
         * against any RME-enabled branch. For a pinned account that is WIDER
         * than the stale-branch bug this sprint fixed: it would let the operator
         * name any branch rather than one.
         *
         * `EnsureRmeOnlineContext` does intercept first today, because
         * `hasSatisfiedContext()` is pin-aware. But that makes the route the
         * only thing standing between a crafted POST and the write, and this
         * module's own rule is that the refusal belongs where the write happens
         * — a FormRequest is reachable by any future route, and middleware
         * exemption lists grow.
         *
         * Narrowing only: `appliesTo()` is false for every account outside the
         * cohort and while both flags are off, so nobody else sees a change.
         */
        $user = $this->user();

        // DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.2 (rule 244 §5.3) — the
        // no-phone exception of a NEW patient is sent only by a holder of
        // `register_patient_without_phone` (the checkbox is rendered for holders
        // only); PatientService re-asserts it against the registrar.
        if ($this->hasNoPhoneInput() && ! $user?->can(PatientCoreFields::WITHOUT_PHONE_PERMISSION)) {
            return false;
        }

        if ($user === null) {
            return true;
        }

        $pin = app(FrontOfficeBranchPinResolver::class);

        if (! $pin->appliesTo($user)) {
            return true;
        }

        /*
         * `activeContextBranchId()`, NOT `resolveActiveBranchForAdmin()`.
         *
         * The latter answers for the admin_clinic and perawat contexts only, and
         * returns null for a DOCTOR context even when the working branch is
         * resolvable and equals the pin. A cohort account that also holds the
         * Doctor role, online as a doctor at its own pinned branch, would
         * otherwise be refused registration outright — a false 403, and a false
         * 403 on visit registration is a clinical-scale outage.
         *
         * `activeContextBranchId()` is the pin-narrowed, daily-lock-aware
         * chokepoint, so a non-null answer here already means "this operator has
         * a working branch AND it is the pinned one".
         */
        return app(UserOnlineContextService::class)->activeContextBranchId($user) !== null;
    }

    /** Rule 244 §5.3 — whether a new-patient registration carries a no-phone exception field. */
    public function hasNoPhoneInput(): bool
    {
        if ($this->input('patient_mode') !== 'new') {
            return false;
        }

        foreach (PatientCoreFields::NO_PHONE_FIELDS as $field) {
            if ($this->filled('new_patient.'.$field)) {
                return true;
            }
        }

        return false;
    }

    protected function prepareForValidation(): void
    {
        // Default to selecting an existing patient so legacy callers (and tests)
        // that only send patient_id keep working unchanged.
        if (! $this->filled('patient_mode')) {
            $this->merge(['patient_mode' => 'existing']);
        }

        // REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1 — the visit type is
        // SERVER-DERIVED, never chosen by the operator and never read from the
        // request. Owner decision: a visit is Kontrol only when a prior visit was
        // explicitly selected; otherwise it is Baru. Whatever `visit_type` the
        // client sent is overwritten here, so a hidden or tampered input cannot
        // classify a visit (and therefore cannot steer it into, or out of, the
        // control-chain billing path).
        //
        // The derivation is deliberately on PRESENCE, not on validity: an
        // explicitly selected prior visit that turns out to be invalid,
        // cross-patient or outside the RME branches still derives Kontrol, so
        // validateFollowUpVisit() REJECTS it rather than the request being
        // silently downgraded to Baru. Patient history alone never makes a
        // visit Kontrol.
        $this->merge([
            'visit_type' => $this->filled('follow_up_of_visit_id')
                ? ClinicVisit::VISIT_TYPE_CONTROL
                : ClinicVisit::VISIT_TYPE_NEW,
        ]);

        if ($this->has('new_patient.ktp_number')) {
            $ktp = trim((string) $this->input('new_patient.ktp_number'));

            $this->merge([
                'new_patient' => array_merge($this->input('new_patient', []), [
                    'ktp_number' => $ktp === '' ? null : $ktp,
                ]),
            ]);
        }

        // BEFORE the admin-clinic merge: that helper answers for two role
        // contexts only and early-returns for a doctor context, leaving the
        // form's own branch_id intact. For a pinned account that is the last
        // place a branch could come from the request.
        $this->applyFrontOfficePinnedBranchContext();
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

    /**
     * REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 — a pinned account registers at
     * its working branch, never at one the form names.
     *
     * The value is `activeContextBranchId()` and deliberately NOT
     * `requiredBranchIdFor()`. The chokepoint applies the daily-branch-lock
     * override BEFORE the pin, so its answer can never be a branch the day has
     * not committed to; forcing the raw pin here would hand an armed account a
     * same-day branch move that `FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1` requires
     * Super Admin approval for.
     *
     * A null is left to `authorize()` to refuse rather than merged as null.
     */
    private function applyFrontOfficePinnedBranchContext(): void
    {
        $user = $this->user();

        if ($user === null || ! app(FrontOfficeBranchPinResolver::class)->appliesTo($user)) {
            return;
        }

        $branchId = app(UserOnlineContextService::class)->activeContextBranchId($user);

        if ($branchId === null) {
            return;
        }

        if ($this->input('patient_mode') === 'new') {
            $this->merge([
                'new_patient' => array_merge($this->input('new_patient', []), [
                    'branch_id' => $branchId,
                ]),
                'branch_id' => $branchId,
            ]);

            return;
        }

        $this->merge(['branch_id' => $branchId]);
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
            // HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1 — no `clinic_room_id`
            // here. A room is placed only through rme.visits.assign-room, where
            // the same-branch / active-room / doctor-from-room rules live. The
            // registration form never sent one; accepting it (existence-checked
            // only) let a crafted POST place a new visit straight into another
            // branch's room. The service drops it as well (rule 200).
            'chief_complaint' => ['nullable', 'string', 'max:5000'],
            'initial_treatment_id' => ['required', 'integer', Rule::exists('mst_treatments', 'id')->where('is_active', true)],
            'initial_service_note' => ['nullable', 'string', 'max:2000'],

            'visit_type' => ['required', 'string', Rule::in(ClinicVisit::VISIT_TYPES)],
            'follow_up_of_visit_id' => ['nullable', 'integer', Rule::exists('trx_clinic_visits', 'id')],

            // Existing patient mode.
            'patient_id' => [Rule::requiredIf(! $isNew), 'nullable', 'integer', Rule::exists('mst_patients', 'id')],

            // New patient mode. DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule
            // 244 §2) — the Nomor RM is automatic and read-only: no number,
            // manual sequence or registration date is accepted (PatientService
            // drops them and stamps the clinical day).
            'new_patient' => [Rule::requiredIf($isNew), 'array'],
            'new_patient.name' => [Rule::requiredIf($isNew), 'nullable', 'string', 'max:150'],
            'new_patient.branch_id' => [
                Rule::requiredIf($isNew),
                'nullable',
                'integer',
                Rule::exists('mst_branches', 'id')->where('is_active', true)->where('is_rme_enabled', true),
            ],
            // Memo M4.2 (rule 244 §5) — NIK stays optional; when given it is
            // exactly 16 digits. Never read on an existing-patient visit.
            'new_patient.ktp_number' => [Rule::excludeIf(! $isNew), 'nullable', 'string', ...PatientCoreFields::NIK_RULES, Rule::unique('mst_patients', 'ktp_number')],
            'new_patient.email' => ['nullable', 'email', 'max:150'],
            // Memo M4.2 (rule 244 §5) — the core fields of a NEW patient and the
            // no-phone exception, from the ONE builder the Master Data form uses
            // too. On an existing-patient visit the hidden panel's leftovers are
            // excluded, never validated (as rule 184 §13 does for KTP state).
            ...PatientCoreFields::rules((array) $this->input('new_patient', []), 'new_patient.', $isNew),
            // FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 — required only when
            // a strong duplicate of the new patient is found.
            'new_patient.duplicate_override_reason' => ['nullable', 'string', 'max:1000'],

            // Sprint 61.1.1 — optional temp token for a KTP scan captured in the
            // RME "Pasien Baru" panel; promoted to a PatientDocument after the
            // patient is created. Missing/expired token never blocks creation.
            'ktp_scan_token' => ['nullable', 'string', 'max:64'],
            // REVISION-REGISTRATION-KTP-CAMERA-OCR-1 — required only when OCR
            // suggestions were applied to a NEW patient (the scan panel lives in
            // the new-patient panel; an existing-patient visit never needs it).
            'ktp_ocr_applied' => ['nullable', 'in:0,1'],
            'ktp_ocr_verified' => [
                Rule::excludeIf(! $isNew || (string) $this->input('ktp_ocr_applied') !== '1'),
                'accepted',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'ktp_ocr_verified.accepted' => 'Konfirmasi bahwa data hasil baca KTP sudah dicocokkan dengan KTP asli sebelum menyimpan.',
            'ktp_ocr_verified.required' => 'Konfirmasi bahwa data hasil baca KTP sudah dicocokkan dengan KTP asli sebelum menyimpan.',
            'new_patient.name.required' => 'Nama pasien baru wajib diisi.',
            'new_patient.branch_id.required' => 'Cabang RME pasien baru wajib dipilih.',
            'new_patient.branch_id.same' => 'Cabang RME pasien baru harus sama dengan Klinik/Cabang kunjungan.',
            'branch_id.required' => 'Cabang RME wajib dipilih untuk kunjungan pasien terdaftar.',
            'branch_id.exists' => 'Klinik/Cabang yang dipilih harus cabang RME aktif.',
            'new_patient.ktp_number.unique' => 'Nomor KTP sudah terdaftar pada pasien lain.',
            'patient_id.required' => 'Pilih pasien terdaftar.',
            'visit_type.in' => 'Jenis kunjungan tidak valid.',
            'follow_up_of_visit_id.required' => 'Kunjungan sebelumnya wajib dipilih untuk kunjungan kontrol atau lanjutan tindakan.',
            'follow_up_of_visit_id.exists' => 'Kunjungan sebelumnya tidak ditemukan.',
            ...PatientCoreFields::messages('new_patient.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateNewPatientShape($validator);
            $this->validateExistingPatientSelectable($validator);
            $this->validateFollowUpVisit($validator);
            $this->validateOnlineDoctor($validator);

            if ($this->input('patient_mode') === 'new' && $validator->errors()->isEmpty()) {
                app(PatientRegistrationDuplicateCheck::class)->validate(
                    $validator,
                    (array) $this->input('new_patient', []),
                    $this->input('new_patient.duplicate_override_reason'),
                    'new_patient.duplicate_override_reason',
                );
            }
        });
    }

    /**
     * A new patient is a NEW visit in the visit's own branch. Its Nomor RM is no
     * longer composed or pre-checked here (rule 244 §2): the allocator issues it
     * inside the registration transaction.
     */
    private function validateNewPatientShape(Validator $validator): void
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
        }
    }

    /**
     * REVISION-NEW-VISIT-GLOBAL-PATIENT-LOOKUP-1 — the server-side patient
     * boundary for visit creation.
     *
     * `Rule::exists` alone only proves the row is real, not that this operator
     * may register a visit for it. Being offered in the combobox is a UI
     * convenience; THIS is the authorization, and it is re-asserted on every
     * submit so a hand-crafted `patient_id` still has to pass it.
     *
     * What changed with the global lookup is the legitimate scope, not the
     * existence of the check. A patient from ANOTHER RME branch is now a valid
     * selection — that is the whole point of the revision — while a patient
     * outside the registry entirely (a non-RME or disabled branch), or an id
     * submitted by an operator with no valid working context, is still refused.
     *
     * The scope is the very same {@see PatientSelectorSearchService} the search
     * uses, so what is selectable and what is submittable cannot drift apart in
     * either direction.
     *
     * Note what this method deliberately does NOT do: it never touches the visit
     * branch. That is resolved independently from the operator's authorized
     * working context, so accepting a Telkomas patient can never create a
     * Telkomas visit for a Landak operator.
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
                'Pasien yang dipilih tidak tersedia untuk pendaftaran kunjungan.'
            );
        }
    }

    /**
     * HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1 (rule 200) — the prior-visit
     * rule now lives in ONE place, {@see ClinicVisitRegistrationGuard}, which
     * ClinicVisitService::create() also enforces against the explicit registrar.
     * This method only turns its verdict into a form error, so the form and the
     * service cannot disagree about which prior visit may be linked.
     *
     * OD-1 (owner, 2026-10-22): a Kontrol may follow a visit held at another
     * branch and treated by another doctor; only the patient must match.
     */
    private function validateFollowUpVisit(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $violation = app(ClinicVisitRegistrationGuard::class)->followUpViolation([
            'patient_mode' => $this->input('patient_mode'),
            'patient_id' => $this->input('patient_id'),
            'visit_type' => $this->input('visit_type', ClinicVisit::VISIT_TYPE_NEW),
            'follow_up_of_visit_id' => $this->input('follow_up_of_visit_id'),
        ]);

        if ($violation !== null) {
            $validator->errors()->add($violation[0], $violation[1]);
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
