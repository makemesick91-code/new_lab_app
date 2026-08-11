<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Interfaces;

use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Models\LegacyRmeRecordPage;
use Illuminate\Support\Collection;

/**
 * LEGACY-RME-PDF-1A — persistence boundary for published legacy RME records.
 *
 * Branch scope is always supplied by the caller as an already-resolved id list
 * (never a request value); an empty list resolves to nothing (fail closed).
 *
 * Published legacy records are immutable — this interface deliberately exposes
 * no update or delete operation.
 */
interface LegacyRmeRecordRepositoryInterface
{
    /**
     * Published (non-void) legacy records for a patient, oldest first, so the
     * archive reads chronologically alongside the native RME history.
     *
     * @param  array<int, int>  $branchIds
     * @return Collection<int, LegacyRmeRecord>
     */
    public function listPublishedForPatientInBranches(array $branchIds, int $patientId, bool $includeUnscoped = false): Collection;

    /**
     * @param  array<int, int>  $branchIds
     */
    public function findByIdInBranches(array $branchIds, int $id, bool $includeUnscoped = false): ?LegacyRmeRecord;

    public function findByUuid(string $uuid): ?LegacyRmeRecord;

    public function findBySourceImportId(int $importId): ?LegacyRmeRecord;

    /**
     * Records sharing a source PDF checksum. Duplicate handling is a service
     * decision; the repository only reports the collision.
     *
     * @return Collection<int, LegacyRmeRecord>
     */
    public function findByPdfChecksum(string $sha256): Collection;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): LegacyRmeRecord;

    /*
    |--------------------------------------------------------------------------
    | LEGACY-RME-PDF-1C — controlled publish and the published-record viewer
    |--------------------------------------------------------------------------
    |
    | Still no update and no delete: a published record stays immutable. The
    | additions below only create the record's pages at publish time and read
    | them back for the policy-gated viewer.
    */

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createPage(LegacyRmeRecord $record, array $attributes): LegacyRmeRecordPage;

    /**
     * @return Collection<int, LegacyRmeRecordPage>
     */
    public function pagesFor(LegacyRmeRecord $record): Collection;

    /**
     * Nested lookup: a page is always resolved THROUGH its record, so a page
     * number can never reach another record's rendered output.
     */
    public function findPage(LegacyRmeRecord $record, int $pageNumber): ?LegacyRmeRecordPage;

    /*
    |--------------------------------------------------------------------------
    | LEGACY-RME-PDF-1D — VOID, the single exception to immutability
    |--------------------------------------------------------------------------
    |
    | Still no generic update() and still no delete(). Reopening a general
    | update path would quietly restore in-place mutation of clinical evidence,
    | which every prior sprint deliberately refused.
    |
    | Instead exactly one narrow, named transition is exposed. markVoided()
    | writes ONLY the four void columns; it cannot touch the patient, the date,
    | the file, the hashes or the pages, so a "correction" can never be smuggled
    | in as a void. A corrected archive is still a VOID plus a fresh import.
    */

    /**
     * Re-read a record under a row lock so the VOID transition can re-assert
     * its status inside the transaction that performs it.
     */
    public function lockForUpdate(int $id): ?LegacyRmeRecord;

    /**
     * Retract a published record. Writes ONLY status / voided_by / voided_at /
     * void_reason — never any other column.
     */
    public function markVoided(LegacyRmeRecord $record, ?int $actorId, string $reason): LegacyRmeRecord;
}
