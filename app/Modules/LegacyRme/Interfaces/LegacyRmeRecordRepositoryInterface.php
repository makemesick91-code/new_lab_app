<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Interfaces;

use App\Modules\LegacyRme\Models\LegacyRmeRecord;
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
}
