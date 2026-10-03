<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Support;

/**
 * Stable machine reason codes — PR1 §4, §15.
 *
 * Two separate families, and the separation is the point.
 *
 *   TRIAGE_* are the OPERATOR's reasons. A human chose one from a closed list
 *   when withholding a document. A closed list is what makes "why was this
 *   blocked" aggregatable across a migration instead of a field of free prose.
 *
 *   REFUSAL_* are the SERVER's reasons, assigned when the canonical review path
 *   says no. They are a classification of an exception the canonical layer
 *   raised, never a re-implementation of its decision.
 *
 * Every code is PII-free and safe to put in an audit payload, a flash message
 * and a CSV. The free-text note that may accompany a triage code is operator
 * input and is length-bounded and HTML-escaped at render; it is never used as a
 * key, never parsed, and never interpolated into SQL.
 */
final class LegacyBatchReviewReason
{
    private function __construct() {}

    // --- Operator triage reasons (the closed list a reviewer picks from) ---

    /** Pages are unreadable, cropped, upside down, or too dark to read. */
    public const TRIAGE_ILLEGIBLE = 'TRIAGE_ILLEGIBLE';

    /** The document does not belong to the patient it is filed against. */
    public const TRIAGE_WRONG_PATIENT = 'TRIAGE_WRONG_PATIENT';

    /** The clinical date on the paper disagrees with the staged date. */
    public const TRIAGE_DATE_MISMATCH = 'TRIAGE_DATE_MISMATCH';

    /** Pages are missing, duplicated, or out of order. */
    public const TRIAGE_INCOMPLETE_PAGES = 'TRIAGE_INCOMPLETE_PAGES';

    /** Not a legacy document of this type at all (wrong form, wrong module). */
    public const TRIAGE_WRONG_DOCUMENT_TYPE = 'TRIAGE_WRONG_DOCUMENT_TYPE';

    /** Looks like an archive that already exists for this patient. */
    public const TRIAGE_SUSPECTED_DUPLICATE = 'TRIAGE_SUSPECTED_DUPLICATE';

    /** Needs a clinician, the original paper, or a supervisor to resolve. */
    public const TRIAGE_NEEDS_CLARIFICATION = 'TRIAGE_NEEDS_CLARIFICATION';

    /** Anything else. Requires a note, because the code alone says nothing. */
    public const TRIAGE_OTHER = 'TRIAGE_OTHER';

    // --- Server refusal classifications (assigned, never chosen) ---

    /** Separation of duties: this actor may not review this document. */
    public const REFUSAL_SEPARATION_OF_DUTIES = 'REFUSAL_SEPARATION_OF_DUTIES';

    /** The import left a reviewable state between attestation and submit. */
    public const REFUSAL_STALE_STATUS = 'REFUSAL_STALE_STATUS';

    /** Rendered pages are missing or unusable. */
    public const REFUSAL_PAGES_UNUSABLE = 'REFUSAL_PAGES_UNUSABLE';

    /** The source-RM / patient binding or visit attestation no longer holds. */
    public const REFUSAL_BINDING_INVALID = 'REFUSAL_BINDING_INVALID';

    /** Branch scope or policy denied it for this actor. */
    public const REFUSAL_NOT_AUTHORIZED = 'REFUSAL_NOT_AUTHORIZED';

    /** The import vanished from the actor's scope, or was deleted. */
    public const REFUSAL_IMPORT_UNAVAILABLE = 'REFUSAL_IMPORT_UNAVAILABLE';

    /** The migration capability was switched off mid-session. */
    public const REFUSAL_FEATURE_DISABLED = 'REFUSAL_FEATURE_DISABLED';

    /** Sticky triage withheld it. Not an error; recorded for completeness. */
    public const REFUSAL_TRIAGE_BLOCKING = 'REFUSAL_TRIAGE_BLOCKING';

    /** Classified but unrecognised. Never silently treated as success. */
    public const REFUSAL_UNKNOWN = 'REFUSAL_UNKNOWN';

    /**
     * The codes an operator may submit. Validation uses exactly this list, so a
     * crafted reason_code cannot introduce a value the UI never offered.
     *
     * @return list<string>
     */
    public static function triageCodes(): array
    {
        return [
            self::TRIAGE_ILLEGIBLE,
            self::TRIAGE_WRONG_PATIENT,
            self::TRIAGE_DATE_MISMATCH,
            self::TRIAGE_INCOMPLETE_PAGES,
            self::TRIAGE_WRONG_DOCUMENT_TYPE,
            self::TRIAGE_SUSPECTED_DUPLICATE,
            self::TRIAGE_NEEDS_CLARIFICATION,
            self::TRIAGE_OTHER,
        ];
    }

    public static function isTriageCode(?string $code): bool
    {
        return $code !== null && in_array($code, self::triageCodes(), true);
    }

    /** Codes whose meaning is carried by the note, so a note is mandatory. */
    public static function requiresNote(?string $code): bool
    {
        return $code === self::TRIAGE_OTHER;
    }

    public static function label(?string $code): string
    {
        return match ($code) {
            self::TRIAGE_ILLEGIBLE => 'Hasil pindai tidak terbaca',
            self::TRIAGE_WRONG_PATIENT => 'Dokumen bukan milik pasien ini',
            self::TRIAGE_DATE_MISMATCH => 'Tanggal dokumen tidak sesuai',
            self::TRIAGE_INCOMPLETE_PAGES => 'Halaman tidak lengkap atau ganda',
            self::TRIAGE_WRONG_DOCUMENT_TYPE => 'Jenis dokumen tidak sesuai',
            self::TRIAGE_SUSPECTED_DUPLICATE => 'Dugaan arsip ganda',
            self::TRIAGE_NEEDS_CLARIFICATION => 'Perlu klarifikasi lebih lanjut',
            self::TRIAGE_OTHER => 'Alasan lain',
            self::REFUSAL_SEPARATION_OF_DUTIES => 'Ditolak: pemisahan tugas',
            self::REFUSAL_STALE_STATUS => 'Ditolak: status sudah berubah',
            self::REFUSAL_PAGES_UNUSABLE => 'Ditolak: halaman tidak dapat dipakai',
            self::REFUSAL_BINDING_INVALID => 'Ditolak: keterikatan data tidak valid',
            self::REFUSAL_NOT_AUTHORIZED => 'Ditolak: tidak berwenang',
            self::REFUSAL_IMPORT_UNAVAILABLE => 'Ditolak: dokumen tidak tersedia',
            self::REFUSAL_FEATURE_DISABLED => 'Ditolak: kapabilitas migrasi nonaktif',
            self::REFUSAL_TRIAGE_BLOCKING => 'Ditolak: masih ditahan peninjau',
            self::REFUSAL_UNKNOWN => 'Ditolak: alasan tidak dikenal',
            default => (string) $code,
        };
    }
}
