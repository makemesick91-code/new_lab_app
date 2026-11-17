<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Interfaces;

use App\Models\User;
use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\RmeAmendment\Models\RmeAmendment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 (rule 240 §6) — persistence of the
 * supervised amendment requests, and the few reads of other modules' rows the
 * amendment service needs (the doctor of record's account, the sheet's
 * diagnoses, the diagnosis master) so the service holds no query of its own.
 */
interface RmeAmendmentRepositoryInterface
{
    /**
     * File a new amendment; it is always `pending`.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): RmeAmendment;

    public function find(int $id): ?RmeAmendment;

    /** The row, locked FOR UPDATE (inside the caller's transaction). */
    public function findForUpdate(int $id): ?RmeAmendment;

    public function pendingFor(string $subjectType, int $subjectId): ?RmeAmendment;

    /** @return Collection<int, RmeAmendment> newest first */
    public function recentFor(string $subjectType, int $subjectId, int $limit): Collection;

    /**
     * Writes the status and decision columns, which are not mass-assignable.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function transition(RmeAmendment $amendment, array $attributes): RmeAmendment;

    /** @param list<int> $branchIds */
    public function paginateQueue(array $branchIds, ?string $status, int $perPage): LengthAwarePaginator;

    /**
     * @param  list<int>  $branchIds
     * @return array<string, int>
     */
    public function statusCounts(array $branchIds): array;

    /** The amended record itself (a sheet or a chart), or null when it is gone. */
    public function findSubject(string $subjectType, int $subjectId): MedicalRecord|Odontogram|null;

    /**
     * Rule 240 §7 — apply the diagnosis part of an approved amendment to the
     * locked sheet: soft-delete the removed rows (this sheet's only), then add the
     * new ones. Only RmeAmendmentService::approve() calls it, inside its
     * transaction; the normal diagnosis writes stay refused on a final sheet.
     *
     * @param  list<array{clinical_diagnosis_id: int, diagnosis_role: string, notes: ?string}>  $add
     * @param  list<int>  $remove
     */
    public function applySheetDiagnoses(MedicalRecord $sheet, array $add, array $remove, int $diagnosedBy, int $createdBy): void;

    /** @return Collection<int, User> accounts holding the permission (directly or by role), by id */
    public function approverCandidates(string $permission): Collection;

    /** `mst_doctors.user_id` of a doctor, or null. */
    public function doctorUserId(?int $doctorId): ?int;

    /** @return Collection<int, MedicalRecordDiagnosis> keyed by id */
    public function sheetDiagnoses(int $medicalRecordId): Collection;

    /**
     * @param  list<int>  $ids
     * @return Collection<int, ClinicalDiagnosis> keyed by id
     */
    public function clinicalDiagnoses(array $ids): Collection;
}
