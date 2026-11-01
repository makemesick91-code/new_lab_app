<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Interfaces;

use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Support\Collection;

/**
 * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — persistence of the
 * doctor's structured treatment record.
 *
 * Append-only by construction: there is no update or delete method. A row is
 * created once and can only be marked VOIDED; a declaration is replaced with a
 * version bump. Every write is called by RmePerformedTreatmentService inside a
 * transaction that already holds the visit row lock.
 */
interface RmePerformedTreatmentRepositoryInterface
{
    public function declarationFor(int $visitId): ?RmeTreatmentDeclaration;

    /** @return Collection<int, RmePerformedTreatment> active rows, oldest first, `treatment.category` loaded (soft-deleted included) */
    public function activeFor(int $visitId): Collection;

    /** @return Collection<int, RmePerformedTreatment> every row (voided included), oldest first */
    public function historyFor(int $visitId): Collection;

    public function activeCount(int $visitId): int;

    public function findForVisit(int $visitId, int $rowId, bool $lock = false): ?RmePerformedTreatment;

    public function findBySubmissionToken(int $visitId, string $token): ?RmePerformedTreatment;

    /** @param  list<string>|null  $toothNumbers  normalized (sorted, unique) or null */
    public function findActiveDuplicate(int $visitId, int $treatmentId, ?array $toothNumbers): ?RmePerformedTreatment;

    /** @param  array<string, mixed>  $attributes  an explicit allow-listed column set */
    public function createRow(array $attributes): RmePerformedTreatment;

    public function markVoided(RmePerformedTreatment $row, int $voidedBy, string $reason): RmePerformedTreatment;

    /**
     * Insert or replace the visit's declaration, bumping its version on a change.
     */
    public function putDeclaration(int $visitId, string $type, ?string $category, int $declaredBy, int $doctorId): RmeTreatmentDeclaration;

    /**
     * A treatment the doctor may record: active, not soft-deleted, not a
     * consultation / Kontrol service category and not an excluded (voucher) code.
     */
    public function findSelectableTreatment(int $treatmentId, array $excludedCategoryCodes, array $excludedCodes): ?Treatment;

    /** @return Collection<int, Treatment> */
    public function selectableTreatments(array $excludedCategoryCodes, array $excludedCodes): Collection;
}
