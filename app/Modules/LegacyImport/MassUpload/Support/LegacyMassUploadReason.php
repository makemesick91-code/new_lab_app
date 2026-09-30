<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Support;

/**
 * Stable machine reasons for FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 (§21).
 *
 * These codes are a CONTRACT. They appear in staging rows, in the downloadable
 * report and in audit payloads, so renaming one silently breaks an operator's
 * saved filter and every historical report. Add new codes; do not repurpose old
 * ones.
 *
 * Two hard rules about the messages:
 *
 *   1. No SQL, no exception class, no stack frame, no absolute path, no disk
 *      name. An operator-facing message that leaks internals is both a support
 *      burden and a disclosure surface.
 *   2. No patient name, no identity number, no clinical content. The report is
 *      a spreadsheet that gets emailed; the row that leaks is always the one
 *      nobody meant to include. The medical record number is the ONLY patient
 *      identifier permitted, because the operator supplied it themselves and
 *      cannot act on the report without it.
 */
final class LegacyMassUploadReason
{
    // ---------------------------------------------------------------------
    // PACKAGE-INTEGRITY refusals (§17) — the batch never dispatches an item.
    // ---------------------------------------------------------------------

    public const PACKAGE_UNREADABLE = 'PACKAGE_UNREADABLE';

    public const PACKAGE_NOT_ZIP = 'PACKAGE_NOT_ZIP';

    public const PACKAGE_TOO_LARGE = 'PACKAGE_TOO_LARGE';

    public const PACKAGE_TOO_MANY_ENTRIES = 'PACKAGE_TOO_MANY_ENTRIES';

    public const PACKAGE_UNCOMPRESSED_TOO_LARGE = 'PACKAGE_UNCOMPRESSED_TOO_LARGE';

    public const PACKAGE_COMPRESSION_RATIO = 'PACKAGE_COMPRESSION_RATIO';

    public const PACKAGE_UNSAFE_PATH = 'PACKAGE_UNSAFE_PATH';

    public const PACKAGE_ABSOLUTE_PATH = 'PACKAGE_ABSOLUTE_PATH';

    public const PACKAGE_SYMLINK_ENTRY = 'PACKAGE_SYMLINK_ENTRY';

    public const PACKAGE_DUPLICATE_ENTRY = 'PACKAGE_DUPLICATE_ENTRY';

    public const MANIFEST_MISSING = 'MANIFEST_MISSING';

    public const MANIFEST_TOO_LARGE = 'MANIFEST_TOO_LARGE';

    public const MANIFEST_UNREADABLE = 'MANIFEST_UNREADABLE';

    public const MANIFEST_HEADER_INVALID = 'MANIFEST_HEADER_INVALID';

    public const MANIFEST_HEADER_FORBIDDEN = 'MANIFEST_HEADER_FORBIDDEN';

    public const MANIFEST_EMPTY = 'MANIFEST_EMPTY';

    public const MANIFEST_DUPLICATE_FILE = 'MANIFEST_DUPLICATE_FILE';

    public const MANIFEST_TOO_MANY_ROWS = 'MANIFEST_TOO_MANY_ROWS';

    // ---------------------------------------------------------------------
    // ITEM-eligibility refusals (§21) — this row only; siblings proceed.
    // ---------------------------------------------------------------------

    public const PATIENT_NOT_FOUND = 'PATIENT_NOT_FOUND';

    public const PATIENT_AMBIGUOUS = 'PATIENT_AMBIGUOUS';

    public const PATIENT_NOT_AUTHORIZED = 'PATIENT_NOT_AUTHORIZED';

    public const BRANCH_MISMATCH = 'BRANCH_MISMATCH';

    public const BRANCH_NOT_ADMITTED = 'BRANCH_NOT_ADMITTED';

    public const ALREADY_PUBLISHED = 'ALREADY_PUBLISHED';

    public const ACTIVE_IMPORT_EXISTS = 'ACTIVE_IMPORT_EXISTS';

    public const DATE_MISSING = 'DATE_MISSING';

