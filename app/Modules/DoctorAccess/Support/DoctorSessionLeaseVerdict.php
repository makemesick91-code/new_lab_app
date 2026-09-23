<?php

declare(strict_types=1);

namespace App\Modules\DoctorAccess\Support;

use App\Modules\DoctorAccess\Models\DoctorSessionLease;

/**
 * DOCTOR-ACCESS-SINGLE-SESSION-BRANCH-LOCK-1 — what the claim transaction
 * decided, carried back out of that transaction so the consequences can happen
 * outside it.
 *
 * WHY THIS OBJECT EXISTS AT ALL. The exemplar this claim is copied from,
 * DailyBranchContextService::assertSelectable(), throws its refusal INSIDE the
 * transaction, because it has nothing to persist on the refusal path. This one
 * does have something to persist — a denial audit row that must survive, and a
 * session teardown that issues a DELETE on `sessions` through the same
 * connection, which a rollback would undo. So the transaction returns a verdict
 * and the caller acts on it after the commit.
 *
 * IT ALSO FIXES A COMPILE DEFECT BY CONSTRUCTION. The created lease is produced
 * inside a NESTED transaction whose closure return value must be assigned, or
 * the audit has no lease id to name. Making the granted/reclaimed verdict
 * REQUIRE the lease means a caller cannot forget to capture it.
 */
final class DoctorSessionLeaseVerdict
{
    /** A fresh lease was inserted; no incumbent stood in the way. */
    public const OUTCOME_GRANTED = 'granted';

    /** The incumbent's session was gone, so its lease was released and retaken. */
    public const OUTCOME_RECLAIMED = 'reclaimed';

    /** The login is refused. Nothing about the incumbent session changed. */
    public const OUTCOME_DENIED = 'denied';

    private function __construct(
        private readonly string $outcome,
        private readonly ?DoctorSessionLease $lease,
        private readonly ?DoctorSessionLease $incumbent,
        private readonly ?string $reason,
    ) {}

    public static function granted(DoctorSessionLease $lease): self
    {
        return new self(self::OUTCOME_GRANTED, $lease, null, null);
    }

    public static function reclaimed(DoctorSessionLease $lease, DoctorSessionLease $incumbent): self
    {
        return new self(self::OUTCOME_RECLAIMED, $lease, $incumbent, null);
    }

    public static function denied(string $reason, ?DoctorSessionLease $incumbent): self
    {
        return new self(self::OUTCOME_DENIED, null, $incumbent, $reason);
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    /**
     * The lease that now belongs to this session.
     *
     * Null ONLY on a denial, which is the one outcome that creates no row.
     */
    public function lease(): ?DoctorSessionLease
    {
        return $this->lease;
    }

    /**
     * The lease that was already there: the reason for a denial, or the row
     * that was released on a reclaim. Null on a clean grant.
     */
    public function incumbent(): ?DoctorSessionLease
    {
        return $this->incumbent;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function isDenied(): bool
    {
        return $this->outcome === self::OUTCOME_DENIED;
    }

    public function isReclaim(): bool
    {
        return $this->outcome === self::OUTCOME_RECLAIMED;
    }

    /**
     * A structured, PII-free description for an audit payload.
     *
     * Ids and reason codes only. The lease token, its hash and the session id
     * are deliberately absent: the first two are bearer credentials and the
     * third identifies a live session.
     *
     * @return array<string, mixed>
     */
    public function toAuditArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'lease_id' => $this->lease?->id === null ? null : (int) $this->lease->id,
            'incumbent_lease_id' => $this->incumbent?->id === null ? null : (int) $this->incumbent->id,
        ];
    }
}
