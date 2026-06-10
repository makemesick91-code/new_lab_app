<?php

namespace App\Modules\Odontogram\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Odontogram\Interfaces\OdontogramRepositoryInterface;
use App\Modules\Odontogram\Models\Odontogram;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OdontogramService
{
    public function __construct(
        private readonly OdontogramRepositoryInterface $odontograms,
        private readonly BranchContext $branchContext,
    ) {}

    public function getOrCreateForVisit(ClinicVisit $clinicVisit, User $user): Odontogram
    {
        return DB::transaction(function () use ($clinicVisit, $user) {
            $branchId = $this->branchContext->requireId();

            if ($clinicVisit->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'clinic_visit_id' => 'Kunjungan tidak ditemukan di cabang aktif.',
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
            $branchId = $this->branchContext->requireId();

            if ($odontogram->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'odontogram_id' => 'Odontogram tidak ditemukan di cabang aktif.',
                ]);
            }

            if ($odontogram->isFinalized()) {
                throw ValidationException::withMessages([
                    'status' => 'Odontogram yang sudah final tidak dapat diubah.',
                ]);
            }

            $safe = array_intersect_key($payload, array_flip(['summary_notes', 'tooth_map_payload']));
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
            $branchId = $this->branchContext->requireId();

            if ($odontogram->branch_id !== $branchId) {
                throw ValidationException::withMessages([
                    'odontogram_id' => 'Odontogram tidak ditemukan di cabang aktif.',
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
