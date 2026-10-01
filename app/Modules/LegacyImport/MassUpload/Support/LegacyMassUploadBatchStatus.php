<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Support;

/**
 * Batch lifecycle for FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1.
 *
 * A final class of constants rather than a native enum, matching every other
 * status vocabulary in this codebase (LegacyRmeImportStatus, LegacyImportType,
 * LegacyRmeRecordStatus). Consistency here is not cosmetic: the staging column
 * is a string, and a native enum would be the only one of its kind.
 *
 * The lifecycle is deliberately small. Every extra state is another edge an
 * operator can get stuck in and another branch a test has to cover.
 *
 *   UPLOADED ──► VALIDATING ──► PREFLIGHT_READY ──► CONFIRMED ──► DISPATCHING
 *                    │                │                              │
 *                    │                └──► CANCELLED                 ├──► COMPLETED
 *                    ├──► PACKAGE_REJECTED                           ├──► COMPLETED_WITH_BLOCKED_ITEMS
 *                    └──► FAILED                                     └──► FAILED
 *
 * PACKAGE_REJECTED is separate from FAILED on purpose (§17). "Your archive was
 * malformed and nothing was attempted" and "we attempted work and it broke" are
 * different facts, and collapsing them costs an operator the one piece of
 * information that tells them whether to fix the ZIP or call someone.
 *
 * DISPATCHING is not a transient flicker — a large batch legitimately rests
 * there between bounded passes (§23), which is what makes resume possible.
 */
final class LegacyMassUploadBatchStatus
{
    /** Package stored, nothing inspected yet. */
    public const UPLOADED = 'UPLOADED';

    /** Package integrity + manifest parse in progress. */
    public const VALIDATING = 'VALIDATING';

    /** Per-item preflight done; awaiting operator review and confirmation. */
    public const PREFLIGHT_READY = 'PREFLIGHT_READY';

    /** Archive itself was unsafe or unusable. No item was ever created. */
    public const PACKAGE_REJECTED = 'PACKAGE_REJECTED';

    /** Operator confirmed; eligible items may now be created. */
    public const CONFIRMED = 'CONFIRMED';

    /** Bounded creation/dispatch underway, possibly across several passes. */
    public const DISPATCHING = 'DISPATCHING';

    /** Every eligible item produced a canonical import. */
    public const COMPLETED = 'COMPLETED';

    /**
     * Finished, but some rows were refused. This is a NORMAL outcome, not a
     * failure: a real archive routinely contains patients who already have a
     * published document. Naming it distinctly stops operators from reading a
     * healthy migration as a broken one.
     */
    public const COMPLETED_WITH_BLOCKED_ITEMS = 'COMPLETED_WITH_BLOCKED_ITEMS';

    /** Operator abandoned it before any item lifecycle existed. */
    public const CANCELLED = 'CANCELLED';

    /** Something broke after dispatch began. Created items are untouched. */
    public const FAILED = 'FAILED';

    /**
     * Allowed transitions. Anything absent here is refused by the service, so
     * a double-clicked Confirm cannot advance a batch twice (§27).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::UPLOADED => [self::VALIDATING, self::PACKAGE_REJECTED, self::CANCELLED, self::FAILED],
        self::VALIDATING => [self::PREFLIGHT_READY, self::PACKAGE_REJECTED, self::CANCELLED, self::FAILED],
        self::PREFLIGHT_READY => [self::CONFIRMED, self::CANCELLED],
        self::CONFIRMED => [self::DISPATCHING, self::FAILED],
        self::DISPATCHING => [
            self::DISPATCHING, // a further bounded pass
            self::COMPLETED,
            self::COMPLETED_WITH_BLOCKED_ITEMS,
            self::FAILED,
        ],
        self::PACKAGE_REJECTED => [],
        self::COMPLETED => [],
        self::COMPLETED_WITH_BLOCKED_ITEMS => [],
        self::CANCELLED => [],
        self::FAILED => [],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isValid(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Terminal states. A terminal batch is read-only: it may be viewed and its
     * report downloaded, but it can never dispatch again.
     *
     * @return list<string>
     */
    public static function terminal(): array
    {
        return [
            self::PACKAGE_REJECTED,
            self::COMPLETED,
            self::COMPLETED_WITH_BLOCKED_ITEMS,
            self::CANCELLED,
            self::FAILED,
        ];
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::terminal(), true);
    }

    /**
     * Cancellation is only ever a STAGING operation (§29). Once items exist,
     * the batch is no longer cancellable — withdrawing a real document is VOID
     * on that document, under its own authority, and must never be reachable by
     * cancelling a work-queue row.
     */
    public static function isCancellable(string $status): bool
    {
        return in_array($status, [self::UPLOADED, self::VALIDATING, self::PREFLIGHT_READY], true);
    }

    public static function label(string $status): string
    {
        return match ($status) {
            self::UPLOADED => 'Paket diunggah',
            self::VALIDATING => 'Memvalidasi paket',
            self::PREFLIGHT_READY => 'Siap ditinjau',
            self::PACKAGE_REJECTED => 'Paket ditolak',
            self::CONFIRMED => 'Dikonfirmasi',
            self::DISPATCHING => 'Sedang diproses',
            self::COMPLETED => 'Selesai',
            self::COMPLETED_WITH_BLOCKED_ITEMS => 'Selesai dengan baris ditolak',
            self::CANCELLED => 'Dibatalkan',
            self::FAILED => 'Gagal',
            default => $status,
        };
    }
}
