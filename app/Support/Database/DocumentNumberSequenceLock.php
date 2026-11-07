<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * DMS-RME-REVISION-PROGRAM-8B / D2 — the serialization key for the three RME
 * document number sequences: RME invoice (`RME-YYYYMM-NNNNNN`), RME payment
 * (`RMEPAY-YYYYMM-NNNNNN`) and visit consent (`CONSENT-YYYYMM-NNNNNN`).
 *
 * WHY A LOCK
 * ----------
 * Each number is "the highest issued this month, plus one". The read was a plain
 * MAX / ORDER BY ... LIMIT 1 with no serialisation, so two cashiers (or two
 * consent signings) committing in the same instant both read N and both chose
 * N+1; the UNIQUE index then refused the second insert and that request ended in
 * HTTP 500 with a full rollback. This is the same race rule 225 closed for the
 * visit sequence (ClinicVisitSequenceLock), and it gets the same answer: a
 * transaction-scoped PostgreSQL advisory lock taken BEFORE the highest number is
 * read and released only at COMMIT or ROLLBACK, so read, choice and insert are
 * one step per sequence and month.
 *
 * KEY DERIVATION — EXACT, NEVER A HASH
 * ------------------------------------
 * `pg_advisory_xact_lock(int4, int4)`:
 *
 *     classid = one fixed NAMESPACE per sequence (below)
 *     objid   = the month as the integer YYYYMM (identity-mapped, cannot collide)
 *
 * 91_xxx belongs to legacy imports and 92_001 to the visit sequence. The three
 * sequences take three separate namespaces, so an invoice, a payment and a
 * consent never wait for each other, and a sequence never waits on another
 * month.
 *
 * NEVER RENUMBER A NAMESPACE. During a rolling deploy an old and a new process
 * would take different locks for the same month and both proceed. The values are
 * pinned by tests/Feature/RME/RmeDocumentNumberSequenceLockTest.php.
 *
 * The lock does not renumber anything. Every number already issued keeps its
 * value; only the choice of the NEXT number becomes serial.
 */
final class DocumentNumberSequenceLock
{
    public const RME_INVOICE = 92_002;

    public const RME_PAYMENT = 92_003;

    public const RME_VISIT_CONSENT = 92_004;

    /** Every namespace this class owns; used to reject an unknown one. */
    public const NAMESPACES = [self::RME_INVOICE, self::RME_PAYMENT, self::RME_VISIT_CONSENT];

    private function __construct() {}

    /**
     * The exact (classid, objid) pair handed to `pg_advisory_xact_lock`.
     *
     * @return array{0: int, 1: int}
     */
    public static function keyFor(int $namespace, string $month): array
    {
        if (! in_array($namespace, self::NAMESPACES, true)) {
            throw new InvalidArgumentException('Unknown document number sequence namespace.');
        }

        if (preg_match('/^(\d{4})(\d{2})$/D', $month, $parts) !== 1
            || (int) $parts[2] < 1
            || (int) $parts[2] > 12) {
            throw new InvalidArgumentException('The document number month must be YYYYMM.');
        }

        return [$namespace, (int) $month];
    }

    /**
     * Take the sequence lock for one month, inside the caller's transaction.
     *
     * Outside a transaction the lock would be released at the end of the
     * statement and serialise nothing, so that is a programming error rather
     * than a silent no-op. On a non-PostgreSQL driver (the SQLite test suite)
     * there is no advisory lock; SQLite serialises writers at the file level.
     */
    public static function acquire(int $namespace, string $month): void
    {
        [$classId, $objectId] = self::keyFor($namespace, $month);

        if (DB::transactionLevel() < 1) {
            throw new LogicException('A document number sequence lock must be taken inside a transaction.');
        }

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement('select pg_advisory_xact_lock(?, ?)', [$classId, $objectId]);
    }
}
