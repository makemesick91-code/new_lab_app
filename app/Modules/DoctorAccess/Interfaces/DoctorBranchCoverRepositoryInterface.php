<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Interfaces;

use App\Modules\DoctorAccess\Models\DoctorBranchCover;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the TEMPORARY_BRANCH_COVER
 * boundary, and the home of the canonical 'is a cover current?' SQL.
 *
 * NO METHOD HERE CALLS now(). Every time-dependent read takes the instant to
 * judge against, so an authorization decision and the screen that explains it
 * can never disagree because a second ticked over between them.
 *
 * WHAT THE DATABASE DOES NOT PROTECT. `trx_doctor_branch_covers_pending_uq`
 * stops a second PENDING cover per doctor and
 * `trx_doctor_branch_covers_period_uq` stops an IDENTICAL approved period being
 * inserted twice. Neither expresses 'no two approved covers whose
 * [starts_at, ends_at) intervals OVERLAP' — a unique index compares values and
 * overlap is a range predicate, which was confirmed by attempting the insert,
 * not assumed. The overlap invariant rests ENTIRELY on the doctor's
 * `mst_doctor_branch_locks` row lock taken inside the approval transaction, and
 * no reader may assume a database backstop exists.
 */
interface DoctorBranchCoverRepositoryInterface
{
    public function findById(int $id): ?DoctorBranchCover;

    /**
     * The same row read `FOR UPDATE`. MUST be inside a transaction.
     */
    public function lockById(int $id): ?DoctorBranchCover;

    /**
     * The doctor's one open cover request, if any — guarded by
     * `trx_doctor_branch_covers_pending_uq`.
     */
    public function findPendingForDoctor(int $doctorId): ?DoctorBranchCover;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): DoctorBranchCover;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(DoctorBranchCover $cover, array $attributes): DoctorBranchCover;

    /**
     * THE canonical predicate: the cover that is the doctor's operational
     * authority at `$at`, or null.
     *
     * Half-open, `starts_at <= $at < ends_at`, so a cover ending at 17:00 and
     * one starting at 17:00 can never both answer. This SQL and
     * {@see DoctorBranchCover::coversInstant()} are two expressions of ONE rule
     * and are pinned equal by test.
     *
     * Overlap is forbidden, so at most one row should ever qualify. The order
     * is defence in depth: if a bad row ever exists the resolver still answers
     * deterministically with the most recently starting cover, and it never
     * combines two.
     */
    public function activeApprovedForDoctor(int $doctorId, CarbonInterface $at): ?DoctorBranchCover;

    /**
     * Approved covers colliding with the proposed period — the read the
     * approval transaction evaluates UNDER the doctor row lock.
     *
     * Half-open on both sides: `starts_at < $ends AND ends_at > $starts`, so a
     * back-to-back handover is not a collision. `lockForUpdate()` is
     * deliberately NOT applied: a range lock would gap-lock nothing useful and
     * compiles to an empty string on SQLite. The doctor's lock row is the
     * serialization point.
     *
     * @return Collection<int, DoctorBranchCover>
     */
    public function overlappingApproved(
        int $doctorId,
        CarbonInterface $starts,
        CarbonInterface $ends,
        ?int $excludeId = null,
    ): Collection;

    /**
     * @return Collection<int, DoctorBranchCover>
     */
    public function pending(): Collection;

    /**
     * Every approved cover for this doctor, so the surface can show a
     * SCHEDULED one that is not effective yet.
     *
     * @return Collection<int, DoctorBranchCover>
     */
    public function approvedForDoctor(int $doctorId): Collection;

    /**
     * @return Collection<int, DoctorBranchCover>
     */
    public function recentlyDecided(int $limit = 50): Collection;
}
