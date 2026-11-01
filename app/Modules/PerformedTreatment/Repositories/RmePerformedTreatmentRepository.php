<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Repositories;

use App\Modules\PerformedTreatment\Interfaces\RmePerformedTreatmentRepositoryInterface;
use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RmePerformedTreatmentRepository implements RmePerformedTreatmentRepositoryInterface
{
    public function declarationFor(int $visitId): ?RmeTreatmentDeclaration
    {
        return RmeTreatmentDeclaration::query()->where('clinic_visit_id', $visitId)->first();
    }

    public function activeFor(int $visitId): Collection
    {
        return RmePerformedTreatment::query()
            ->where('clinic_visit_id', $visitId)
            ->active()
            ->with(['treatment' => fn ($q) => $q->withTrashed()->with(['category' => fn ($c) => $c->withTrashed()])])
            ->orderBy('id')
            ->get();
    }

    public function historyFor(int $visitId): Collection
    {
        return RmePerformedTreatment::query()
            ->where('clinic_visit_id', $visitId)
            ->orderBy('id')
            ->get();
    }

    public function activeCount(int $visitId): int
    {
        return RmePerformedTreatment::query()->where('clinic_visit_id', $visitId)->active()->count();
    }

    public function findForVisit(int $visitId, int $rowId, bool $lock = false): ?RmePerformedTreatment
    {
        return RmePerformedTreatment::query()
            ->where('clinic_visit_id', $visitId)
            ->whereKey($rowId)
            ->when($lock, fn (Builder $q) => $q->lockForUpdate())
            ->first();
    }

    public function findBySubmissionToken(int $visitId, string $token): ?RmePerformedTreatment
    {
        return RmePerformedTreatment::query()
            ->where('clinic_visit_id', $visitId)
            ->where('submission_token', $token)
            ->first();
    }

    public function findActiveDuplicate(int $visitId, int $treatmentId, ?array $toothNumbers): ?RmePerformedTreatment
    {
        // Compared in PHP: a json column has no portable equality across
        // PostgreSQL (json, not jsonb) and SQLite. A visit holds a handful of rows.
        return RmePerformedTreatment::query()
            ->where('clinic_visit_id', $visitId)
            ->where('treatment_id', $treatmentId)
            ->active()
            ->get()
            ->first(fn (RmePerformedTreatment $row) => ($row->tooth_numbers ?: null) === $toothNumbers);
    }

    public function createRow(array $attributes): RmePerformedTreatment
    {
        $row = new RmePerformedTreatment;
        $row->forceFill($attributes)->save();

        return $row;
    }

    public function markVoided(RmePerformedTreatment $row, int $voidedBy, string $reason): RmePerformedTreatment
    {
        $row->forceFill([
            'status' => RmePerformedTreatment::STATUS_VOIDED,
            'voided_by' => $voidedBy,
            'voided_at' => now(),
            'void_reason' => $reason,
        ])->save();

        return $row;
    }

    public function putDeclaration(int $visitId, string $type, ?string $category, int $declaredBy, int $doctorId): RmeTreatmentDeclaration
    {
        $declaration = RmeTreatmentDeclaration::query()->where('clinic_visit_id', $visitId)->first();

        if ($declaration === null) {
            $declaration = new RmeTreatmentDeclaration;
            $declaration->forceFill([
                'clinic_visit_id' => $visitId,
                'version' => 1,
            ]);
        } else {
            $declaration->forceFill(['version' => (int) $declaration->version + 1]);
        }

        $declaration->forceFill([
            'declaration_type' => $type,
            'operational_visit_category' => $category,
            'declared_by' => $declaredBy,
            'declared_by_doctor_id' => $doctorId,
            'declared_at' => now(),
        ])->save();

        return $declaration;
    }

    public function findSelectableTreatment(int $treatmentId, array $excludedCategoryCodes, array $excludedCodes): ?Treatment
    {
        return $this->selectableQuery($excludedCategoryCodes, $excludedCodes)->whereKey($treatmentId)->first();
    }

    public function selectableTreatments(array $excludedCategoryCodes, array $excludedCodes): Collection
    {
        return $this->selectableQuery($excludedCategoryCodes, $excludedCodes)->orderBy('name')->get();
    }

    /** @return Builder<Treatment> */
    private function selectableQuery(array $excludedCategoryCodes, array $excludedCodes): Builder
    {
        return Treatment::query()
            ->with('category')
            ->where('is_active', true)
            ->whereNotIn('code', $excludedCodes)
            ->whereHas('category', fn (Builder $q) => $q->whereNotIn('code', $excludedCategoryCodes));
    }
}