    public const DATE_INVALID = 'DATE_INVALID';

    public const DATE_RANGE_INVALID = 'DATE_RANGE_INVALID';

    public const NATIVE_RME_BOUNDARY = 'NATIVE_RME_BOUNDARY';

    /**
     * The odontogram equivalent, kept separate rather than folded into
     * NATIVE_RME_BOUNDARY.
     *
     * The two boundaries are computed from different native sources and can
     * disagree for the same patient, so one shared code would tell an operator
     * to go and look at the wrong record.
     */
    public const NATIVE_ODONTOGRAM_BOUNDARY = 'NATIVE_ODONTOGRAM_BOUNDARY';

    public const SOURCE_RM_MISSING = 'SOURCE_RM_MISSING';

    public const SOURCE_RM_MISMATCH = 'SOURCE_RM_MISMATCH';

    /**
     * The manifest RM is not a well-formed, complete medical record number.
     *
     * Kept distinct from SOURCE_RM_MISMATCH because the operator action is
     * completely different, and at 250 rows the wrong instruction is expensive.
     *
     * This is the case that makes the binding gate matter in bulk. The patient
     * lookup falls back to a SUFFIX match across every branch, so a partial
     * manifest value like `27541` will happily find `TLK1-2019-27541`. The
     * canonical binding service then resolves that same value EXACTLY, finds it
     * is not a valid whole number, and refuses. That refusal is correct — but
     * "does not refer to the same patient" would send the operator hunting a
     * mix-up that does not exist, when the real fix is to write the RM in full.
     */
    public const SOURCE_RM_INVALID = 'SOURCE_RM_INVALID';

    public const DUPLICATE_SOURCE = 'DUPLICATE_SOURCE';

    public const DUPLICATE_MANIFEST_PATIENT = 'DUPLICATE_MANIFEST_PATIENT';

    public const MANIFEST_FILE_MISSING = 'MANIFEST_FILE_MISSING';

    public const FILE_INVALID = 'FILE_INVALID';

    public const FILE_NOT_PDF = 'FILE_NOT_PDF';

    public const FILE_TOO_LARGE = 'FILE_TOO_LARGE';

    public const CAPACITY_EXCEEDED = 'CAPACITY_EXCEEDED';

    public const FEATURE_DISABLED = 'FEATURE_DISABLED';

    public const SLOT_LOCK_UNAVAILABLE = 'SLOT_LOCK_UNAVAILABLE';

    public const OPERATIONS_NOT_CLEARED = 'OPERATIONS_NOT_CLEARED';

    /**
     * Refused by the canonical single-item service for a reason mass upload
     * does not model explicitly.
     *
     * This exists because the alternative is worse. The single-item services
     * carry many gates (wave binding, steady-state ops, rollout readiness) and
     * enumerating each one here would be a second copy of their rules that
     * drifts the first time one of them changes. When the domain refuses with
     * something we do not recognise, we record THAT honestly rather than
     * guessing a prettier code — and the validation message the domain itself
     * produced is carried through, because the domain wrote it for an operator.
     */
    public const DOMAIN_REFUSED = 'DOMAIN_REFUSED';

    /** Technical failure, not a domain refusal. Retry is meaningful. */
    public const PROCESSING_ERROR = 'PROCESSING_ERROR';

    /** Batch stopped before this row was reached. */
    public const NOT_ATTEMPTED = 'NOT_ATTEMPTED';

