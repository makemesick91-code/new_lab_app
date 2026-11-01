<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Support;

use Illuminate\Validation\ValidationException;

/**
 * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — stable refusal codes of
 * the doctor's structured treatment record. Every refusal answers under the ONE
 * key `performed_treatment` and its message starts with `[CODE]`; branch on the
 * code, never the text.
 */
final class RmePerformedTreatmentRefusal
{
    public const KEY = 'performed_treatment';

    public const NOT_ENABLED = 'DPT_NOT_ENABLED';

    public const ENCOUNTER_NOT_ACTIVE = 'DPT_ENCOUNTER_NOT_ACTIVE';

    public const CONSENT_REQUIRED = 'DPT_CONSENT_REQUIRED';

    public const ACTOR_NOT_AUTHORIZED = 'DPT_ACTOR_NOT_AUTHORIZED';

    public const NOT_TREATING_DOCTOR = 'DPT_NOT_TREATING_DOCTOR';

    public const TREATMENT_NOT_SELECTABLE = 'DPT_TREATMENT_NOT_SELECTABLE';

    public const QUANTITY_INVALID = 'DPT_QUANTITY_INVALID';

    public const TOOTH_INVALID = 'DPT_TOOTH_INVALID';

    public const DUPLICATE_ACTIVE = 'DPT_DUPLICATE_ACTIVE';

    public const DECLARED_NO_TREATMENT = 'DPT_DECLARED_NO_TREATMENT';

    public const HAS_ACTIVE_TREATMENTS = 'DPT_HAS_ACTIVE_TREATMENTS';

    public const DECLARATION_INVALID = 'DPT_DECLARATION_INVALID';

    public const ROW_NOT_ACTIVE = 'DPT_ROW_NOT_ACTIVE';

    public const VOID_REASON_REQUIRED = 'DPT_VOID_REASON_REQUIRED';

    public const SUBMISSION_INVALID = 'DPT_SUBMISSION_INVALID';

    /** A submission token reused for a DIFFERENT payload (a stale form): refused, never a silent replay. */
    public const SUBMISSION_REUSED = 'DPT_SUBMISSION_REUSED';

    public static function message(string $code): string
    {
        $text = match ($code) {
            self::NOT_ENABLED => 'Pencatatan Tindakan Dilakukan belum diaktifkan.',
            self::ENCOUNTER_NOT_ACTIVE => 'Tindakan hanya dapat dicatat saat kunjungan ini sedang dalam pemeriksaan (sudah "Mulai Pemeriksaan" dan belum "Selesai Pemeriksaan").',
            self::CONSENT_REQUIRED => 'Tindakan kunjungan ini belum dapat dicatat karena Persetujuan Tindakan Medis belum ditandatangani.',
            self::ACTOR_NOT_AUTHORIZED => 'Anda tidak berwenang mencatat tindakan untuk pemeriksaan pasien ini.',
            self::NOT_TREATING_DOCTOR => 'Tindakan hanya dapat dicatat oleh dokter yang menangani kunjungan ini dengan akun yang terhubung ke data dokter aktif.',
            self::TREATMENT_NOT_SELECTABLE => 'Tindakan tidak ditemukan, tidak aktif, atau bukan tindakan medis yang dapat dicatat (konsultasi, kontrol, dan voucher tidak dicatat sebagai tindakan).',
            self::QUANTITY_INVALID => 'Jumlah tindakan harus berupa bilangan bulat antara 1 dan 32.',
            self::TOOTH_INVALID => 'Nomor gigi tidak valid. Gunakan nomor FDI (11–18, 21–28, 31–38, 41–48, 51–55, 61–65, 71–75, 81–85).',
            self::DUPLICATE_ACTIVE => 'Tindakan yang sama pada gigi yang sama sudah tercatat. Ubah jumlahnya dengan membatalkan baris lama lalu mencatat ulang.',
            self::DECLARED_NO_TREATMENT => 'Kunjungan ini sudah dinyatakan "Tidak Ada Tindakan". Pilih "Ada Tindakan" terlebih dahulu sebelum mencatat tindakan.',
            self::HAS_ACTIVE_TREATMENTS => 'Masih ada tindakan yang tercatat. Batalkan setiap tindakan (dengan alasan) sebelum menyatakan "Tidak Ada Tindakan".',
            self::DECLARATION_INVALID => 'Pilihan deklarasi tindakan tidak valid.',
            self::ROW_NOT_ACTIVE => 'Tindakan ini sudah dibatalkan dan tidak dapat diubah lagi.',
            self::VOID_REASON_REQUIRED => 'Alasan pembatalan tindakan wajib diisi.',
            self::SUBMISSION_INVALID => 'Formulir tidak valid. Muat ulang halaman lalu coba lagi.',
            self::SUBMISSION_REUSED => 'Formulir ini sudah pernah dikirim untuk tindakan lain. Muat ulang halaman lalu catat ulang tindakan ini.',
            default => 'Permintaan ditolak.',
        };

        return '['.$code.'] '.$text;
    }

    public static function exception(string $code): ValidationException
    {
        return ValidationException::withMessages([self::KEY => self::message($code)]);
    }
}
