<?php

namespace App\Modules\MedicalRecord\Services;

use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use Illuminate\Support\Collection;

/**
 * Sprint 64.0 — Patient-Centric RM Workspace resolver.
 *
 * Read-side service that turns the per-visit medical records of one patient into
 * a single "workspace/buku RM" of swipeable sheets. Pure reads, except the
 * explicit stamp helpers invoked from the create/update flow.
 *
 * Branch isolation: everything is scoped to the active "Cabang RME" set
 * (BranchService::rmeEnabledIds(), MAIN excluded), mirroring MedicalRecordPolicy
 * so no cross-branch / non-RME data ever leaks into a workspace.
 */
class PatientRmWorkspaceResolver
{
    public function __construct(
        private readonly BranchService $branches,
    ) {}

    /** @return array<int, int> */
    public function rmeBranchIds(): array
    {
        return $this->branches->rmeEnabledIds();
    }

    /**
     * The workspace anchor for a patient: the earliest non-cancelled RME-branch
     * visit that already has a medical record (ordered by visit_date then id).
     * Null when the patient has no RM sheet yet.
     *
     * Sprint 64.0 zero-MR fix: this "must already have an MR" variant is no
     * longer used for routing/redirect (it 404'd patients with zero sheets);
     * resolveCanonicalWorkspaceVisit() is the routing anchor. It is retained for
     * callers that specifically want the earliest *recorded* sheet's visit.
     */
    public function resolveCanonicalVisit(int $patientId): ?ClinicVisit
    {
        return ClinicVisit::query()
            ->whereIn('branch_id', $this->rmeBranchIds())
            ->where('patient_id', $patientId)
            ->where('status', '!=', ClinicVisit::STATUS_CANCELLED)
            ->whereHas('medicalRecord')
            ->orderBy('visit_date')
            ->orderBy('id')
            ->first();
    }

    /**
     * The patient's RM workspace anchor: the earliest non-cancelled RME-branch
     * visit (visit_date, id) REGARDLESS of whether it has a medical record yet.
     *
     * Sprint 64.0 zero-MR fix: unlike resolveCanonicalVisit() this never
     * requires an existing sheet, so a patient with visits but zero MRs still
     * has a stable canonical URL that renders an empty-state workspace shell
     * instead of 404ing. Null only when the patient has no eligible RME-branch
     * (non-cancelled) visit at all.
     */
    public function resolveCanonicalWorkspaceVisit(int $patientId): ?ClinicVisit
    {
        return ClinicVisit::query()
            ->whereIn('branch_id', $this->rmeBranchIds())
            ->where('patient_id', $patientId)
            ->where('status', '!=', ClinicVisit::STATUS_CANCELLED)
            ->orderBy('visit_date')
            ->orderBy('id')
            ->first();
    }

    /**
     * Sprint 64.0.2 — the single handwriting RM book: the medical record attached
     * to the patient's canonical (earliest non-cancelled RME) visit, if any.
     */
    public function canonicalMedicalRecord(int $patientId): ?MedicalRecord
    {
        $visit = $this->resolveCanonicalWorkspaceVisit($patientId);

        if ($visit === null) {
            return null;
        }

        return MedicalRecord::query()
            ->where('clinic_visit_id', $visit->id)
            ->first();
    }

