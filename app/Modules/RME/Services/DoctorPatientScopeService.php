<?php

namespace App\Modules\RME\Services;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeOnlineContext\Services\DoctorUserResolver;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sprint 66.2 — Doctor RM visibility scope by assignment and visit history.
 */
class DoctorPatientScopeService
{
    public function __construct(
        private readonly DoctorUserResolver $doctorResolver,
    ) {}

    public function shouldApplyDoctorScope(User $user): bool
    {
        if ($user->hasRole(['Owner', 'Super Admin', 'Supervisor RME'])) {
            return false;
        }

        return $user->hasRole('Doctor');
    }

    public function resolveDoctorForUser(User $user): ?Doctor
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return null;
        }

        return $this->doctorResolver->resolveForUser($user);
    }

    public function doctorCanAccessPatient(User $user, Patient $patient): bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        $doctor = $this->resolveDoctorForUser($user);

        if ($doctor === null) {
            return false;
        }

        return $this->doctorHasPatientAccess((int) $doctor->id, (int) $patient->id);
    }

    public function doctorCanAccessVisit(User $user, ClinicVisit $visit): bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        if ($visit->patient_id === null) {
            return false;
        }

        $patient = $visit->relationLoaded('patient')
            ? $visit->patient
            : Patient::query()->find($visit->patient_id);

        if ($patient === null) {
            return false;
        }

        return $this->doctorCanAccessPatient($user, $patient);
    }

    /**
     * @param  Builder<Patient>  $query
     * @return Builder<Patient>
     */
    public function applyPatientScopeForUser(User $user, Builder $query): Builder
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return $query;
        }

        $doctor = $this->resolveDoctorForUser($user);

        if ($doctor === null) {
            return $query->whereRaw('1 = 0');
        }

        return $this->applyPatientScopeForDoctor($query, (int) $doctor->id);
    }

    /**
     * @param  Builder<ClinicVisit>  $query
     * @return Builder<ClinicVisit>
     */
    public function applyVisitScopeForUser(User $user, Builder $query): Builder
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return $query;
        }

        $doctor = $this->resolveDoctorForUser($user);

        if ($doctor === null) {
            return $query->whereRaw('1 = 0');
        }

        return $this->applyVisitScopeForDoctor($query, (int) $doctor->id);
    }

    /**
     * @param  Builder<MedicalRecord>  $query
     * @return Builder<MedicalRecord>
     */
    public function applyMedicalRecordScopeForUser(User $user, Builder $query): Builder
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return $query;
        }

        $doctor = $this->resolveDoctorForUser($user);

        if ($doctor === null) {
            return $query->whereRaw('1 = 0');
        }

        $doctorId = (int) $doctor->id;

        return $query->where(function (Builder $q) use ($doctorId) {
            $q->whereExists(function ($sub) use ($doctorId) {
                $sub->selectRaw('1')
                    ->from('trx_rme_patient_doctor_assignments as pda')
                    ->whereColumn('pda.patient_id', 'trx_medical_records.patient_id')
                    ->where('pda.doctor_id', $doctorId);
            })->orWhereExists(function ($sub) use ($doctorId) {
                $sub->selectRaw('1')
                    ->from('trx_clinic_visits as v')
                    ->whereColumn('v.patient_id', 'trx_medical_records.patient_id')
                    ->where('v.doctor_id', $doctorId);
            });
        });
    }

    public function authorizePatientAccess(User $user, Patient $patient): Response|bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        if ($this->resolveDoctorForUser($user) === null) {
            return Response::deny('Akun dokter belum terhubung ke Master Dokter.');
        }

        if (! $this->doctorCanAccessPatient($user, $patient)) {
            return Response::deny('Anda tidak memiliki akses ke RM pasien ini.');
        }

        return true;
    }

    public function authorizeVisitAccess(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        if ($this->resolveDoctorForUser($user) === null) {
            return Response::deny('Akun dokter belum terhubung ke Master Dokter.');
        }

        if (! $this->doctorCanAccessVisit($user, $visit)) {
            return Response::deny('Anda tidak memiliki akses ke RM pasien ini.');
        }

        return true;
    }

    /**
     * Strict visit-level scope for specific visit detail/actions (not patient workspace).
     */
    public function doctorCanAccessSpecificVisit(User $user, ClinicVisit $visit): bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        if ($visit->patient_id === null) {
            return false;
        }

        $doctor = $this->resolveDoctorForUser($user);

        if ($doctor === null) {
            return false;
        }

        $doctorId = (int) $doctor->id;

        if ((int) $visit->doctor_id === $doctorId) {
            return true;
        }

        return PatientDoctorAssignment::query()
            ->where('patient_id', (int) $visit->patient_id)
            ->where('doctor_id', $doctorId)
            ->whereNull('unassigned_at')
            ->exists();
    }

    public function authorizeSpecificVisitAccess(User $user, ClinicVisit $visit): Response|bool
    {
        if (! $this->shouldApplyDoctorScope($user)) {
            return true;
        }

        if ($this->resolveDoctorForUser($user) === null) {
            return Response::deny('Akun dokter belum terhubung ke Master Dokter.');
        }

        if (! $this->doctorCanAccessSpecificVisit($user, $visit)) {
            return Response::deny('Anda tidak memiliki akses ke detail kunjungan pasien ini.');
        }

        return true;
    }

    /**
     * @return array<int, array{doctor: Doctor, badges: array<int, string>}>
     */
    public function doctorsWithAccessSummary(Patient $patient): array
    {
        $patientId = (int) $patient->id;
        $summary = [];

        $assignments = $patient->doctorAssignments()
            ->with('doctor')
            ->orderByDesc('assigned_at')
            ->get();

        foreach ($assignments as $assignment) {
            if ($assignment->doctor === null) {
                continue;
            }

            $doctorId = (int) $assignment->doctor_id;
            $badges = $summary[$doctorId]['badges'] ?? [];

            if ($assignment->isActive()) {
                $badges[] = 'Aktif';
            } else {
                $badges[] = 'Pernah Assigned';
            }

            $badges[] = match ($assignment->assignment_type) {
                PatientDoctorAssignment::TYPE_AUTO_VISIT => 'Auto dari Antrian',
                PatientDoctorAssignment::TYPE_SHARED => 'Shared/Reassigned',
                PatientDoctorAssignment::TYPE_REASSIGNED => 'Shared/Reassigned',
                PatientDoctorAssignment::TYPE_MANUAL => 'Manual',
                default => ucfirst(str_replace('_', ' ', $assignment->assignment_type)),
            };

            $summary[$doctorId] = [
                'doctor' => $assignment->doctor,
                'badges' => array_values(array_unique($badges)),
            ];
        }

        $visitDoctorIds = ClinicVisit::query()
            ->where('patient_id', $patientId)
            ->whereNotNull('doctor_id')
            ->distinct()
            ->pluck('doctor_id');

        $visitDoctors = Doctor::query()->whereIn('id', $visitDoctorIds)->get()->keyBy('id');

        foreach ($visitDoctorIds as $doctorId) {
            $doctor = $visitDoctors->get($doctorId);
            if ($doctor === null) {
                continue;
            }

            $doctorId = (int) $doctorId;
            $badges = $summary[$doctorId]['badges'] ?? [];
            $badges[] = 'Pernah Menangani';
            $summary[$doctorId] = [
                'doctor' => $doctor,
                'badges' => array_values(array_unique($badges)),
            ];
        }

        return array_values($summary);
    }

    private function doctorHasPatientAccess(int $doctorId, int $patientId): bool
    {
        return PatientDoctorAssignment::query()
            ->where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->exists()
            || ClinicVisit::query()
                ->where('patient_id', $patientId)
                ->where('doctor_id', $doctorId)
                ->exists();
    }

    /**
     * @param  Builder<Patient>  $query
     * @return Builder<Patient>
     */
    private function applyPatientScopeForDoctor(Builder $query, int $doctorId): Builder
    {
        return $query->where(function (Builder $q) use ($doctorId) {
            $q->whereExists(function ($sub) use ($doctorId) {
                $sub->selectRaw('1')
                    ->from('trx_rme_patient_doctor_assignments as pda')
                    ->whereColumn('pda.patient_id', 'mst_patients.id')
                    ->where('pda.doctor_id', $doctorId);
            })->orWhereExists(function ($sub) use ($doctorId) {
                $sub->selectRaw('1')
                    ->from('trx_clinic_visits as v')
                    ->whereColumn('v.patient_id', 'mst_patients.id')
                    ->where('v.doctor_id', $doctorId);
            });
        });
    }

    /**
     * @param  Builder<ClinicVisit>  $query
     * @return Builder<ClinicVisit>
     */
    private function applyVisitScopeForDoctor(Builder $query, int $doctorId): Builder
    {
        return $query->where(function (Builder $q) use ($doctorId) {
            $q->whereExists(function ($sub) use ($doctorId) {
                $sub->selectRaw('1')
                    ->from('trx_rme_patient_doctor_assignments as pda')
                    ->whereColumn('pda.patient_id', 'trx_clinic_visits.patient_id')
                    ->where('pda.doctor_id', $doctorId);
            })->orWhere('trx_clinic_visits.doctor_id', $doctorId);
        });
    }
}
