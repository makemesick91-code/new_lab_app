<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Interfaces;

use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImportPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * FIX-04b — persistence boundary for legacy odontogram STAGING rows.
 *
 * Every read that can reach a row takes the caller's already-resolved branch id
 * list as its FIRST parameter, so branch scope is structural rather than
 * something each call site has to remember. An empty list means "deny", never
 * "no filter".
 */
interface LegacyOdontogramImportRepositoryInterface
{
    /**
     * @param  list<int>  $branchIds
     * @param  array<string, mixed>  $filters
     */
    public function paginateInBranches(array $branchIds, array $filters = [], bool $includeUnscoped = false, int $perPage = 20): LengthAwarePaginator;

    /**
     * @param  list<int>  $branchIds
     */
    public function findByIdInBranches(array $branchIds, int $id, bool $includeUnscoped = false): ?LegacyOdontogramImport;

    /**
     * @param  list<int>  $branchIds
     * @return Collection<int, LegacyOdontogramImport>
     */
    public function listForPatientInBranches(array $branchIds, int $patientId, bool $includeUnscoped = false): Collection;

    /**
     * Deliberately UNSCOPED: the queue worker acts as the system, not as a
     * user, and has no branch context of its own. It is only ever reached from
     * a job that was dispatched by an already-authorized intake.
     */
    public function findForProcessing(int $id): ?LegacyOdontogramImport;

    public function lockForUpdate(int $id): ?LegacyOdontogramImport;

    /**
     * REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1 — the oldest staging
     * row that still OCCUPIES this patient's single legacy odontogram slot, or
     * null if none does.
     *
     * "Occupies" means `LegacyOdontogramImportStatus::SLOT_OCCUPYING`: every
     * state that can still advance toward PUBLISHED, including the retryable
     * FAILED.
     *
     * DELIBERATELY UNSCOPED BY BRANCH, unlike every read above it. Those answer
     * "what may this operator see"; this answers "what is clinically true about
     * this patient". A narrower scope could only return fewer rows, which would
     * let an operator at one branch open a second chart lifecycle for a patient
     * who already has one elsewhere. Authorization stays with the policies.
     *
     * INCLUDES SOFT-DELETED ROWS, so a `delete()` can never silently release a
     * clinical slot that only CANCEL or VOID is supposed to release.
     */
    public function firstSlotOccupyingForPatient(int $patientId): ?LegacyOdontogramImport;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): LegacyOdontogramImport;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(LegacyOdontogramImport $import, array $attributes): LegacyOdontogramImport;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertPage(LegacyOdontogramImport $import, int $pageNumber, array $attributes): LegacyOdontogramImportPage;

    /**
     * @return Collection<int, LegacyOdontogramImportPage>
     */
    public function pagesFor(LegacyOdontogramImport $import): Collection;

    public function findPage(LegacyOdontogramImport $import, int $pageNumber): ?LegacyOdontogramImportPage;

    public function deletePages(LegacyOdontogramImport $import): void;

    /** @return Collection<int, LegacyOdontogramImport> */
    public function findByPdfChecksum(string $sha256): Collection;
}
