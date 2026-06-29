<?php

namespace App\Modules\RME\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use Illuminate\Database\QueryException;
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
            $existing = PatientDoctorAssignment::query()
                ->where('patient_id', $visit->patient_id)
                ->where('doctor_id', $visit->doctor_id)
                ->whereNull('unassigned_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            try {
                return PatientDoctorAssignment::query()->create([
                    'patient_id' => $visit->patient_id,
                    'doctor_id' => $visit->doctor_id,
                    'branch_id' => $visit->branch_id,
                    'source_visit_id' => $visit->id,
                    'assigned_by' => $actor?->id,
                    'assigned_at' => now(),
                    'assignment_type' => PatientDoctorAssignment::TYPE_AUTO_VISIT,
                    'notes' => 'Auto-assigned from queue room assignment',
                ]);
            } catch (QueryException) {
                return PatientDoctorAssignment::query()
                    ->where('patient_id', $visit->patient_id)
                    ->where('doctor_id', $visit->doctor_id)
                    ->whereNull('unassigned_at')
                    ->first();
            }
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
            $existing = PatientDoctorAssignment::query()
                ->where('patient_id', $patient->id)
                ->where('doctor_id', $doctor->id)
                ->whereNull('unassigned_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            try {
                return PatientDoctorAssignment::query()->create([
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
            } catch (QueryException) {
                return PatientDoctorAssignment::query()
                    ->where('patient_id', $patient->id)
                    ->where('doctor_id', $doctor->id)
                    ->whereNull('unassigned_at')
                    ->firstOrFail();
            }
        });
    }
}
