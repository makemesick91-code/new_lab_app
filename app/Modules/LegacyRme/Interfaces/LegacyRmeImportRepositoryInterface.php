<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Interfaces;

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use Illuminate\Support\Collection;

/**
 * LEGACY-RME-PDF-1A — persistence boundary for legacy RME staging rows.
 *
 * Every listing/lookup takes the caller's already-resolved branch id list as
 * its first parameter. The list is produced server-side from BranchContext /
 * the RME-enabled branch set (LegacyRmeWorkspaceScope) and is NEVER taken from
 * the request. An empty list must resolve to "nothing" — fail closed.
 */
interface LegacyRmeImportRepositoryInterface
{
    /**
     * Staging rows whose origin branch is inside the given scope. Rows with a
     * NULL origin branch are only visible to a caller that can see every
     * RME-enabled branch, because they carry no branch provenance.
     *
     * @param  array<int, int>  $branchIds
     * @return Collection<int, LegacyRmeImport>
     */
    public function listForPatientInBranches(array $branchIds, int $patientId, bool $includeUnscoped = false): Collection;

    /**
     * @param  array<int, int>  $branchIds
     */
    public function findByIdInBranches(array $branchIds, int $id, bool $includeUnscoped = false): ?LegacyRmeImport;

    public function findByUuid(string $uuid): ?LegacyRmeImport;

    /**
     * Non-terminal staging rows already holding the same historical date for a
     * patient. Used to warn about an accidental double import.
     *
     * @return Collection<int, LegacyRmeImport>
     */
    public function openImportsForPatientOnDate(int $patientId, string $rmeDate): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): LegacyRmeImport;
}
