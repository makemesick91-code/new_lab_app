<?php

namespace App\Modules\ClinicVisit\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Interfaces\ClinicVisitRepositoryInterface;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Services\PatientSelectorSearchService;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use Illuminate\Validation\ValidationException;

/**
 * HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1 — the ONE source of the visit
 * registration selection rules (rule 200): which patient, which prior visit and
 * which doctor a new visit may name.
 *
 * Before this sprint these rules lived only in StoreClinicVisitRequest, so
 * ClinicVisitService::create() accepted whatever a direct caller handed it
 * (rule 199 §27). Both now consult this class: the FormRequest turns a violation
 * into a field error for the form, the service re-asserts it as the
 * authoritative boundary. They cannot drift apart because neither restates it.
 *
 * OWNER DECISION OD-1 (2026-10-22, "pasien bisa kontrol lintas cabang bahkan
 * dengan dokter berbeda"): a Kontrol may follow a visit held at ANOTHER branch
 * and treated by ANOTHER doctor. The prior-visit link is therefore checked for
 * PATIENT identity only. It deliberately never compares the prior visit's branch
 * or doctor with the new visit's — the new visit's branch comes from the
 * registrar's working branch (ClinicVisitService::resolveBranchId) and its
 * doctor from the rules below, never from the prior visit.
 */
class ClinicVisitRegistrationGuard
{
    public const MESSAGE_PATIENT = 'Pasien yang dipilih tidak tersedia untuk pendaftaran kunjungan.';

    public function __construct(
        private readonly ClinicVisitRepositoryInterface $visits,
        private readonly BranchService $branches,
        private readonly PatientSelectorSearchService $patientSelector,
        private readonly UserOnlineContextService $onlineContext,
    ) {}

    /**
     * The selected existing patient must be one this registrar may register a
     * visit for — the same scope the "Kunjungan Baru" search offers (global
     * identity across the active RME branches, the doctor's own patient scope,
     * merged and soft-deleted patients excluded). A brand-new patient is created
     * by the registration itself and is not "selected".
     *
     * @param  array<string, mixed>  $data
     */
    public function assertPatientSelectable(User $registrar, array $data): void
    {
        if ($this->isNewPatient($data)) {
            return;
        }

        $patientId = $this->positiveInt($data['patient_id'] ?? null);

        if ($patientId === null || ! $this->patientSelector->isSelectable($registrar, $patientId)) {
            throw ValidationException::withMessages(['patient_id' => self::MESSAGE_PATIENT]);
        }
    }

    /**
     * The prior-visit link, as a field error, or null when the link is valid.
     *
     * Coherence first (pure input), then the prior visit itself: it must exist
     * at an active RME branch (soft-deleted visits do not exist here) and belong
     * to the SAME patient. With `$lock` the prior visit is read FOR SHARE, so a
     * concurrent soft-delete or patient re-pointing of that row cannot commit
     * between this check and the insert that references it.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string}|null
     */
    public function followUpViolation(array $data, bool $lock = false): ?array
    {
        $visitType = (string) ($data['visit_type'] ?? ClinicVisit::VISIT_TYPE_NEW);
        $isFollowUpType = in_array($visitType, ClinicVisit::FOLLOW_UP_VISIT_TYPES, true);
        $hasPriorVisit = ($data['follow_up_of_visit_id'] ?? null) !== null && ($data['follow_up_of_visit_id'] ?? '') !== '';

        if (! $isFollowUpType && ! $hasPriorVisit) {
            return null;
        }

        if ($this->isNewPatient($data)) {
            return ['visit_type', 'Kunjungan kontrol hanya dapat dibuat untuk pasien terdaftar.'];
        }

        if (! $isFollowUpType) {
            // Unreachable over HTTP (the form derives the type from the
            // presence of a prior visit); it stops a direct caller filing a
            // prior-visit link under a type that does not carry one.
            return ['visit_type', 'Kunjungan sebelumnya hanya dapat dipilih untuk kunjungan kontrol atau lanjutan tindakan.'];
        }

        $priorVisitId = $this->positiveInt($data['follow_up_of_visit_id'] ?? null);

        if (! $hasPriorVisit) {
            return ['follow_up_of_visit_id', 'Kunjungan sebelumnya wajib dipilih untuk kunjungan kontrol atau lanjutan tindakan.'];
        }

        $patientId = $this->positiveInt($data['patient_id'] ?? null);

        if ($patientId === null) {
            return ['patient_id', 'Pilih pasien terdaftar.'];
        }

        $prior = $priorVisitId === null
            ? null
            : $this->visits->findForRegistrationLink($this->branches->rmeEnabledIds(), $priorVisitId, $lock);

        if ($prior === null) {
            return ['follow_up_of_visit_id', 'Kunjungan sebelumnya harus berasal dari cabang RME yang valid.'];
        }

        if ((int) $prior->patient_id !== $patientId) {
            return ['follow_up_of_visit_id', 'Kunjungan sebelumnya harus milik pasien yang sama.'];
        }

        // OD-1: no branch comparison, no doctor comparison. See the class note.
        return null;
    }

    /** @param  array<string, mixed>  $data */
    public function assertFollowUpLink(array $data, bool $lock = false): void
    {
        $violation = $this->followUpViolation($data, $lock);

        if ($violation !== null) {
            throw ValidationException::withMessages([$violation[0] => $violation[1]]);
        }
    }

    /**
     * The doctor a registration may carry, as the data to persist.
     *
     * A front-desk registration (Admin Klinik / Perawat / Front Office working
     * context) carries NO doctor: the doctor is resolved from the treatment room
     * when the visit is placed (Sprint 66.1.4), so any supplied doctor — the
     * previous visit's included — is dropped, exactly as the form drops it.
     * Every other registrar must name a doctor who is online at the visit branch
     * (the form's validateOnlineDoctor), because a visit's doctor widens that
     * doctor's patient scope (rule 199 F9).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolveDoctor(User $registrar, array $data, int $branchId): array
    {
        if ($this->isFrontDeskRegistrar($registrar)) {
            $data['doctor_id'] = null;

            return $data;
        }

        $doctorId = $this->positiveInt($data['doctor_id'] ?? null);

        if ($doctorId === null) {
            throw ValidationException::withMessages(['doctor_id' => 'Dokter wajib dipilih untuk kunjungan ini.']);
        }

        $this->onlineContext->assertDoctorSelectableForVisit($doctorId, $branchId);

        $data['doctor_id'] = $doctorId;

        return $data;
    }

    public function isFrontDeskRegistrar(User $registrar): bool
    {
        return $this->onlineContext->resolveActiveBranchForAdmin($registrar) !== null;
    }

    /** @param  array<string, mixed>  $data */
    private function isNewPatient(array $data): bool
    {
        return ($data['patient_mode'] ?? 'existing') === 'new';
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
