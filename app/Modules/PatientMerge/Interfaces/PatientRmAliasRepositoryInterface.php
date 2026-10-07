<?php

namespace App\Modules\PatientMerge\Interfaces;

use App\Modules\PatientMerge\Models\PatientRmAlias;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PatientRmAliasRepositoryInterface
{
    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): PatientRmAlias;

    /**
     * Active aliases matching any spelling of a Nomor RM.
     *
     * @param  array<int, string>  $numbers
     */
    public function findActiveByNumbers(array $numbers): ?PatientRmAlias;

    /**
     * Repoint every active alias resolving to $fromPatientId so it resolves
     * to $toPatientId. Returns the ids moved (recorded for reversal).
     *
     * @return array<int, int>
     */
    public function repointCanonical(int $fromPatientId, int $toPatientId): array;

    /** @param  array<int, int>  $aliasIds */
    public function restoreCanonical(array $aliasIds, int $toPatientId): void;

    public function revokeForCase(int $caseId): int;

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $filters
     */
    public function paginateScoped(array $branchIds, array $filters, int $perPage): LengthAwarePaginator;
}