    /**
     * Sprint 64.0.2 — virtual handwriting RM book for one patient. Collects every
     * handwriting page from all same-patient sheets (canonical + legacy later-visit
     * records) without moving data. Ordered by source visit (visit_date, id) then
     * page_number within each sheet.
     *
     * Each entry extends orderedHandwritingPages() with:
     * virtual_page_number, medical_record_id, clinic_visit_id, storage_page_number.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function orderedHandwritingBookForPatient(int $patientId): Collection
    {
        $virtualPages = collect();
        $virtualIndex = 1;

        foreach ($this->sheetsForPatient($patientId) as $sheet) {
            foreach ($sheet->orderedHandwritingPages() as $page) {
                $virtualPages->push(array_merge($page, [
                    'virtual_page_number' => $virtualIndex,
                    'medical_record_id' => $sheet->id,
                    'clinic_visit_id' => $sheet->clinic_visit_id,
                    'storage_page_number' => $page['page_number'],
                ]));
                $virtualIndex++;
            }
        }

        return $virtualPages;
    }

    /**
     * All RM sheets (medical records) of a patient within the RME branch scope,
     * ordered chronologically by their source visit (visit_date, id). This is the
     * swipe order shown in the workspace.
     *
     * @return Collection<int, MedicalRecord>
     */
    public function sheetsForPatient(int $patientId): Collection
    {
        return MedicalRecord::query()
            ->select('trx_medical_records.*')
            ->join('trx_clinic_visits', 'trx_clinic_visits.id', '=', 'trx_medical_records.clinic_visit_id')
            ->whereIn('trx_medical_records.branch_id', $this->rmeBranchIds())
            ->where('trx_medical_records.patient_id', $patientId)
            ->with(['clinicVisit', 'recordedBy', 'finalizedBy'])
            ->orderBy('trx_clinic_visits.visit_date')
            ->orderBy('trx_clinic_visits.id')
            ->get();
    }

    /**
     * Validate a client-supplied source_visit_id (IDOR boundary). Returns the
     * visit only when it belongs to the same patient AND an accessible RME
     * branch; otherwise null (forged / cross-patient / cross-branch ignored).
     */
    public function resolveSourceVisit(int $patientId, ?int $sourceVisitId): ?ClinicVisit
    {
        if ($sourceVisitId === null) {
            return null;
        }

        return ClinicVisit::query()
            ->whereIn('branch_id', $this->rmeBranchIds())
            ->where('patient_id', $patientId)
            ->whereKey($sourceVisitId)
            ->first();
    }

    /**
     * Next per-patient sheet ordinal (count of existing sheets + 1).
     */
    public function nextSheetNumber(int $patientId): int
    {
        return MedicalRecord::query()
            ->where('patient_id', $patientId)
            ->count() + 1;
    }

    /**
     * Pick the canonical (anchor) visit id for a sheet being created on
     * $candidate: the earliest non-cancelled RME visit for the patient
     * (visit_date, id), falling back to the candidate when it is itself the
     * earliest. Used at create time so a sheet always points at the true first
     * visit even when it is the very first sheet — including the zero-MR case
     * where the doctor creates the first sheet from a later visit.
     */
    public function pickCanonicalVisitId(ClinicVisit $candidate): int
    {
        $anchor = $this->resolveCanonicalWorkspaceVisit($candidate->patient_id);

        if ($anchor === null) {
            return $candidate->id;
        }

        $anchorKey = [$anchor->visit_date?->toDateString(), $anchor->id];
        $candidateKey = [$candidate->visit_date?->toDateString(), $candidate->id];

        return $anchorKey <= $candidateKey ? $anchor->id : $candidate->id;
    }

    /**
     * Lazily backfill the workspace audit columns on an existing sheet when they
     * are null. Safe to call repeatedly (no-op once stamped); never overwrites a
     * stored value. Called from the create/update/finalize flow only.
     */
    public function stampWorkspaceFields(MedicalRecord $medicalRecord): MedicalRecord
    {
        if ($medicalRecord->source_visit_id !== null
            && $medicalRecord->canonical_visit_id !== null
            && $medicalRecord->sheet_number !== null) {
            return $medicalRecord;
        }

        if ($medicalRecord->source_visit_id === null) {
            $medicalRecord->source_visit_id = $medicalRecord->clinic_visit_id;
        }

        if ($medicalRecord->canonical_visit_id === null) {
            $medicalRecord->canonical_visit_id = $medicalRecord->clinicVisit
                ? $this->pickCanonicalVisitId($medicalRecord->clinicVisit)
                : ($this->resolveCanonicalWorkspaceVisit($medicalRecord->patient_id)?->id ?? $medicalRecord->clinic_visit_id);
        }

        if ($medicalRecord->sheet_number === null) {
            $rank = $this->sheetsForPatient($medicalRecord->patient_id)
                ->search(fn (MedicalRecord $sheet) => $sheet->id === $medicalRecord->id);
            $medicalRecord->sheet_number = $rank === false ? 1 : $rank + 1;
        }

        $medicalRecord->save();

        return $medicalRecord;
    }
}
