<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — the answer to
 * EFFECTIVE_CLINICAL_BRANCH, and WHY it is that answer.
 *
 * The reason travels with the value so the resolver can stay pure. A degraded
 * lock has to be explainable to an operator, and the only alternative — writing
 * an audit row from the resolver — would insert one row on every page view of
 * every degraded doctor, because the resolver runs on every protected request.
 * The write hook audits the refusal once per attempt; a readiness surface
 * reports the standing condition; this object carries the reason in between.
 *
 * SIX SOURCES, AND ONLY TWO OF THEM LOCK ANYTHING.
 *
 *   NOT_APPLICABLE  the question does not apply: no user, the capability is
 *                   off, or the account is not a branch-lockable doctor
 *   UNLINKED        a Doctor-role account with no mst_doctors.user_id link,
 *                   which binds nobody
 *   UNSET           a linked doctor with no lock row: the compatibility state,
 *                   byte-identical to pre-sprint behaviour, NOT missing data
 *   HOME            the permanent home lock
 *   COVER           an approved cover that is current at the instant asked
 *   DEGRADED        a lock or cover that exists but names a branch that is no
 *                   longer an active RME branch
 *
 * DEGRADED IS UNSET-EQUIVALENT FOR EVERY CONSUMER. `branchId` is null and
 * `isLocked()` is false, so a branch that loses is_active or is_rme_enabled
 * lets legacy behaviour resume instead of throwing on every write path and
 * stopping the doctor dead. Only `degradedReason` distinguishes it, and only
 * for humans.
 */
final class DoctorEffectiveBranch
{
    public const SOURCE_NOT_APPLICABLE = 'not_applicable';

    public const SOURCE_UNLINKED = 'unlinked';

    public const SOURCE_UNSET = 'unset';

    public const SOURCE_HOME = 'home';

    public const SOURCE_COVER = 'cover';

    public const SOURCE_DEGRADED = 'degraded';

    /** The lock names a branch that is no longer an active RME branch. */
    public const REASON_HOME_BRANCH_NOT_RME_ENABLED = 'home_branch_not_rme_enabled';

    /** The cover names a branch that is no longer an active RME branch. */
    public const REASON_COVER_BRANCH_NOT_RME_ENABLED = 'cover_branch_not_rme_enabled';

    private function __construct(
        private readonly ?int $branchId,
        private readonly string $source,
        private readonly ?int $homeBranchId = null,
        private readonly ?int $coverId = null,
        private readonly ?string $degradedReason = null,
    ) {}

    /**
     * The capability is off, there is no user, or this account is not a
     * branch-lockable doctor. Callers must behave exactly as they did before
     * this sprint.
     */
    public static function notApplicable(): self
    {
        return new self(null, self::SOURCE_NOT_APPLICABLE);
    }

    /** A Doctor-role account with no linked mst_doctors record. */
    public static function unlinked(): self
    {
        return new self(null, self::SOURCE_UNLINKED);
    }

    /** A linked doctor who has never been assigned a home branch. */
    public static function notSet(): self
    {
        return new self(null, self::SOURCE_UNSET);
    }

    public static function home(int $branchId): self
    {
        return new self($branchId, self::SOURCE_HOME, homeBranchId: $branchId);
    }

    public static function cover(int $branchId, int $coverId, ?int $homeBranchId): self
    {
        return new self($branchId, self::SOURCE_COVER, homeBranchId: $homeBranchId, coverId: $coverId);
    }

    public static function degraded(string $reason, ?int $homeBranchId, ?int $coverId = null): self
    {
        return new self(
            null,
            self::SOURCE_DEGRADED,
            homeBranchId: $homeBranchId,
            coverId: $coverId,
            degradedReason: $reason,
        );
    }

    /**
     * The branch this doctor may operate in right now, or null.
     *
     * Null means 'do not narrow anything' for every one of the four reasons
     * above. A caller must never read null as an error.
     */
    public function branchId(): ?int
    {
        return $this->branchId;
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * The permanent home lock, even while a cover is in force — so a surface
     * can say where the doctor returns to.
     */
    public function homeBranchId(): ?int
    {
        return $this->homeBranchId;
    }

    public function coverId(): ?int
    {
        return $this->coverId;
    }

    public function degradedReason(): ?string
    {
        return $this->degradedReason;
    }

    /**
     * TRUE only for HOME and COVER: the doctor is bound to a usable branch.
     *
     * This is the predicate every consumer branches on. It is deliberately
     * false for DEGRADED.
     */
    public function isLocked(): bool
    {
        return $this->source === self::SOURCE_HOME || $this->source === self::SOURCE_COVER;
    }

    public function isCover(): bool
    {
        return $this->source === self::SOURCE_COVER;
    }

    public function isDegraded(): bool
    {
        return $this->source === self::SOURCE_DEGRADED;
    }

    /**
     * A structured, PII-free description for an audit payload or a readiness
     * report. Ids only: no name, no patient data.
     *
     * @return array<string, mixed>
     */
    public function toAuditArray(): array
    {
        return [
            'source' => $this->source,
            'effective_branch_id' => $this->branchId,
            'home_branch_id' => $this->homeBranchId,
            'cover_id' => $this->coverId,
            'degraded_reason' => $this->degradedReason,
        ];
    }
}
