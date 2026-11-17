<?php

namespace App\Modules\RME\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Services\AuditLogService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Sprint 66.2 — Patient ↔ doctor assignment history (share/reassign, not exclusive transfer).
 *
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M2.1/M2.2 (rule 243 §2.12, PR A4) — an
 * automatic (`auto_visit`) row says "this doctor was placed on a visit of this
 * patient by the queue". It is STALE once no live visit of the patient (not
 * cancelled, not soft-deleted) has that doctor any more
 * ({@see staleAutoVisitRows()}, the one definition): revocation ends it at the
 * moment it goes stale ({@see revokeStaleAutoVisit()}, flag ON), and
 * `rme:doctor-assignments-reconcile` ends the ones that went stale before.
 * Manual, shared and reassigned rows are never ended here.
 */
class PatientDoctorAssignmentService
{
    public const AUDIT_TABLE = 'trx_rme_patient_doctor_assignments';

    /** Rule 243 §2.12 — one auto_visit row ended by a doctor change or a cancel. */
    public const AUDIT_ACTION_REVOKED = 'PATIENT_DOCTOR_ASSIGNMENT_REVOKED';

    private const END_REASONS = [
        PatientDoctorAssignment::END_REASON_VISIT_DOCTOR_CHANGED,
        PatientDoctorAssignment::END_REASON_VISIT_CANCELLED,
    ];

    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    public function ensureAssignedFromVisit(ClinicVisit $visit, ?User $actor): ?PatientDoctorAssignment
    {
        if ($visit->patient_id === null || $visit->doctor_id === null) {
            return null;
        }

        return DB::transaction(function () use ($visit, $actor) {
            $existing = $this->activeAssignment((int) $visit->patient_id, (int) $visit->doctor_id, lock: true);

            if ($existing !== null) {
                return $existing;
            }

            return $this->createOrAdoptActive([
                'patient_id' => $visit->patient_id,
                'doctor_id' => $visit->doctor_id,
                'branch_id' => $visit->branch_id,
                'source_visit_id' => $visit->id,
                'assigned_by' => $actor?->id,
                'assigned_at' => now(),
                'assignment_type' => PatientDoctorAssignment::TYPE_AUTO_VISIT,
                'notes' => 'Auto-assigned from queue room assignment',
            ]);
        });
    }

    public function assignPatientToDoctor(
        Patient $patient,
        Doctor $doctor,
        ?User $actor,
        ?Branch $branch,
        ?string $notes = null,
    ): PatientDoctorAssignment {
        return $this->createActiveAssignment(
            patient: $patient,
            doctor: $doctor,
            fromDoctor: null,
            actor: $actor,
            branch: $branch,
            sourceVisit: null,
            assignmentType: PatientDoctorAssignment::TYPE_MANUAL,
            reason: null,
            notes: $notes,
        );
    }

    public function sharePatientWithDoctor(
        Patient $patient,
        Doctor $toDoctor,
        ?Doctor $fromDoctor,
        ?User $actor,
        ?Branch $branch,
        ?string $reason = null,
    ): PatientDoctorAssignment {
        return $this->createActiveAssignment(
            patient: $patient,
            doctor: $toDoctor,
            fromDoctor: $fromDoctor,
            actor: $actor,
            branch: $branch,
            sourceVisit: null,
            assignmentType: $fromDoctor !== null
                ? PatientDoctorAssignment::TYPE_SHARED
                : PatientDoctorAssignment::TYPE_REASSIGNED,
            reason: $reason,
            notes: null,
        );
    }

