<?php

declare(strict_types=1);

namespace App\Support\Legacy;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — a refusal to bind a
 * legacy document to a real visit.
 *
 * STABLE CODES, NOT STABLE PROSE. Callers and tests branch on `$refusalCode`;
 * the Indonesian message is for the operator and may be reworded without
 * breaking anything. This is the same split the legacy module already uses
 * (LegacyRmeDateRuleService::CODE_*, LegacyRmePdfFailure::*), and it exists so
 * a test can assert the RULE that fired rather than a sentence.
 *
 * WHY AN EXCEPTION AND NOT A RESULT OBJECT. Every caller of the binding
 * resolver is a write path about to consume real resources — hash a file,
 * store 20 MiB, reserve a quota slot, queue a render. There is no caller that
 * legitimately wants to continue past a refusal, so the type system should not
 * offer them the option. The date rules use a result object precisely because
 * they DO have a read-only caller (the create screen previews the bound);
 * binding has none.
 */
final class LegacyVisitBindingRefusal extends RuntimeException
{
    /** No visit was supplied at all — the preverified path requires one. */
    public const VISIT_REQUIRED = 'VISIT_REQUIRED';

    /**
     * The visit does not exist, is soft-deleted, or the actor may not see it.
     *
     * DELIBERATELY ONE CODE FOR THREE CAUSES. Telling an unauthorized caller
     * "that visit exists but is not yours" is an enumeration oracle over the
     * visit table. The operator-facing message is identical in all three cases.
     */
    public const VISIT_NOT_ACCESSIBLE = 'VISIT_NOT_ACCESSIBLE';

    /** The visit exists and is visible but cannot anchor an attestation. */
    public const VISIT_INVALID = 'VISIT_INVALID';

    /** A submitted patient id disagreed with the visit's own patient. */
    public const VISIT_PATIENT_MISMATCH = 'VISIT_PATIENT_MISMATCH';

    /** The human did not tick the date attestation. */
    public const ATTESTATION_REQUIRED = 'ATTESTATION_REQUIRED';

    /**
     * The stored document no longer matches the file the human attested to.
     *
     * The attestation is a statement about ONE set of bytes. If the source
     * hash has moved, a human certified a different document and the
     * statement is void — it is not repaired, and it is not re-derived.
     */
    public const SOURCE_CHANGED = 'SOURCE_CHANGED';

    /**
     * The attestation exists but is no longer usable at finalization time:
     * incomplete, or its visit has been cancelled, deleted or rescheduled.
     *
     * Deliberately NOT self-healing. Adopting the new state would silently
     * re-point a human's statement at facts they never saw.
     */
    public const PREVERIFIED_REVALIDATION_FAILED = 'PREVERIFIED_REVALIDATION_FAILED';

    /** @var list<string> */
    public const CODES = [
        self::VISIT_REQUIRED,
        self::VISIT_NOT_ACCESSIBLE,
        self::VISIT_INVALID,
        self::VISIT_PATIENT_MISMATCH,
        self::ATTESTATION_REQUIRED,
        self::SOURCE_CHANGED,
        self::PREVERIFIED_REVALIDATION_FAILED,
    ];

    /** Field the refusal is surfaced against at an input boundary. */
    public const FIELD = 'clinic_visit_id';

    private function __construct(
        public readonly string $refusalCode,
        string $message,
        public readonly string $field,
    ) {
        parent::__construct($message);
    }

    public static function visitRequired(): self
    {
        return new self(
            self::VISIT_REQUIRED,
            'Unggahan arsip legacy pada kunjungan harus terhubung ke satu kunjungan nyata.',
            self::FIELD,
        );
    }

    public static function visitNotAccessible(): self
    {
        return new self(
            self::VISIT_NOT_ACCESSIBLE,
            'Kunjungan tidak ditemukan atau tidak dapat diakses dari cabang Anda.',
            self::FIELD,
        );
    }

    public static function visitInvalid(string $reason): self
    {
        return new self(self::VISIT_INVALID, $reason, self::FIELD);
    }

    public static function patientMismatch(): self
    {
        return new self(
            self::VISIT_PATIENT_MISMATCH,
            'Pasien yang dikirim tidak sesuai dengan pasien pada kunjungan ini.',
            'patient_id',
        );
    }

    public static function sourceChanged(): self
    {
        return new self(
            self::SOURCE_CHANGED,
            'Dokumen sumber berbeda dari dokumen yang tanggalnya diverifikasi. '
            .'Verifikasi tanggal tidak lagi berlaku untuk berkas ini.',
            'document',
        );
    }

    public static function revalidationFailed(string $reason): self
    {
        return new self(self::PREVERIFIED_REVALIDATION_FAILED, $reason, self::FIELD);
    }

    public static function attestationRequired(): self
    {
        return new self(
            self::ATTESTATION_REQUIRED,
            'Anda harus menyatakan bahwa tanggal yang dimasukkan sesuai dengan dokumen asli.',
            'date_attestation',
        );
    }

    /**
     * Surface the refusal the way every other legacy input rule surfaces:
     * a field error on the form the operator is looking at.
     */
    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            $this->field => [$this->getMessage()],
        ]);
    }
}
