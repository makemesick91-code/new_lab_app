<?php

namespace App\Modules\PatientMerge\Repositories;

use App\Modules\PatientMerge\Interfaces\PatientRmAliasRepositoryInterface;
use App\Modules\PatientMerge\Models\PatientRmAlias;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class PatientRmAliasRepository implements PatientRmAliasRepositoryInterface
{
    public function create(array $attributes): PatientRmAlias
    {
        return PatientRmAlias::query()->create($attributes);
    }

    public function findActiveByNumbers(array $numbers): ?PatientRmAlias
    {
        $numbers = array_values(array_unique(array_filter(array_map(
            static fn ($n): string => mb_strtolower(trim((string) $n)),
            $numbers,
        ))));

        if ($numbers === []) {
            return null;
        }

        return PatientRmAlias::query()
            ->active()
            ->where(function (Builder $q) use ($numbers): void {
                foreach ($numbers as $number) {
                    $q->orWhereRaw('LOWER(alias_medical_record_number) = ?', [$number]);
                }
            })
            ->orderBy('id')
            ->first();
    }

    public function repointCanonical(int $fromPatientId, int $toPatientId): array
    {
        $ids = PatientRmAlias::query()
            ->active()
            ->where('canonical_patient_id', $fromPatientId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($ids !== []) {
            PatientRmAlias::query()->whereKey($ids)->update(['canonical_patient_id' => $toPatientId, 'updated_at' => now()]);
        }

        return $ids;
    }

    public function restoreCanonical(array $aliasIds, int $toPatientId): void
    {
        if ($aliasIds === []) {
            return;
        }

        PatientRmAlias::query()->whereKey($aliasIds)->update(['canonical_patient_id' => $toPatientId, 'updated_at' => now()]);
    }

    public function revokeForCase(int $caseId): int
    {
        return PatientRmAlias::query()
            ->where('merge_case_id', $caseId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public function paginateScoped(array $branchIds, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = PatientRmAlias::query()
            ->with(['canonicalPatient:id,name,medical_record_number,branch_id', 'sourcePatient:id,name,branch_id', 'mergeCase:id,uuid,case_number']);

        if ($branchIds === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereHas('canonicalPatient', function (Builder $patient) use ($branchIds): void {
                $patient->where(function (Builder $q) use ($branchIds): void {
                    $q->whereIn('branch_id', $branchIds)->orWhereNull('branch_id');
                });
            });
        }

        if (! empty($filters['rm'])) {
            $rm = mb_strtolower(trim((string) $filters['rm']));
            $query->where(function (Builder $q) use ($rm): void {
                $q->whereRaw('LOWER(alias_medical_record_number) = ?', [$rm])
                    ->orWhereHas('canonicalPatient', fn (Builder $p) => $p->whereRaw('LOWER(medical_record_number) = ?', [$rm]));
            });
        }

        if (($filters['state'] ?? null) === 'active') {
            $query->whereNull('revoked_at');
        } elseif (($filters['state'] ?? null) === 'revoked') {
            $query->whereNotNull('revoked_at');
        }

        return $query->orderByDesc('id')->paginate($perPage)->withQueryString();
    }
}
