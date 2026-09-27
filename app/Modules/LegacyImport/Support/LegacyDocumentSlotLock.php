<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Support;

use App\Modules\LegacyImport\Exceptions\LegacyDocumentSlotLockUnavailable;

/**
 * REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1 — the serialization key
 * for "at most one active legacy lifecycle per patient, per document type".
 *
 * WHY A LOCK AND NOT A UNIQUE INDEX
 * ---------------------------------
 * The invariant spans TWO tables. A patient's RME slot is occupied either by a
 * live staging row (`stg_rme_legacy_imports`) or by a published archive row
 * (`trx_rme_legacy_records`), and a PUBLISHED staging row whose record has been
 * VOIDed occupies nothing at all. No single PostgreSQL partial UNIQUE index can
 * express a predicate that reads another table, so the race is closed by
 * serializing the DECISION instead of constraining one table's rows.
 *
 * KEY DERIVATION — EXACT, AND DELIBERATELY NOT A HASH
 * ---------------------------------------------------
 * `pg_advisory_xact_lock(int4, int4)` is called as:
 *
 *     classid = NAMESPACES[$type]   (a fixed, documented small integer)
 *     objid   = $patientId
 *
 * Two integers rather than one 64-bit key because the two-argument form gives a
 * genuine namespace split: LEGACY_RME and LEGACY_ODONTOGRAM can never contend
 * with each other for the same patient, which is precisely the independence the
 * product requires. A hash is avoided on purpose — a hash can collide, and a
 * collision here would silently serialize two unrelated patients (a liveness
 * bug that only shows up under load). Identity mapping cannot collide.
 *
 * The namespace base is offset well away from 0 so these locks cannot be
 * confused with, or collide against, an advisory lock taken by anything else.
 * At the time of writing this is the FIRST and ONLY advisory-lock user in the
 * codebase; the offset exists so that stays safe if it is no longer the only
 * one.
 *
 * TRANSACTION-SCOPED, NEVER SESSION-SCOPED
 * ----------------------------------------
 * `pg_advisory_xact_lock` releases automatically at COMMIT or ROLLBACK. A
 * session-scoped lock (`pg_advisory_lock`) would leak across a pooled
 * connection and could strand a patient's slot for the life of the process.
 */
final class LegacyDocumentSlotLock
{
    /**
     * Offset for this feature's advisory-lock namespace. Never reuse or renumber
     * these: a renumbering during a rolling deploy would let an old and a new
     * process take different locks for the same patient and both proceed.
     */
    public const NAMESPACE_BASE = 91_000;

    /** @var array<string, int> */
    public const NAMESPACES = [
        LegacyImportType::LEGACY_RME => self::NAMESPACE_BASE + 1,
        LegacyImportType::LEGACY_ODONTOGRAM => self::NAMESPACE_BASE + 2,
    ];

    /**
     * `objid` is an int4 column in PostgreSQL's lock table. Passing a larger
     * value is an error, not a wrap-around, but we refuse it ourselves so the
     * failure names the real cause instead of surfacing as a driver error.
     */
    public const MAX_OBJID = 2_147_483_647;

    private function __construct() {}

    public static function supports(string $type): bool
    {
        return isset(self::NAMESPACES[$type]);
    }

    /**
     * The exact (classid, objid) pair handed to `pg_advisory_xact_lock`.
     *
     * @return array{0: int, 1: int}
     */
    public static function keyFor(string $type, int $patientId): array
    {
        if (! self::supports($type)) {
            throw LegacyDocumentSlotLockUnavailable::unsupportedType($type);
        }

        if ($patientId < 1 || $patientId > self::MAX_OBJID) {
            throw LegacyDocumentSlotLockUnavailable::patientIdOutOfRange($patientId);
        }

        return [self::NAMESPACES[$type], $patientId];
    }
}
