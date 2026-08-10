<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

/**
 * LEGACY-RME-PDF-1B — stable failure vocabulary for the PDF pipeline.
 *
 * Callers (form requests, services, the queued job, the UI, tests) branch on
 * the CODE, never on the human message — exactly as the 1A date rules do.
 *
 * Every message here is safe to render: it never contains a filesystem path, a
 * raw process command line, a stack trace, or any patient data.
 */
final class LegacyRmePdfFailure
{
    public const INVALID_PDF = 'INVALID_PDF';

    public const PDF_HEADER_INVALID = 'PDF_HEADER_INVALID';

    public const PDF_INSPECTION_FAILED = 'PDF_INSPECTION_FAILED';

    public const PDF_ENCRYPTED = 'PDF_ENCRYPTED';

    public const PDF_PASSWORD_PROTECTED = 'PDF_PASSWORD_PROTECTED';

    public const PDF_PAGE_COUNT_INVALID = 'PDF_PAGE_COUNT_INVALID';

    public const PDF_PAGE_LIMIT_EXCEEDED = 'PDF_PAGE_LIMIT_EXCEEDED';

    public const PDF_DIMENSION_LIMIT_EXCEEDED = 'PDF_DIMENSION_LIMIT_EXCEEDED';

    public const PDF_FILE_TOO_LARGE = 'PDF_FILE_TOO_LARGE';

    public const PDF_MALWARE_DETECTED = 'PDF_MALWARE_DETECTED';

    public const PDF_STORAGE_FAILED = 'PDF_STORAGE_FAILED';

    public const PDF_PROCESS_TIMEOUT = 'PDF_PROCESS_TIMEOUT';

    public const PDF_RENDER_FAILED = 'PDF_RENDER_FAILED';

    public const PAGE_OUTPUT_COUNT_MISMATCH = 'PAGE_OUTPUT_COUNT_MISMATCH';

    public const PAGE_IMAGE_INVALID = 'PAGE_IMAGE_INVALID';

    public const RENDER_SIZE_LIMIT_EXCEEDED = 'RENDER_SIZE_LIMIT_EXCEEDED';

    public const SOURCE_FILE_MISSING = 'SOURCE_FILE_MISSING';

    public const DUPLICATE_SAME_PATIENT = 'DUPLICATE_SAME_PATIENT';

    public const DUPLICATE_OTHER_PATIENT = 'DUPLICATE_OTHER_PATIENT';

    public const IMPORT_NOT_PROCESSABLE = 'IMPORT_NOT_PROCESSABLE';

    public const IMPORT_NOT_RETRYABLE = 'IMPORT_NOT_RETRYABLE';

    public const IMPORT_NOT_CANCELLABLE = 'IMPORT_NOT_CANCELLABLE';

    /*
    |--------------------------------------------------------------------------
    | LEGACY-RME-PDF-1C — review and publish
    |--------------------------------------------------------------------------
    |
    | Same vocabulary class on purpose: these are workflow refusals in exactly
    | the same domain, and a second failure family would only give callers two
    | places to look for one concept.
    */

    public const IMPORT_NOT_REVIEWABLE = 'IMPORT_NOT_REVIEWABLE';

    public const IMPORT_NOT_PUBLISHABLE = 'IMPORT_NOT_PUBLISHABLE';

    public const RENDERED_PAGES_MISSING = 'RENDERED_PAGES_MISSING';

    public const PAGE_FILE_MISSING = 'PAGE_FILE_MISSING';

    public const PAGE_COUNT_MISMATCH = 'PAGE_COUNT_MISMATCH';

    /** @var list<string> */
    public const ALL = [
        self::INVALID_PDF,
        self::PDF_HEADER_INVALID,
        self::PDF_INSPECTION_FAILED,
        self::PDF_ENCRYPTED,
        self::PDF_PASSWORD_PROTECTED,
        self::PDF_PAGE_COUNT_INVALID,
        self::PDF_PAGE_LIMIT_EXCEEDED,
        self::PDF_DIMENSION_LIMIT_EXCEEDED,
        self::PDF_FILE_TOO_LARGE,
        self::PDF_MALWARE_DETECTED,
        self::PDF_STORAGE_FAILED,
        self::PDF_PROCESS_TIMEOUT,
        self::PDF_RENDER_FAILED,
        self::PAGE_OUTPUT_COUNT_MISMATCH,
        self::PAGE_IMAGE_INVALID,
        self::RENDER_SIZE_LIMIT_EXCEEDED,
        self::SOURCE_FILE_MISSING,
        self::DUPLICATE_SAME_PATIENT,
        self::DUPLICATE_OTHER_PATIENT,
        self::IMPORT_NOT_PROCESSABLE,
        self::IMPORT_NOT_RETRYABLE,
        self::IMPORT_NOT_CANCELLABLE,
        self::IMPORT_NOT_REVIEWABLE,
        self::IMPORT_NOT_PUBLISHABLE,
        self::RENDERED_PAGES_MISSING,
        self::PAGE_FILE_MISSING,
        self::PAGE_COUNT_MISMATCH,
    ];

