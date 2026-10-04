<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchPublish\Support;

/**
 * Stable, safe refusal codes for batch publish — PR2 §11.
 *
 * THESE CLASSIFY; THEY DO NOT DECIDE. Every code below names a refusal the
 * CANONICAL publish path already made (or that the pre-flight observed before
 * the canonical call was worth making). If this whole class were deleted, no
 * document would publish that does not publish today — only the report would
 * get less readable.
 *
 * WHY THE CANONICAL MESSAGE IS CARRIED VERBATIM
 * ---------------------------------------------
 * The canonical layer already produces precise operator-facing Indonesian text
 * ("Cabang arsip tidak lagi sesuai dengan Nomor RM pasien…"). Paraphrasing it
 * here would create a second, drifting source of truth. So the canonical
 * message is what an operator reads, and the code exists for grouping and for
 * tests.
 *
 * NOTHING HERE MAY LEAK INTERNALS. §11 forbids SQL and internal exception text
 * reaching an operator. Enforcement is in the classifier's message scrubber,
 * not in the goodwill of callers.
 *
 * UNKNOWN IS A REAL OUTCOME. A refusal that cannot be identified confidently is
 * reported as PUBLISH_UNKNOWN with the canonical message attached — never
 * guessed at, and never silently folded into success. §4 is explicit that the
 * refused items must be reported, not skipped.
 */
final class LegacyBatchPublishReason
{
    private function __construct() {}

    /** The import is not in REVIEWED state, so publish is not its next step. */
    public const NOT_REVIEWED = 'NOT_REVIEWED';

    /** A PR1 triage annotation is withholding it. Never auto-cleared (§15). */
    public const TRIAGE_BLOCKED = 'TRIAGE_BLOCKED';

    /**
     * An archive record already exists for this import.
     *
     * NOT an error. The canonical publish is idempotent and returns the existing
     * record with `created: false`; this code records that the attempt was a
     * no-op rather than letting it read as a fresh publication.
     */
    public const ALREADY_PUBLISHED = 'ALREADY_PUBLISHED';

    /** Separation of duties: this actor may not publish this document. */
    public const SOD_REFUSED = 'SOD_REFUSED';

    /** The source-RM / patient binding no longer holds. */
    public const PATIENT_BINDING_FAILED = 'PATIENT_BINDING_FAILED';

    /** Branch scope, admission, or an origin-branch drift refused it. */
    public const BRANCH_REFUSED = 'BRANCH_REFUSED';

    /** A clinical date rule failed on re-validation. */
    public const DATE_RULE_FAILED = 'DATE_RULE_FAILED';

    /** The native-RME / native-odontogram boundary failed on re-validation. */
    public const NATIVE_BOUNDARY_FAILED = 'NATIVE_BOUNDARY_FAILED';

    /** The patient's single-active-document slot conflicts. */
    public const SINGLE_ACTIVE_CONFLICT = 'SINGLE_ACTIVE_CONFLICT';

    /** Source bytes, checksum, rendered pages or attestation changed. */
    public const SOURCE_CHANGED = 'SOURCE_CHANGED';

    /** Permission or policy denied it for this actor. */
    public const AUTHORIZATION_REFUSED = 'AUTHORIZATION_REFUSED';

    /** The selection was made against state that has since moved on. */
    public const STALE_ITEM = 'STALE_ITEM';

    /** The migration capability was switched off mid-run. */
    public const FEATURE_DISABLED = 'FEATURE_DISABLED';

    /** The import left the actor's scope, or was deleted. */
    public const IMPORT_UNAVAILABLE = 'IMPORT_UNAVAILABLE';

    /** Classified but unrecognised. Never silently treated as success. */
    public const PUBLISH_UNKNOWN = 'PUBLISH_UNKNOWN';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::NOT_REVIEWED,
            self::TRIAGE_BLOCKED,
            self::ALREADY_PUBLISHED,
            self::SOD_REFUSED,
            self::PATIENT_BINDING_FAILED,
            self::BRANCH_REFUSED,
            self::DATE_RULE_FAILED,
            self::NATIVE_BOUNDARY_FAILED,
            self::SINGLE_ACTIVE_CONFLICT,
            self::SOURCE_CHANGED,
            self::AUTHORIZATION_REFUSED,
            self::STALE_ITEM,
            self::FEATURE_DISABLED,
            self::IMPORT_UNAVAILABLE,
            self::PUBLISH_UNKNOWN,
        ];
    }

    public static function isValid(?string $code): bool
    {
        return $code !== null && in_array($code, self::all(), true);
    }

    /**
     * Codes that mean "nothing was wrong, there was simply nothing to do".
     *
     * ALREADY_PUBLISHED is the whole set: the document IS in the archive, which
     * is the outcome the operator wanted. Reported separately from a published
     * count so a run of 100 that finds 3 already filed does not read as 103.
     *
     * @return list<string>
     */
    public static function benign(): array
    {
        return [self::ALREADY_PUBLISHED];
    }

    public static function isBenign(?string $code): bool
    {
        return $code !== null && in_array($code, self::benign(), true);
    }

    public static function label(?string $code): string
    {
        return match ($code) {
            self::NOT_REVIEWED => 'Belum ditinjau',
            self::TRIAGE_BLOCKED => 'Masih ditahan peninjau',
            self::ALREADY_PUBLISHED => 'Sudah dipublikasikan sebelumnya',
            self::SOD_REFUSED => 'Ditolak: pemisahan tugas',
            self::PATIENT_BINDING_FAILED => 'Ditolak: keterikatan pasien tidak valid',
            self::BRANCH_REFUSED => 'Ditolak: cabang tidak sesuai',
            self::DATE_RULE_FAILED => 'Ditolak: aturan tanggal gagal',
            self::NATIVE_BOUNDARY_FAILED => 'Ditolak: batas rekam medis asli',
            self::SINGLE_ACTIVE_CONFLICT => 'Ditolak: konflik dokumen aktif pasien',
            self::SOURCE_CHANGED => 'Ditolak: dokumen sumber berubah',
            self::AUTHORIZATION_REFUSED => 'Ditolak: tidak berwenang',
            self::STALE_ITEM => 'Ditolak: pilihan sudah tidak berlaku',
            self::FEATURE_DISABLED => 'Ditolak: kapabilitas migrasi nonaktif',
            self::IMPORT_UNAVAILABLE => 'Ditolak: dokumen tidak tersedia',
            self::PUBLISH_UNKNOWN => 'Ditolak: alasan tidak dikenal',
            default => (string) $code,
        };
    }
}
