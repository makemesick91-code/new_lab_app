<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Services;

use App\Models\User;
use App\Modules\RmeInvoice\Interfaces\RmeOwnerApprovalReferenceRepositoryInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * HARDENING-RME-OWNER-REFERENCE-REGISTRY-1 — the database guarantee behind
 * "one Owner approval reference authorises one financial decision scope"
 * (decision PP-2; refund decision package, migration plan §2a).
 *
 * A reference is claimed by the FIRST scope that consumes it and stays bound to
 * that scope for good. Scopes:
 *   POST_PAYMENT_CORRECTION_INVOICE — the invoice; a resubmission on the same
 *     invoice repeats its reference (PP-2);
 *   REFUND_REQUEST — one refund request; a new request needs a new reference
 *     (owner decision RF-6). Consumed by RmeRefundRequestService::submit()
 *     (FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1, rule 215).
 *
 * Contract for a consumer:
 *   - call consume() INSIDE the transaction that records the decision, AFTER
 *     every domain row lock it takes, and claim at most one reference per
 *     transaction (no wait cycle can then form on the registry key);
 *   - treat `false` as a refusal and throw, so the transaction rolls back — the
 *     claim is in the same transaction, so a refused or failed decision never
 *     burns the reference;
 *   - keep its own friendly application check for the error message; this is
 *     the backstop that holds under real concurrency.
 *
 * Concurrency (PostgreSQL, READ COMMITTED): a second claimant of the same key
 * blocks on the first claimant's uncommitted index entry. If the first commits,
 * the second inserts nothing and reads the first scope; if it rolls back, the
 * second wins. ON CONFLICT DO NOTHING never raises, so a refusal never aborts the
 * transaction (25P02). Under REPEATABLE READ or SERIALIZABLE the read-after-
 * conflict could see a stale snapshot, so the registry refuses to run there.
 */
final class RmeOwnerApprovalReferenceRegistry
{
    public const SCOPE_POST_PAYMENT_CORRECTION_INVOICE = 'POST_PAYMENT_CORRECTION_INVOICE';

    public const SCOPE_REFUND_REQUEST = 'REFUND_REQUEST';

    public const SCOPES = [
        self::SCOPE_POST_PAYMENT_CORRECTION_INVOICE,
        self::SCOPE_REFUND_REQUEST,
    ];

    /** The folded form; the same pattern is a PostgreSQL CHECK on the column. */
    public const FOLDED_PATTERN = '/^[a-z0-9._:\/-]{6,120}$/D';

    public function __construct(private readonly RmeOwnerApprovalReferenceRepositoryInterface $references) {}

    /**
     * The registry key of a NORMALIZED reference
     * (RmeInvoiceCorrectionApprovalReference::normalize()). Its alphabet is ASCII,
     * so lower-casing is the complete fold; separators are kept, so `OWN-001` and
     * `OWN_001` stay distinct.
     *
     * @throws InvalidArgumentException when the value is not a normalized reference
     */
    public static function fold(string $normalizedReference): string
    {
        $folded = strtolower($normalizedReference);

        if (preg_match(self::FOLDED_PATTERN, $folded) !== 1) {
            throw new InvalidArgumentException('Not a normalized Owner approval reference.');
        }

        return $folded;
    }

    /**
     * Claims the reference for the scope, or finds it already claimed. True when
     * the reference belongs to THIS scope; false when another scope owns it.
     *
     * @throws InvalidArgumentException on an unknown scope, a non-positive scope id or a malformed reference
     * @throws LogicException outside a transaction or under an isolation level that cannot see the winner
     */
    public function consume(string $normalizedReference, string $scopeType, int $scopeId, User $actor): bool
    {
        $folded = self::fold($normalizedReference);
        $this->assertScope($scopeType, $scopeId);
        $this->assertTransaction();

        $this->references->insertIfAbsent($folded, $scopeType, $scopeId, (int) $actor->id);

        $owner = $this->references->scopeOf($folded);
        if ($owner === null) {
            throw new LogicException('The Owner approval reference claim is not visible after it was made.');
        }

        return $owner['scope_type'] === $scopeType && $owner['scope_id'] === $scopeId;
    }

    /**
     * Read-only: does the registry bind the reference to this scope? A malformed
     * reference belongs to no scope.
     */
    public function belongsTo(string $normalizedReference, string $scopeType, int $scopeId): bool
    {
        try {
            $folded = self::fold($normalizedReference);
        } catch (InvalidArgumentException) {
            return false;
        }

        $owner = $this->references->scopeOf($folded);

        return $owner !== null && $owner['scope_type'] === $scopeType && $owner['scope_id'] === $scopeId;
    }

    /**
     * Read-only: does ANY scope already hold this reference? Used by a decision
     * that records a reference without consuming the registry (a pre-payment
     * correction, rule 214 D2) so it cannot repeat one a registry-backed decision
     * already spent. A malformed reference is held by nobody.
     */
    public function isClaimed(string $normalizedReference): bool
    {
        try {
            $folded = self::fold($normalizedReference);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $this->references->scopeOf($folded) !== null;
    }

    private function assertScope(string $scopeType, int $scopeId): void
    {
        if (! in_array($scopeType, self::SCOPES, true) || $scopeId <= 0) {
            throw new InvalidArgumentException('Unknown Owner approval reference scope.');
        }
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('An Owner approval reference can only be consumed inside the transaction that records the decision.');
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $level = (string) (DB::selectOne('SELECT current_setting(\'transaction_isolation\') AS level')->level ?? '');
        if ($level !== 'read committed') {
            throw new LogicException('The Owner approval reference registry requires READ COMMITTED isolation, found '.$level.'.');
        }
    }
}
