<?php

namespace App\Modules\Tariff\Interfaces;

use App\Modules\Tariff\Models\Tariff;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

interface TariffRepositoryInterface
{
    /**
     * @param  array{search?: string|null, treatment_id?: int|null, is_active?: bool|null, requires_lab?: bool|null}  $filters
     */
    public function paginate(int $branchId, array $filters = [], int $perPage = 10): LengthAwarePaginator;

    public function findInBranch(int $branchId, int $id): ?Tariff;

    public function findActiveForTreatment(int $branchId, int $treatmentId, ?Carbon $onDate = null): ?Tariff;

    /**
     * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — the tariff IN FORCE for each of the
     * given treatments in ONE branch on a clinical date (Y-m-d): active, not
     * soft-deleted, effective_date <= date; the latest effective date wins, then
     * the highest id. Never falls back to another branch. One query.
     *
     * @param  list<int>  $treatmentIds
     * @return array<int, Tariff> keyed by treatment_id; a treatment with no tariff in force is absent
     */
    public function inForceForTreatments(int $branchId, array $treatmentIds, string $onDate): array;

    /**
     * Which of the given treatments are usable for billing authority: they
     * exist, are not soft-deleted and are active. One query.
     *
     * @param  list<int>  $treatmentIds
     * @return array<int, bool> treatment id => usable
     */
    public function treatmentUsability(array $treatmentIds): array;

    /**
     * The global active treatment master (id => code), for coverage reports.
     *
     * @return array<int, string>
     */
    public function activeTreatmentCodes(): array;

    /**
     * Tariffs of one branch exactly at one effective date, keyed by treatment_id,
     * soft-deleted rows included (the unique index includes them). Used by the
     * controlled import to detect "unchanged" vs "conflict".
     *
     * @param  list<int>  $treatmentIds
     * @return array<int, Tariff>
     */
    public function atEffectiveDateIncludingTrashed(int $branchId, array $treatmentIds, string $effectiveDate): array;

    /**
     * FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1 — the approval reference of each
     * given tariff (null = approval not recorded). One query.
     *
     * @param  list<int>  $tariffIds
     * @return array<int, string|null> keyed by tariff id; an unknown id is absent
     */
    public function approvalReferencesFor(array $tariffIds): array;

    /**
     * Re-read one tariff (soft-deleted included) under a row lock, so the import
     * can re-check an ATTEST row inside its transaction before recording
     * provenance on it.
     */
    public function lockForAttestation(int $tariffId): ?Tariff;

    /**
     * Record owner-approval provenance on a tariff. The ONLY writer of the
     * approval_* columns (they are not mass-assignable). Never changes a price.
     */
    public function recordApprovalProvenance(Tariff $tariff, string $reference, int $actorId): Tariff;

    /**
     * Withdraw approval provenance (a master-data change moved what was approved).
     */
    public function clearApprovalProvenance(Tariff $tariff): Tariff;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Tariff;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Tariff $tariff, array $data): Tariff;

    public function delete(Tariff $tariff): bool;
}
