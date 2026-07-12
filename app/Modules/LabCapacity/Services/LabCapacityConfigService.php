<?php

namespace App\Modules\LabCapacity\Services;

use App\Models\User;
use App\Modules\LabCapacity\Models\LabServiceWorkloadProfile;
use App\Modules\LabCapacity\Models\TechnicianAvailabilityOverride;
use App\Modules\LabCapacity\Models\TechnicianCapability;
use App\Modules\LabCapacity\Models\TechnicianCapacityProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LAB-PROD-3 — Capacity configuration writes (transactional, auditable).
 *
 * All writes run in a DB::transaction with row locks. Effective-date overlap is
 * rejected server-side (never trusts the UI). created_by/updated_by stamped.
 */
class LabCapacityConfigService
{
    public function createCapacityProfile(array $data, User $actor): TechnicianCapacityProfile
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->assertNoCapacityOverlap((int) $data['technician_id'], $data['planning_unit'], $data['effective_from'], $data['effective_until'] ?? null, null);

            return TechnicianCapacityProfile::create([
                'technician_id' => $data['technician_id'],
                'planning_unit' => $data['planning_unit'],
                'daily_capacity' => $data['daily_capacity'],
                'working_days' => $data['working_days'] ?? null,
                'effective_from' => $data['effective_from'],
                'effective_until' => $data['effective_until'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });
    }

    public function updateCapacityProfile(TechnicianCapacityProfile $profile, array $data, User $actor): TechnicianCapacityProfile
    {
        return DB::transaction(function () use ($profile, $data, $actor) {
            $locked = TechnicianCapacityProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->assertNoCapacityOverlap(
                (int) ($data['technician_id'] ?? $locked->technician_id),
                $data['planning_unit'] ?? $locked->planning_unit,
                $data['effective_from'] ?? $locked->effective_from,
                $data['effective_until'] ?? $locked->effective_until,
                $locked->id,
            );
            $locked->fill([
                'planning_unit' => $data['planning_unit'] ?? $locked->planning_unit,
                'daily_capacity' => $data['daily_capacity'] ?? $locked->daily_capacity,
                'working_days' => $data['working_days'] ?? $locked->working_days,
                'effective_from' => $data['effective_from'] ?? $locked->effective_from,
                'effective_until' => array_key_exists('effective_until', $data) ? $data['effective_until'] : $locked->effective_until,
                'is_active' => $data['is_active'] ?? $locked->is_active,
                'notes' => $data['notes'] ?? $locked->notes,
                'updated_by' => $actor->id,
            ]);
            $locked->save();

            return $locked;
        });
    }

    public function deactivateCapacityProfile(TechnicianCapacityProfile $profile, User $actor): void
    {
        DB::transaction(function () use ($profile, $actor) {
            $locked = TechnicianCapacityProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $locked->update(['is_active' => false, 'updated_by' => $actor->id]);
        });
    }

    public function createWorkloadProfile(array $data, User $actor): LabServiceWorkloadProfile
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->assertNoWorkloadOverlap((int) $data['lab_service_id'], $data['planning_unit'], $data['effective_from'], $data['effective_until'] ?? null, null);

            return LabServiceWorkloadProfile::create([
                'lab_service_id' => $data['lab_service_id'],
                'planning_unit' => $data['planning_unit'],
                'planned_workload' => $data['planned_workload'],
                'effective_from' => $data['effective_from'],
                'effective_until' => $data['effective_until'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });
    }

    public function updateWorkloadProfile(LabServiceWorkloadProfile $profile, array $data, User $actor): LabServiceWorkloadProfile
    {
        return DB::transaction(function () use ($profile, $data, $actor) {
            $locked = LabServiceWorkloadProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->assertNoWorkloadOverlap(
                (int) ($data['lab_service_id'] ?? $locked->lab_service_id),
                $data['planning_unit'] ?? $locked->planning_unit,
                $data['effective_from'] ?? $locked->effective_from,
                $data['effective_until'] ?? $locked->effective_until,
                $locked->id,
            );
            $locked->fill([
                'planning_unit' => $data['planning_unit'] ?? $locked->planning_unit,
                'planned_workload' => $data['planned_workload'] ?? $locked->planned_workload,
                'effective_from' => $data['effective_from'] ?? $locked->effective_from,
                'effective_until' => array_key_exists('effective_until', $data) ? $data['effective_until'] : $locked->effective_until,
                'is_active' => $data['is_active'] ?? $locked->is_active,
                'notes' => $data['notes'] ?? $locked->notes,
                'updated_by' => $actor->id,
            ]);
            $locked->save();

            return $locked;
        });
    }

