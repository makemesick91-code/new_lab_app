<?php

namespace App\Modules\RME\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 66.2 — Patient ↔ doctor assignment history (share/reassign, not exclusive transfer).
 */
class PatientDoctorAssignmentService
{
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
