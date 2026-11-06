<?php

namespace App\Modules\ClinicVisit\Interfaces;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ClinicVisitRepositoryInterface
{
    /** @param array{search?: string|null, status?: string|null, visit_date?: string|null} $filters */
    public function paginate(int $branchId, array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Paginate visits scoped to a set of branches (the active RME-enabled
     * "Cabang RME" set), rather than a single BranchContext fallback branch.
     *
     * @param  array<int, int>  $branchIds
     * @param  array{search?: string|null, status?: string|null, visit_date?: string|null}  $filters
     */
    public function paginateForBranches(array $branchIds, array $filters = [], int $perPage = 15, ?\Closure $scope = null): LengthAwarePaginator;

    /**
     * Paginate room-assigned, non-terminal visits for the doctor/nurse worklist,
     * scoped to the active RME-enabled branch set.
     *
     * @param  array<int, int>  $branchIds
     * @param  array{search?: string|null, status?: string|null, clinic_room_id?: int|null}  $filters
     */
    public function worklistForBranches(array $branchIds, array $filters = [], int $perPage = 20, ?\Closure $scope = null): LengthAwarePaginator;

    /**
     * Paginate active (non-terminal) registered-patient queue visits scoped to
     * the active RME-enabled branch set. Includes visits with and without an
     * assigned room. Sprint 58.7 — Antrian Pasien.
     *
     * @param  array<int, int>  $branchIds
     * @param  array{search?: string|null, status?: string|null, room_status?: string|null, visit_date?: string|null}  $filters
     */
    public function queueForBranches(array $branchIds, array $filters = [], int $perPage = 20, ?\Closure $scope = null): LengthAwarePaginator;

    public function findInBranch(int $branchId, int $id): ?ClinicVisit;

    /**
     * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — the visit row,
     * re-read under a row lock (SELECT … FOR UPDATE) inside the caller's
     * transaction. The serialization point of every clinical write that must
     * observe the committed examination status (a status UPDATE takes the same
     * row lock). Not branch-filtered: the caller authorized the visit first.
     */
    public function lockForUpdate(int $id): ?ClinicVisit;

    /**
     * DMS-RME-REVISION-PROGRAM-5 (rule 223 §1) — the same row lock at the
     * strength the visit's own status/room/consent decisions need:
     * FOR NO KEY UPDATE. It conflicts with itself and with FOR UPDATE (so it
     * serialises against lockForUpdate() callers and against another
     * transition), but NOT with the FOR KEY SHARE every foreign-key INSERT
     * takes on this parent row — so diagnoses, payments, consents and
     * odontograms written against the visit are never blocked by it and cannot
     * deadlock with it (the 40P01 RmeInvoiceService::create() records). SQLite
     * compiles any lock clause to nothing.
     */
    public function lockForStatusDecision(int $id): ?ClinicVisit;

    /**
     * DMS-RME-REVISION-PROGRAM-7 / R5-2 — take this branch's visit-numbering
     * lock for the rest of the current transaction. Must be called inside a
     * transaction and BEFORE the queue number or visit number is read; another
     * registration at the same branch then waits here until this one commits.
     * A no-op on drivers without advisory locks (SQLite serialises writers).
     */
    public function lockVisitSequence(int $branchId): void;

    /** The day's next queue number. Correct only under lockVisitSequence(). */
    public function nextQueueNumber(int $branchId, Carbon $visitDate): int;

    public function countTodayByBranch(int $branchId, string $date): int;

    public function countByBranchStatus(int $branchId, string $status): int;

    /** @param array<int, int> $branchIds */
    public function countTodayByBranches(array $branchIds, string $date): int;

    /** @param array<int, int> $branchIds */
    public function countByBranchesStatus(array $branchIds, string $status): int;

    /** @param array<string, mixed> $data */
    public function create(array $data): ClinicVisit;

    /** @param array<string, mixed> $data */
    public function update(ClinicVisit $visit, array $data): ClinicVisit;

    /**
     * List visits for a patient scoped to RME-enabled branches.
     *
     * @param  array<int, int>  $branchIds
     */
    public function listForPatient(array $branchIds, int $patientId, ?int $excludeVisitId = null): Collection;

    /**
     * Find a visit by ID when it belongs to one of the given RME branches.
     *
     * @param  array<int, int>  $branchIds
     */
    public function findByIdInBranches(array $branchIds, int $id): ?ClinicVisit;

    /**
     * HARDENING-RME-ACTIVE-USER-PATIENT-SELECTION-1 — the prior visit a new
     * registration links to, inside the given branch set (soft-deleted visits
     * excluded). With `$sharedLock` the row is read FOR SHARE so it cannot be
     * soft-deleted or re-pointed until the registering transaction ends.
     *
     * @param  array<int, int>  $branchIds
     */
    public function findForRegistrationLink(array $branchIds, int $id, bool $sharedLock = false): ?ClinicVisit;

    /**
     * Resolve the previous/next visit for the same patient, scoped to the given
     * RME branches and ordered chronologically by (visit_date, id). Used for the
     * prev/next arrow navigation on the RM and Odontogram pages (Sprint 59).
     * When $requireMedicalRecord is true, only visits that already have a medical
     * record are considered (so RM navigation never lands on a 404).
     *
     * @param  array<int, int>  $branchIds
     * @return array{previous: ?ClinicVisit, next: ?ClinicVisit}
     */
    public function adjacentVisitsForPatient(
        array $branchIds,
        ClinicVisit $visit,
        bool $requireMedicalRecord = false
    ): array;

    /**
     * LEGACY-RME-PDF-1A — the patient's EARLIEST native RME encounter: the
     * oldest non-cancelled visit that already carries a medical record produced
     * by this system's own workflow.
     *
     * Deliberately NOT branch-scoped. This is a clinical safety bound for the
     * legacy archive, and a narrower scan could only move the bound LATER,
     * which would let a historical document overlap a real native record.
     * Branch isolation for "who may see/import what" is enforced separately by
     * the LegacyRme policies.
     */
    public function earliestVisitWithMedicalRecordForPatient(int $patientId): ?ClinicVisit;
}
