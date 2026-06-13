<?php

namespace App\Modules\Odontogram\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Interfaces\OdontogramRepositoryInterface;
use App\Modules\Odontogram\Models\Odontogram;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OdontogramService
{
    public function __construct(
        private readonly OdontogramRepositoryInterface $odontograms,
        private readonly BranchService $branches,
    ) {}

    /**
     * Whether a branch belongs to the operational "Cabang RME" set (active
     * RME-enabled branches). Replaces the single BranchContext/MAIN fallback so
     * the doctor odontogram workflow works for any RME-branch visit in the pilot
     * (Sprint 23 Phase 23.10).
     */
    private function isActiveRmeBranch(?int $branchId): bool
    {
        return $branchId !== null && in_array($branchId, $this->branches->rmeEnabledIds(), true);
    }

    public function getOrCreateForVisit(ClinicVisit $clinicVisit, User $user): Odontogram
    {
        return DB::transaction(function () use ($clinicVisit, $user) {
            if (! $this->isActiveRmeBranch($clinicVisit->branch_id)) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Kunjungan tidak berada di cabang RME aktif.',
                ]);
            }

            return $this->odontograms->createForClinicVisit($clinicVisit, [
                'created_by' => $user->id,
            ]);
        });
    }

    public function updatePlaceholder(Odontogram $odontogram, array $payload, User $user): Odontogram
    {
        return DB::transaction(function () use ($odontogram, $payload, $user) {
            if (! $this->isActiveRmeBranch($odontogram->branch_id)) {
                throw ValidationException::withMessages([
                    'odontogram_id' => 'Odontogram tidak berada di cabang RME aktif.',
                ]);
            }

            if ($odontogram->isFinalized()) {
                throw ValidationException::withMessages([
                    'status' => 'Odontogram yang sudah final tidak dapat diubah.',
                ]);
            }

            $safe = array_intersect_key($payload, array_flip(['summary_notes', 'additional_conditions', 'tooth_map_payload']));
            $safe['updated_by'] = $user->id;

            if (isset($safe['tooth_map_payload']['teeth']) && is_array($safe['tooth_map_payload']['teeth'])) {
                foreach ($safe['tooth_map_payload']['teeth'] as $num => $data) {
                    if (isset($data['conditions']) && is_array($data['conditions'])) {
                        $safe['tooth_map_payload']['teeth'][$num]['conditions'] = array_values(
                            array_unique(array_filter($data['conditions'], fn ($c) => $c !== null))
                        );
                    }
                }
            }

            return $this->odontograms->updatePlaceholder($odontogram, $safe);
        });
    }

    public function finalize(Odontogram $odontogram, User $user): Odontogram
    {
        return DB::transaction(function () use ($odontogram, $user) {
            if (! $this->isActiveRmeBranch($odontogram->branch_id)) {
                throw ValidationException::withMessages([
                    'odontogram_id' => 'Odontogram tidak berada di cabang RME aktif.',
                ]);
            }

            if ($odontogram->isFinalized()) {
                return $odontogram;
            }

            return $this->odontograms->finalize($odontogram, [
                'status' => Odontogram::STATUS_FINALIZED,
                'finalized_at' => now(),
                'finalized_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        });
    }
}