    /**
     * Operator-safe message for each code. Deliberately free of internals.
     *
     * @var array<string, string>
     */
    private const MESSAGES = [
        self::PACKAGE_UNREADABLE => 'Berkas paket tidak dapat dibaca sebagai arsip ZIP yang valid.',
        self::PACKAGE_NOT_ZIP => 'Berkas yang diunggah bukan arsip ZIP.',
        self::PACKAGE_TOO_LARGE => 'Ukuran arsip melebihi batas yang diizinkan.',
        self::PACKAGE_TOO_MANY_ENTRIES => 'Jumlah berkas di dalam arsip melebihi batas yang diizinkan.',
        self::PACKAGE_UNCOMPRESSED_TOO_LARGE => 'Total ukuran isi arsip setelah diekstrak melebihi batas yang diizinkan.',
        self::PACKAGE_COMPRESSION_RATIO => 'Rasio kompresi arsip tidak wajar sehingga arsip ditolak.',
        self::PACKAGE_UNSAFE_PATH => 'Arsip memuat jalur berkas yang tidak aman.',
        self::PACKAGE_ABSOLUTE_PATH => 'Arsip memuat jalur berkas absolut yang tidak diizinkan.',
        self::PACKAGE_SYMLINK_ENTRY => 'Arsip memuat tautan simbolik yang tidak diizinkan.',
        self::PACKAGE_DUPLICATE_ENTRY => 'Arsip memuat nama berkas ganda yang tidak dapat dipetakan secara pasti.',
        self::MANIFEST_MISSING => 'Arsip tidak memuat berkas manifest.csv di akar arsip.',
        self::MANIFEST_TOO_LARGE => 'Ukuran manifest melebihi batas yang diizinkan.',
        self::MANIFEST_UNREADABLE => 'Manifest tidak dapat dibaca.',
        self::MANIFEST_HEADER_INVALID => 'Kolom manifest tidak sesuai dengan format yang diharapkan.',
        self::MANIFEST_HEADER_FORBIDDEN => 'Manifest memuat kolom yang tidak diizinkan.',
        self::MANIFEST_EMPTY => 'Manifest tidak memuat satu pun baris data.',
        self::MANIFEST_DUPLICATE_FILE => 'Manifest merujuk nama berkas yang sama lebih dari satu kali.',
        self::MANIFEST_TOO_MANY_ROWS => 'Jumlah baris manifest melebihi batas yang diizinkan.',

        self::PATIENT_NOT_FOUND => 'Nomor RM tidak ditemukan pada data pasien.',
        self::PATIENT_AMBIGUOUS => 'Nomor RM cocok dengan lebih dari satu pasien sehingga tidak dapat dipastikan.',
        self::PATIENT_NOT_AUTHORIZED => 'Pasien berada di luar cakupan akses Anda.',
        self::BRANCH_MISMATCH => 'Cabang pasien tidak sesuai dengan cakupan cabang Anda.',
        self::BRANCH_NOT_ADMITTED => 'Cabang pasien belum diizinkan untuk migrasi arsip lama.',
        self::ALREADY_PUBLISHED => 'Pasien sudah memiliki dokumen arsip lama yang terbit untuk jenis ini.',
        self::ACTIVE_IMPORT_EXISTS => 'Pasien sudah memiliki proses impor aktif untuk jenis dokumen ini.',
        self::DATE_MISSING => 'Tanggal dokumen wajib diisi pada manifest.',
        self::DATE_INVALID => 'Tanggal pada manifest tidak valid.',
        self::DATE_RANGE_INVALID => 'Rentang tanggal pada manifest tidak valid.',
        self::NATIVE_RME_BOUNDARY => 'Tanggal dokumen melanggar batas rekam medis elektronik yang sudah ada.',
        self::NATIVE_ODONTOGRAM_BOUNDARY => 'Tanggal dokumen melanggar batas odontogram elektronik yang sudah ada.',
        self::SOURCE_RM_MISSING => 'Nomor RM pada dokumen wajib diisi pada manifest.',
        self::SOURCE_RM_MISMATCH => 'Nomor RM pada manifest tidak merujuk pasien yang sama.',
        self::SOURCE_RM_INVALID => 'Nomor RM pada manifest tidak lengkap atau bukan Nomor RM yang sah. Tulis Nomor RM lengkap sesuai dokumen.',
        self::DUPLICATE_SOURCE => 'Dokumen yang sama sudah pernah diimpor.',
        self::DUPLICATE_MANIFEST_PATIENT => 'Pasien yang sama muncul lebih dari satu kali pada manifest ini.',
        self::MANIFEST_FILE_MISSING => 'Berkas yang dirujuk manifest tidak ada di dalam arsip.',
        self::FILE_INVALID => 'Berkas dokumen tidak valid.',
        self::FILE_NOT_PDF => 'Berkas dokumen harus berupa PDF.',
        self::FILE_TOO_LARGE => 'Ukuran berkas dokumen melebihi batas yang diizinkan.',
        self::CAPACITY_EXCEEDED => 'Kapasitas migrasi untuk cabang ini sudah penuh.',
        self::FEATURE_DISABLED => 'Fitur migrasi arsip lama sedang tidak aktif.',
        self::SLOT_LOCK_UNAVAILABLE => 'Baris pasien ini sedang diproses oleh permintaan lain.',
        self::OPERATIONS_NOT_CLEARED => 'Operasi migrasi untuk cabang ini belum dibuka.',

        self::DOMAIN_REFUSED => 'Permintaan ditolak oleh aturan impor arsip lama.',
        self::PROCESSING_ERROR => 'Terjadi kegagalan teknis saat memproses baris ini.',
        self::NOT_ATTEMPTED => 'Baris ini belum diproses.',
    ];

