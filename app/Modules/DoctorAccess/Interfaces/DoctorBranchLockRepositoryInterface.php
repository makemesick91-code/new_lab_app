<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Interfaces;

use App\Modules\DoctorAccess\Models\DoctorBranchLock;
use Illuminate\Support\Collection;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the HOME_LOCKED_BRANCH boundary.
 *
 * The enterprise baseline (ENT1-R001) forbids a service touching a model
 * directly, so every read and write of `mst_doctor_branch_locks` goes through
 * here.
 *
 * UNSET HAS EXACTLY ONE REPRESENTATION IN THIS SCHEMA: no row. `home_branch_id`
 * is NOT NULL, so a lock row cannot exist without an approved branch, and the
 * absence of a row is the compatibility state — never an error and never
 * something to repair. That is why there is deliberately NO
 * `firstOrCreateForDoctor()` here: an empty mutex row is unrepresentable, and
 * inventing one would write a branch nobody approved, which owner decision O1
 * forbids outright.
 *
 * CONSEQUENCE FOR THE COVER-OVERLAP INVARIANT. The serialization point for
 * cover approval is this doctor's lock row ({@see self::lockForDoctor()}). A
 * doctor with no lock row has nothing to lock — and also cannot hold a cover,
 * because `trx_doctor_branch_covers.source_home_branch_id` is NOT NULL. So the
 * approval service must read `lockForDoctor()` first and refuse a cover for an
 * UNSET doctor, rather than proceeding without the lock.
 */
interface DoctorBranchLockRepositoryInterface
{
    /**
     * This doctor's home lock, read WITHOUT a lock.
     *
     * Null means UNSET. For read paths only — never decide a mutation on this,
     * see {@see self::lockForDoctor()}.
     */
    public function findForDoctor(int $doctorId): ?DoctorBranchLock;

    /**
     * The cheap read the effective-branch resolver runs on every protected
     * request: the home branch id, or null when the doctor is UNSET.
     */
    public function lockedBranchIdFor(int $doctorId): ?int;

    /**
     * The same row read `FOR UPDATE`, so two approvers serialise on the doctor
     * instead of interleaving.
     *
     * MUST be called inside a transaction. Mirrors
     * DailyBranchContextRepository::lockForUser (:20-26). Returns null for an
     * UNSET doctor: there is no row to lock, which is a decision the caller
     * must handle, not a failure.
     *
     * On SQLite `lockForUpdate()` compiles to an empty string, so the local
     * suite proves the logic and only the PostgreSQL critical gate proves the
     * concurrency.
     */
    public function lockForDoctor(int $doctorId): ?DoctorBranchLock;

    /**
     * Apply an approved decision: create the lock on initial assignment, or
     * move it on transfer.
     *
     * The repository derives `previous_branch_id`, `last_transferred_at` and
     * `transfer_count` from the row it locks, so the history of the move cannot
     * be got wrong by a caller. Writes with forceFill because `$fillable` on
     * the model is deliberately empty.
     *
     * @param  string  $establishedVia  one of DoctorBranchLock::ESTABLISHED_VIA
     */
    public function assign(
        int $doctorId,
        int $homeBranchId,
        ?int $establishedByUserId,
        string $establishedVia,
    ): DoctorBranchLock;

    /**
     * Every current lock, eager-loaded for the approver surface table.
     *
     * @return Collection<int, DoctorBranchLock>
     */
    public function withDoctorAndBranch(): Collection;
}
