<?php

namespace App\Modules\MedicalRecord\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 (rule 243 §2.5, PR A1b) — the
 * sheet rules of `rme.doctor_encounter_scope`.
 *
 * READ ({@see followsItsVisit()}): with the flag ON every sheet except the
 * patient's handwriting BOOK ({@see PatientRmWorkspaceResolver::canonicalMedicalRecord()})
 * is read by the rule of its own visit; the book is read patient-level (HISTORY).
 *
 * TYPED WRITES ({@see assertWritable()}): the typed fields, diagnoses, override
 * and finalisation of ANY sheet — the book included — follow its own (anchor)
 * visit: only the doctor of that visit, while it is in service. Appending a
 * handwriting page to the book is the one patient-level write
 * (MedicalRecordPolicy::appendPage) and never comes here. This is the third
 * layer (CG-06) behind MedicalRecordPolicy::update/finalize, called after the
 * RME authoring gate by MedicalRecordService (updateDraft, finalize, startBook
 * with typed fields), MedicalRecordDiagnosisService, DiagnosisRolloutService and
 * the in-place page save of a per-visit sheet, so a direct call or a bypassed
 * policy is refused the same way. With the flag OFF nothing here applies.
 *
 * LISTS ({@see readableSheets()}, PR A3): the workspace's sheet navigation,
 * document rail and handwriting page sequence keep exactly the sheets READ
 * admits for the viewer.
 */
class MedicalRecordSheetWriteScope
{
    private const BOOK_MEMO_PREFIX = 'dms.rm_book_id.';

    public function __construct(
        private readonly DoctorPatientScopeService $doctorScope,
        private readonly PatientRmWorkspaceResolver $workspace,
    ) {}

    public function followsItsVisit(MedicalRecord $sheet): bool
    {
        if (! $this->doctorScope->encounterScopeEnabled() || $sheet->clinic_visit_id === null) {
            return false;
        }

        return $this->isNotTheBook($sheet, $this->bookIdFor((int) $sheet->patient_id));
    }

    /** The one definition of "not the book", given the patient's book id. */
    private function isNotTheBook(MedicalRecord $sheet, ?int $bookId): bool
    {
        return $bookId === null || $bookId !== (int) $sheet->getKey();
    }

    /**
     * Rule 243 §2.11 (PR A3) — the list mirror of MedicalRecordPolicy::view's
     * doctor scope: of $sheets, the ones $viewer may read. With the flag ON, for
     * a scoped Doctor, a per-visit sheet by its own visit
     * (DoctorPatientScopeService::doctorCanAccessVisit()) and the BOOK
     * patient-level (read mode HISTORY) — so in OWN mode only the doctor's own
     * sheets, never the book. Unchanged without a viewer, with the flag OFF and
     * for the exempt roles. Expects `clinicVisit` loaded
     * (PatientRmWorkspaceResolver::sheetsForPatient() eager-loads it). The
     * book is resolved ONCE per patient per call — also when the patient has
     * none, which bookIdFor() never memoises (A3a review LOW-2) — so a sheet
     * costs no query once the read mode is memoised.
     *
     * @param  Collection<int, MedicalRecord>  $sheets
     * @return Collection<int, MedicalRecord>
     */
    public function readableSheets(?User $viewer, Collection $sheets): Collection
    {
        if ($viewer === null || ! $this->doctorScope->encounterScopeAppliesTo($viewer)) {
            return $sheets;
        }

        /** @var array<int, int|null> $bookIds patient id => book id (null: no book) */
        $bookIds = [];

        return $sheets
            ->filter(function (MedicalRecord $sheet) use ($viewer, &$bookIds): bool {
                $patientId = (int) $sheet->patient_id;

                if (! array_key_exists($patientId, $bookIds)) {
                    $bookIds[$patientId] = $this->bookIdFor($patientId);
                }

                // followsItsVisit(), with the book resolved above.
                $followsItsVisit = $sheet->clinic_visit_id !== null && $this->isNotTheBook($sheet, $bookIds[$patientId]);

                return $followsItsVisit
                    ? $sheet->clinicVisit !== null && $this->doctorScope->doctorCanAccessVisit($viewer, $sheet->clinicVisit)
                    : $this->doctorScope->readModeFor($viewer, $patientId) === DoctorPatientScopeService::READ_HISTORY;
            })
            ->values();
    }

    /**
     * A sheet's typed content is written only by the doctor of its own visit
     * while that visit is in service. The visit is read fresh, so a caller
     * inside the write transaction (after the authoring gate has locked the live
     * encounter) decides on the current row.
     *
     * @throws AuthorizationException
     */
    public function assertWritable(MedicalRecord $sheet, ?User $actor): void
    {
        if (! $this->doctorScope->encounterScopeEnabled()) {
            return;
        }

        $visit = $sheet->clinic_visit_id !== null ? ClinicVisit::query()->find($sheet->clinic_visit_id) : null;

        $this->assertTypedWriteOn($visit, $actor);
    }

    /**
     * The same rule for typed content about to be written onto a sheet of
     * `$visit` that does not exist yet (MedicalRecordService::startBook()).
     *
     * @throws AuthorizationException
     */
    public function assertTypedWriteOn(?ClinicVisit $visit, ?User $actor): void
    {
        if (! $this->doctorScope->encounterScopeEnabled()) {
            return;
        }

        if (! $actor instanceof User) {
            throw new AuthorizationException(DoctorPatientScopeService::OUT_OF_SCOPE_MESSAGE);
        }

        if (! $this->doctorScope->shouldApplyDoctorScope($actor)) {
            return;
        }

        if ($visit !== null && $this->doctorScope->doctorMayWriteVisit($actor, $visit)) {
            return;
        }

        $doctor = $this->doctorScope->resolveDoctorForUser($actor);

        throw new AuthorizationException(
            $visit !== null && $doctor !== null && (int) $visit->doctor_id === (int) $doctor->id
                ? DoctorPatientScopeService::NOT_IN_SERVICE_MESSAGE
                : DoctorPatientScopeService::OTHER_DOCTOR_MESSAGE,
        );
    }

    /**
     * The patient's book id, memoised for the current ROUTED request once found
     * (a page asks once per sheet and per image). "No book yet" is never
     * memoised: the first save of the same request may create it.
     */
    private function bookIdFor(int $patientId): ?int
    {
        $request = $this->routedRequest();
        $key = self::BOOK_MEMO_PREFIX.$patientId;

        if ($request !== null && $request->attributes->has($key)) {
            return (int) $request->attributes->get($key);
        }

        $book = $this->workspace->canonicalMedicalRecord($patientId);

        if ($book === null) {
            return null;
        }

        $request?->attributes->set($key, (int) $book->getKey());

        return (int) $book->getKey();
    }

    private function routedRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request && $request->route() !== null ? $request : null;
    }
}