    /** @var array<string, string> */
    private const MESSAGES = [
        self::INVALID_PDF => 'Berkas yang diunggah bukan dokumen PDF yang valid.',
        self::PDF_HEADER_INVALID => 'Berkas tidak diawali penanda PDF yang sah.',
        self::PDF_INSPECTION_FAILED => 'Struktur PDF tidak dapat dibaca. Pastikan berkas tidak rusak.',
        self::PDF_ENCRYPTED => 'PDF terenkripsi dan tidak dapat diproses. Gunakan salinan tanpa enkripsi.',
        self::PDF_PASSWORD_PROTECTED => 'PDF terkunci kata sandi. Gunakan salinan tanpa kata sandi.',
        self::PDF_PAGE_COUNT_INVALID => 'Jumlah halaman PDF tidak valid.',
        self::PDF_PAGE_LIMIT_EXCEEDED => 'Jumlah halaman PDF melebihi batas yang diizinkan.',
        self::PDF_DIMENSION_LIMIT_EXCEEDED => 'Ukuran halaman PDF melebihi batas yang diizinkan.',
        self::PDF_FILE_TOO_LARGE => 'Ukuran berkas PDF melebihi batas yang diizinkan.',
        self::PDF_MALWARE_DETECTED => 'Berkas ditolak oleh pemindaian keamanan.',
        self::PDF_STORAGE_FAILED => 'Berkas gagal disimpan. Coba ulangi proses unggah.',
        self::PDF_PROCESS_TIMEOUT => 'Pemrosesan PDF melebihi batas waktu.',
        self::PDF_RENDER_FAILED => 'Halaman PDF gagal dirender menjadi gambar.',
        self::PAGE_OUTPUT_COUNT_MISMATCH => 'Jumlah halaman hasil render tidak sesuai dengan jumlah halaman dokumen.',
        self::PAGE_IMAGE_INVALID => 'Gambar halaman hasil render tidak valid.',
        self::RENDER_SIZE_LIMIT_EXCEEDED => 'Total ukuran hasil render melebihi batas yang diizinkan.',
        self::SOURCE_FILE_MISSING => 'Berkas sumber tidak ditemukan pada penyimpanan.',
        self::DUPLICATE_SAME_PATIENT => 'PDF identik sudah pernah dimasukkan untuk pasien ini.',
        self::DUPLICATE_OTHER_PATIENT => 'PDF identik telah terhubung ke pasien berbeda. Periksa pemilihan pasien.',
        self::IMPORT_NOT_PROCESSABLE => 'Impor ini tidak berada pada status yang dapat diproses.',
        self::IMPORT_NOT_RETRYABLE => 'Impor ini tidak dapat diproses ulang.',
        self::IMPORT_NOT_CANCELLABLE => 'Impor ini tidak dapat dibatalkan.',
        self::IMPORT_NOT_REVIEWABLE => 'Impor ini tidak berada pada status yang dapat ditinjau.',
        self::IMPORT_NOT_PUBLISHABLE => 'Impor ini tidak berada pada status yang dapat dipublikasikan.',
        self::RENDERED_PAGES_MISSING => 'Hasil render halaman belum lengkap, arsip belum dapat dipublikasikan.',
        self::PAGE_FILE_MISSING => 'Berkas gambar halaman tidak ditemukan pada penyimpanan.',
        self::PAGE_COUNT_MISMATCH => 'Jumlah halaman siap tidak sesuai dengan jumlah halaman dokumen.',
    ];

    private function __construct() {}

    public static function isValid(?string $code): bool
    {
        return $code !== null && in_array($code, self::ALL, true);
    }

    public static function message(?string $code): string
    {
        return self::MESSAGES[$code] ?? 'Pemrosesan arsip RME lama gagal.';
    }
}
