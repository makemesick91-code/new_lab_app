<?php

namespace App\Modules\MedicalRecord\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\RME\Services\DoctorPatientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

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

        $bookId = $this->bookIdFor((int) $sheet->patient_id);

        return $bookId === null || $bookId !== (int) $sheet->getKey();
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