    /**
     * Codes that mean "the archive itself is unusable". Used to decide whether
     * a refusal is a PACKAGE failure or an ITEM failure (§17).
     *
     * @return list<string>
     */
    public static function packageCodes(): array
    {
        return [
            self::PACKAGE_UNREADABLE,
            self::PACKAGE_NOT_ZIP,
            self::PACKAGE_TOO_LARGE,
            self::PACKAGE_TOO_MANY_ENTRIES,
            self::PACKAGE_UNCOMPRESSED_TOO_LARGE,
            self::PACKAGE_COMPRESSION_RATIO,
            self::PACKAGE_UNSAFE_PATH,
            self::PACKAGE_ABSOLUTE_PATH,
            self::PACKAGE_SYMLINK_ENTRY,
            self::PACKAGE_DUPLICATE_ENTRY,
            self::MANIFEST_MISSING,
            self::MANIFEST_TOO_LARGE,
            self::MANIFEST_UNREADABLE,
            self::MANIFEST_HEADER_INVALID,
            self::MANIFEST_HEADER_FORBIDDEN,
            self::MANIFEST_EMPTY,
            self::MANIFEST_DUPLICATE_FILE,
            self::MANIFEST_TOO_MANY_ROWS,
        ];
    }

    public static function isPackageCode(string $code): bool
    {
        return in_array($code, self::packageCodes(), true);
    }

    public static function isValid(string $code): bool
    {
        return array_key_exists($code, self::MESSAGES);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::MESSAGES);
    }

    /**
     * Operator-safe message. An unknown code degrades to the generic domain
     * refusal rather than echoing the code as prose, so a typo in a caller can
     * never surface as a fake message.
     */
    /**
     * Translate a canonical source-RM binding failure into our vocabulary.
     *
     * Both adapters call the SAME canonical binding service, so the mapping
     * lives here once rather than twice. Unknown codes fall through to the
     * mismatch reason: it is the conservative reading, and a code we do not
     * recognise must never become "looks fine".
     */
    public static function fromSourceRmFailure(?string $bindingCode): string
    {
        return match ($bindingCode) {
            'SOURCE_RM_REQUIRED', 'SOURCE_RM_CAPTURE_MISSING' => self::SOURCE_RM_MISSING,
            'SOURCE_RM_INVALID' => self::SOURCE_RM_INVALID,
            'SOURCE_RM_NOT_FOUND' => self::PATIENT_NOT_FOUND,
            'SOURCE_RM_AMBIGUOUS' => self::PATIENT_AMBIGUOUS,
            'SOURCE_RM_BRANCH_MISMATCH' => self::BRANCH_MISMATCH,
            default => self::SOURCE_RM_MISMATCH,
        };
    }

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? self::MESSAGES[self::DOMAIN_REFUSED];
    }
}
