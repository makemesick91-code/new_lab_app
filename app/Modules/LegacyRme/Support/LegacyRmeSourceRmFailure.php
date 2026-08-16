<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-SOURCE-RM-BINDING-1 — the stable vocabulary for "does the RM
 * printed on this document actually name the patient that was selected?".
 *
 * Callers branch on the CODE, never on the message — the same contract
 * {@see LegacyRmeDateRuleResult}, {@see LegacyRmeBranchResolution} and
 * {@see LegacyRmePatientResolution} already establish. The Indonesian messages
 * are operator-facing and may be reworded; the codes may not.
 *
 * EVERY CODE HERE IS A REFUSAL. There is no "warning" tier and no override:
 * a document whose asserted identity cannot be proven to match the selected
 * patient is not staged, not queued, not reviewed and not published. Wave-2
 * showed what the alternative costs — a wrong binding that was only caught
 * afterwards, from a frozen source hash and manual evidence.
 */
final class LegacyRmeSourceRmFailure
{
    /** No source RM was supplied for an import that requires one. */
    public const SOURCE_RM_REQUIRED = 'SOURCE_RM_REQUIRED';

    /** The value cannot be a Nomor RM at all: empty after normalization, over-long, or illegal characters. */
    public const SOURCE_RM_INVALID = 'SOURCE_RM_INVALID';

    /** No patient carries this Nomor RM — in any branch, including soft-deleted rows. */
    public const SOURCE_RM_NOT_FOUND = 'SOURCE_RM_NOT_FOUND';

    /** The Nomor RM names more than one patient. A human fixes the master data; nothing here guesses. */
    public const SOURCE_RM_AMBIGUOUS = 'SOURCE_RM_AMBIGUOUS';

    /** The Nomor RM resolves cleanly — to a DIFFERENT patient than the one selected. */
    public const SOURCE_RM_PATIENT_MISMATCH = 'SOURCE_RM_PATIENT_MISMATCH';

    /** The branch the source RM implies is not the branch that owns the archive. */
    public const SOURCE_RM_BRANCH_MISMATCH = 'SOURCE_RM_BRANCH_MISMATCH';

    /**
     * A pre-enforcement staging row: created before this sprint, so no source RM
     * was ever captured for it.
     *
     * NOT the same as SOURCE_RM_REQUIRED. That one means "the operator did not
     * answer"; this one means "nobody was ever asked, and inventing an answer
     * now would be manufacturing evidence". The remedy is different too: such a
     * row is CANCELLED and re-imported, which is why cancel is deliberately the
     * one lifecycle action this domain never gates.
     */
    public const SOURCE_RM_CAPTURE_MISSING = 'SOURCE_RM_CAPTURE_MISSING';

    /** @var list<string> */
    public const CODES = [
        self::SOURCE_RM_REQUIRED,
        self::SOURCE_RM_INVALID,
        self::SOURCE_RM_NOT_FOUND,
        self::SOURCE_RM_AMBIGUOUS,
        self::SOURCE_RM_PATIENT_MISMATCH,
        self::SOURCE_RM_BRANCH_MISMATCH,
        self::SOURCE_RM_CAPTURE_MISSING,
    ];

    /** The input field a refusal is attached to on the HTTP boundary. */
    public const FIELD = 'source_rm_raw';

    private function __construct() {}

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }

    /**
     * Operator-facing explanation.
     *
     * DELIBERATELY NON-DISCLOSING. A refusal never names the patient the source
     * RM actually resolved to, never prints their id, and never says whether
     * some other number would have worked. The upload form is reachable by any
     * account holding `create_legacy_rme_imports`, and a message that answered
     * "that RM belongs to someone else, here is who" would turn this gate into
     * a patient-enumeration oracle. The operator is told what to do — re-read
     * the document, or fix the master data — which is all they need.
     */
    public static function explain(string $code): string
    {
        return match ($code) {
            self::SOURCE_RM_REQUIRED => 'Nomor RM yang tercetak pada dokumen wajib diisi.',
            self::SOURCE_RM_INVALID => 'Nomor RM pada dokumen tidak dapat dibaca sebagai Nomor RM yang sah. Periksa kembali penulisannya pada dokumen.',
            self::SOURCE_RM_NOT_FOUND => 'Nomor RM pada dokumen tidak terdaftar pada data pasien. Pastikan penulisannya benar, atau daftarkan pasien melalui pendaftaran resmi bila memang valid.',
            self::SOURCE_RM_AMBIGUOUS => 'Nomor RM pada dokumen cocok dengan lebih dari satu pasien. Perbaiki duplikasi data pasien terlebih dahulu.',
            self::SOURCE_RM_PATIENT_MISMATCH => 'Nomor RM pada dokumen tidak sesuai dengan pasien yang dipilih. Periksa kembali dokumen dan pilih pasien yang benar.',
            self::SOURCE_RM_BRANCH_MISMATCH => 'Cabang pada Nomor RM dokumen tidak sesuai dengan cabang arsip pasien ini.',
            self::SOURCE_RM_CAPTURE_MISSING => 'Impor ini dibuat sebelum Nomor RM dokumen wajib direkam, sehingga kecocokan pasien tidak dapat diverifikasi. Batalkan impor ini dan ulangi unggah dengan Nomor RM dokumen.',
            default => 'Status pengikatan Nomor RM dokumen tidak dikenal.',
        };
    }
}
