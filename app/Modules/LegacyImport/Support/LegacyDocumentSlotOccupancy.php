<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\Support;

/**
 * REVISION-LEGACY-SINGLE-ACTIVE-DOCUMENT-PER-PATIENT-1 — the answer to
 * "may this patient begin a NEW legacy lifecycle of this type?".
 *
 * Immutable, and deliberately richer than a boolean: an operator who is refused
 * needs to know WHICH action is open to them, and those actions differ. A
 * PUBLISHED archive is corrected by a reasoned VOID; an in-flight import is
 * cleared by finishing or cancelling it. Collapsing both into "duplicate" would
 * leave the operator with no next step, so the reason code is part of the
 * contract and callers branch on the CODE, never on the message text.
 *
 * PII-free by construction: ids, a status string and a type. No patient name, no
 * Nomor RM, no KTP/NIK, no clinical content — this object is safe to put into an
 * audit payload verbatim.
 */
final class LegacyDocumentSlotOccupancy
{
    /**
     * A published legacy archive already holds the slot. Correction path: VOID
     * by an authorized actor, then a fresh import.
     */
    public const REASON_ALREADY_PUBLISHED = 'ALREADY_PUBLISHED';

    /**
     * A live, still-advanceable import already holds the slot. Correction path:
     * finish it, or cancel it. Never a second parallel upload.
     */
    public const REASON_ACTIVE_IMPORT_EXISTS = 'ACTIVE_IMPORT_EXISTS';

    private function __construct(
        public readonly string $type,
        public readonly int $patientId,
        public readonly bool $occupied,
        public readonly ?string $reason,
        public readonly ?int $blockingImportId,
        public readonly ?int $blockingRecordId,
        public readonly ?string $blockingStatus,
    ) {}

    public static function available(string $type, int $patientId): self
    {
        return new self($type, $patientId, false, null, null, null, null);
    }

    public static function alreadyPublished(
        string $type,
        int $patientId,
        int $recordId,
        ?int $importId,
    ): self {
        return new self(
            $type,
            $patientId,
            true,
            self::REASON_ALREADY_PUBLISHED,
            $importId,
            $recordId,
            'PUBLISHED',
        );
    }

    public static function activeImport(
        string $type,
        int $patientId,
        int $importId,
        string $importStatus,
    ): self {
        return new self(
            $type,
            $patientId,
            true,
            self::REASON_ACTIVE_IMPORT_EXISTS,
            $importId,
            null,
            $importStatus,
        );
    }

    /**
     * The stable, machine-readable code, namespaced by document type, as
     * required for operator tooling and for the audit trail.
     *
     * e.g. LEGACY_RME_ALREADY_PUBLISHED, LEGACY_ODONTOGRAM_ACTIVE_IMPORT_EXISTS
     */
    public function code(): ?string
    {
        if ($this->reason === null) {
            return null;
        }

        return strtoupper($this->type).'_'.$this->reason;
    }

    /**
     * The operator-facing refusal, in Indonesian, matching the surrounding
     * legacy-import UX.
     *
     * The in-flight wording says "selesaikan atau batalkan" and NEVER mentions
     * VOID: VOID is not a legal transition from any live staging state, and
     * telling an operator to do something the server will refuse is how a
     * refusal message becomes a support ticket.
     */
    public function message(): string
    {
        $label = $this->documentLabel();

        return match ($this->reason) {
            self::REASON_ALREADY_PUBLISHED => sprintf(
                '%s sudah tersedia. Pasien ini telah memiliki %s berstatus Published, sehingga '
                .'upload %s baru tidak diperbolehkan. Jika dokumen yang sudah dipublikasikan '
                .'salah, gunakan prosedur VOID oleh petugas yang berwenang lalu lakukan upload ulang.',
                $label,
                $label,
                $label,
            ),
            self::REASON_ACTIVE_IMPORT_EXISTS => sprintf(
                'Upload %s sedang diproses. Pasien ini sudah memiliki proses %s yang belum '
                .'selesai (status: %s). Selesaikan atau batalkan proses tersebut sebelum '
                .'membuat upload baru.',
                $label,
                $label,
                $this->blockingStatus ?? '-',
            ),
            default => '',
        };
    }

    /**
     * Short status line for a read-only UI panel, where the full refusal
     * paragraph would be noise.
     */
    public function shortStatus(): string
    {
        return match ($this->reason) {
            self::REASON_ALREADY_PUBLISHED => 'Sudah dipublikasikan',
            self::REASON_ACTIVE_IMPORT_EXISTS => 'Sedang diproses ('.($this->blockingStatus ?? '-').')',
            default => 'Belum ada',
        };
    }

    public function documentLabel(): string
    {
        return match ($this->type) {
            LegacyImportType::LEGACY_RME => 'Legacy RME',
            LegacyImportType::LEGACY_ODONTOGRAM => 'Legacy Odontogram',
            default => LegacyImportType::label($this->type),
        };
    }

    /**
     * Structure-only audit payload. Every key here must also be present in the
     * consuming module's ALLOWED_METADATA_KEYS allow-list.
     *
     * @return array<string, mixed>
     */
    public function auditContext(): array
    {
        $context = [
            'patient_id' => $this->patientId,
            'slot_reason' => $this->reason,
        ];

        if ($this->blockingImportId !== null) {
            $context['blocking_import_id'] = $this->blockingImportId;
        }

        if ($this->blockingRecordId !== null) {
            $context['blocking_record_id'] = $this->blockingRecordId;
        }

        if ($this->blockingStatus !== null) {
            $context['blocking_status'] = $this->blockingStatus;
        }

        return $context;
    }
}