    public function unassignPatientDoctor(
        Patient $patient,
        Doctor $doctor,
        ?User $actor,
        ?string $notes = null,
    ): void {
        DB::transaction(function () use ($patient, $doctor, $notes) {
            $assignment = PatientDoctorAssignment::query()
                ->where('patient_id', $patient->id)
                ->where('doctor_id', $doctor->id)
                ->whereNull('unassigned_at')
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                return;
            }

            $assignment->update([
                'unassigned_at' => now(),
                'notes' => $notes ?? $assignment->notes,
            ]);
        });
    }

    private function createActiveAssignment(
        Patient $patient,
        Doctor $doctor,
        ?Doctor $fromDoctor,
        ?User $actor,
        ?Branch $branch,
        ?ClinicVisit $sourceVisit,
        string $assignmentType,
        ?string $reason,
        ?string $notes,
    ): PatientDoctorAssignment {
        return DB::transaction(function () use (
            $patient,
            $doctor,
            $fromDoctor,
            $actor,
            $branch,
            $sourceVisit,
            $assignmentType,
            $reason,
            $notes,
        ) {
            $existing = $this->activeAssignment((int) $patient->id, (int) $doctor->id, lock: true);

            if ($existing !== null) {
                return $existing;
            }

            return $this->createOrAdoptActive([
                'patient_id' => $patient->id,
                'doctor_id' => $doctor->id,
                'from_doctor_id' => $fromDoctor?->id,
                'branch_id' => $branch?->id ?? $patient->branch_id,
                'source_visit_id' => $sourceVisit?->id,
                'assigned_by' => $actor?->id,
                'assigned_at' => now(),
                'assignment_type' => $assignmentType,
                'reason' => $reason,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * DMS-RME-REVISION-PROGRAM-7 / R5-1 — insert the active assignment, or adopt
     * the one a concurrent request committed first.
     *
     * The existence check above locks nothing when there is no row yet, so two
     * concurrent requests can both decide "none" and both insert. The partial
     * unique index (patient, doctor) WHERE unassigned_at IS NULL refuses the
     * loser. The loser used to catch that and query again in the SAME
     * transaction — but PostgreSQL aborts a transaction on any failed statement,
     * so the follow-up query answered 25P02 and the room assignment that called
     * this answered HTTP 500.
     *
     * The insert therefore runs in its own savepoint (the nested
     * DB::transaction). When it fails, Laravel rolls back to the savepoint
     * BEFORE rethrowing, so the connection is usable again by the time the
     * catch runs — the pattern DoctorSessionLeaseService already uses.
     *
     * Only a unique violation is a race to adopt, and only when the winner's row
     * is really there. Anything else (a foreign key, a NOT NULL) used to be
     * swallowed and reported as success; it now surfaces.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createOrAdoptActive(array $attributes): PatientDoctorAssignment
    {
        try {
            return DB::transaction(fn (): PatientDoctorAssignment => PatientDoctorAssignment::query()->create($attributes));
        } catch (UniqueConstraintViolationException $lostRace) {
            return $this->activeAssignment((int) $attributes['patient_id'], (int) $attributes['doctor_id'], lock: true)
                ?? throw $lostRace;
        }
    }

    /**
     * Rule 243 §2.12 — the active `auto_visit` rows no live visit supports any
     * more: no visit of the row's patient that is neither cancelled nor
     * soft-deleted has the row's doctor. Revocation and the reconcile command
     * both start from this builder, so "stale" has one definition. The
     * correlated probe is served by `trx_clinic_visits (doctor_id, patient_id,
     * status)` (rule 243 §2.7).
     *
     * @return Builder<PatientDoctorAssignment>
     */
    public function staleAutoVisitRows(): Builder
    {
        return PatientDoctorAssignment::query()
            ->where('trx_rme_patient_doctor_assignments.assignment_type', PatientDoctorAssignment::TYPE_AUTO_VISIT)
            ->whereNull('trx_rme_patient_doctor_assignments.unassigned_at')
            ->whereNotExists(function (QueryBuilder $supporting): void {
                $supporting->selectRaw('1')
                    ->from('trx_clinic_visits as supporting_visit')
                    ->whereColumn('supporting_visit.patient_id', 'trx_rme_patient_doctor_assignments.patient_id')
                    ->whereColumn('supporting_visit.doctor_id', 'trx_rme_patient_doctor_assignments.doctor_id')
                    ->where('supporting_visit.status', '!=', ClinicVisit::STATUS_CANCELLED)
                    ->whereNull('supporting_visit.deleted_at');
            });
    }

    /**
     * Rule 243 §2.12 (PR A4) — end the former doctor's `auto_visit` row for the
     * visit's patient once it is stale: after the visit's doctor changed away
     * from $formerDoctorId, or the visit was cancelled. A row the former doctor
     * still holds through another live visit of the patient is kept (it is
     * still true). Sets `unassigned_at` and the reason code, and audits ids
     * only. Must run inside the caller's transaction, after the visit write, so
     * the move or the cancel and the revocation commit or fail together.
     */
    public function revokeStaleAutoVisit(
        ClinicVisit $visit,
        int $formerDoctorId,
        string $reason,
        User $actor,
        ?int $replacementDoctorId = null,
    ): ?PatientDoctorAssignment {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Revocation must run inside the transaction that changes the visit.');
        }

        if (! in_array($reason, self::END_REASONS, true)) {
            throw new LogicException('Unknown assignment end reason.');
        }

        $row = $this->staleAutoVisitRows()
            ->where('trx_rme_patient_doctor_assignments.patient_id', (int) $visit->patient_id)
            ->where('trx_rme_patient_doctor_assignments.doctor_id', $formerDoctorId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            return null;
        }

        $row->forceFill([
            'unassigned_at' => now(),
            'unassigned_reason' => $reason,
        ])->save();

        // Ids and a reason code only: no name, no NIK, no visit number (CG-11).
        $this->auditLogs->log(self::AUDIT_TABLE, (int) $row->getKey(), self::AUDIT_ACTION_REVOKED, null, [
            'clinic_visit_id' => (int) $visit->getKey(),
            'patient_id' => (int) $visit->patient_id,
            'doctor_id' => $formerDoctorId,
            'replacement_doctor_id' => $replacementDoctorId,
            'reason' => $reason,
        ], $actor);

        return $row;
    }

    private function activeAssignment(int $patientId, int $doctorId, bool $lock): ?PatientDoctorAssignment
    {
        return PatientDoctorAssignment::query()
            ->where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->whereNull('unassigned_at')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }
}
