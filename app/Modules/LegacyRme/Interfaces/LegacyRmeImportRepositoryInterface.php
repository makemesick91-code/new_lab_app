<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Interfaces;

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeImportPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    /*
    |--------------------------------------------------------------------------
    | LEGACY-RME-PDF-1B — upload runtime, queue processing and page rendering
    |--------------------------------------------------------------------------
    */

    /**
     * Branch-scoped, filterable listing for the staging workspace.
     *
     * @param  array<int, int>  $branchIds
     * @param  array{status?: string|null, patient?: string|null}  $filters
     * @return LengthAwarePaginator<int, LegacyRmeImport>
     */
    public function paginateInBranches(array $branchIds, array $filters = [], bool $includeUnscoped = false, int $perPage = 20): LengthAwarePaginator;

    /**
     * Load an import for background processing WITHOUT a branch scope.
     *
     * A queued job runs with no authenticated user, so there is no scope to
     * resolve; the job only ever receives an id that the (already authorized)
     * upload request produced. Every HTTP entry point must keep using the
     * branch-scoped finder instead.
     */
    public function findForProcessing(int $id): ?LegacyRmeImport;

    /**
     * Re-read an import under a row lock inside an open transaction, so two
     * concurrent workers can never process the same import twice.
     */
    public function lockForUpdate(int $id): ?LegacyRmeImport;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(LegacyRmeImport $import, array $attributes): LegacyRmeImport;

    /**
     * Non-terminal staging rows for a patient holding the same source PDF
     * checksum — an exact re-upload of a document already in flight.
     *
     * @return Collection<int, LegacyRmeImport>
     */
    public function openImportsForPatientWithChecksum(int $patientId, string $sha256): Collection;

    /**
     * Every staging row sharing a source PDF checksum, regardless of patient or
     * status. Duplicate policy is a service decision; the repository only
     * reports the collision.
     *
     * @return Collection<int, LegacyRmeImport>
     */
    public function findByPdfChecksum(string $sha256): Collection;

    /**
     * Idempotent page upsert keyed on the UNIQUE(legacy_import_id, page_number)
     * constraint, so a retried job updates the existing row instead of adding a
     * duplicate page.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upsertPage(LegacyRmeImport $import, int $pageNumber, array $attributes): LegacyRmeImportPage;

    /**
     * @return Collection<int, LegacyRmeImportPage>
     */
    public function pagesFor(LegacyRmeImport $import): Collection;

    public function findPage(LegacyRmeImport $import, int $pageNumber): ?LegacyRmeImportPage;

    /**
     * Drop page rows left behind by a previous rendering attempt.
     */
    public function deletePages(LegacyRmeImport $import): void;
}