    public function deactivateWorkloadProfile(LabServiceWorkloadProfile $profile, User $actor): void
    {
        DB::transaction(function () use ($profile, $actor) {
            $locked = LabServiceWorkloadProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $locked->update(['is_active' => false, 'updated_by' => $actor->id]);
        });
    }

    public function setCapability(array $data, User $actor): TechnicianCapability
    {
        return DB::transaction(function () use ($data, $actor) {
            // One capability row per technician-service pair (the current
            // mapping); re-setting updates the effective window in place.
            return TechnicianCapability::updateOrCreate(
                [
                    'technician_id' => $data['technician_id'],
                    'lab_service_id' => $data['lab_service_id'],
                ],
                [
                    'is_eligible' => $data['is_eligible'] ?? true,
                    'effective_from' => $data['effective_from'],
                    'effective_until' => $data['effective_until'] ?? null,
                    'created_by' => $actor->id,
                ],
            );
        });
    }

    public function removeCapability(TechnicianCapability $capability): void
    {
        DB::transaction(fn () => $capability->delete());
    }

    public function upsertAvailabilityOverride(array $data, User $actor): TechnicianAvailabilityOverride
    {
        return DB::transaction(function () use ($data, $actor) {
            // date-cast columns serialise to 'Y-m-d 00:00:00', so match the
            // date part explicitly rather than via updateOrCreate equality.
            $override = TechnicianAvailabilityOverride::query()
                ->where('technician_id', $data['technician_id'])
                ->whereDate('override_date', $data['override_date'])
                ->lockForUpdate()
                ->first()
                ?? new TechnicianAvailabilityOverride([
                    'technician_id' => $data['technician_id'],
                    'override_date' => $data['override_date'],
                ]);

            $override->fill([
                'capacity_override' => $data['capacity_override'] ?? null,
                'capacity_reduction' => $data['capacity_reduction'] ?? null,
                'reason_category' => $data['reason_category'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);
            $override->save();

            return $override;
        });
    }

    public function removeAvailabilityOverride(TechnicianAvailabilityOverride $override): void
    {
        DB::transaction(fn () => $override->delete());
    }

    private function assertNoCapacityOverlap(int $technicianId, string $unit, $from, $until, ?int $ignoreId): void
    {
        $existing = TechnicianCapacityProfile::query()
            ->where('is_active', true)
            ->where('technician_id', $technicianId)
            ->where('planning_unit', $unit)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->lockForUpdate()
            ->get();

        foreach ($existing as $p) {
            if ($this->overlaps($from, $until, $p->effective_from, $p->effective_until)) {
                throw ValidationException::withMessages([
                    'effective_from' => 'Rentang efektif tumpang tindih dengan profil kapasitas aktif teknisi ini.',
                ]);
            }
        }
    }

    private function assertNoWorkloadOverlap(int $serviceId, string $unit, $from, $until, ?int $ignoreId): void
    {
        $existing = LabServiceWorkloadProfile::query()
            ->where('is_active', true)
            ->where('lab_service_id', $serviceId)
            ->where('planning_unit', $unit)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->lockForUpdate()
            ->get();

        foreach ($existing as $p) {
            if ($this->overlaps($from, $until, $p->effective_from, $p->effective_until)) {
                throw ValidationException::withMessages([
                    'effective_from' => 'Rentang efektif tumpang tindih dengan profil workload aktif layanan ini.',
                ]);
            }
        }
    }

    private function overlaps($aFrom, $aUntil, $bFrom, $bUntil): bool
    {
        $aFrom = Carbon::parse($aFrom);
        $bFrom = Carbon::parse($bFrom);
        $aUntil = $aUntil ? Carbon::parse($aUntil) : null;
        $bUntil = $bUntil ? Carbon::parse($bUntil) : null;

        $aBeforeB = $aUntil !== null && $aUntil->lt($bFrom);
        $bBeforeA = $bUntil !== null && $bUntil->lt($aFrom);

        return ! ($aBeforeB || $bBeforeA);
    }
}
