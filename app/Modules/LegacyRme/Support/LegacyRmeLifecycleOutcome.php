<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;

/**
 * LEGACY-RME-OPS-CLI-1 — the immutable result of a lifecycle operation, or of a
 * dry run that only asked whether one WOULD be allowed.
 *
 * PII POLICY, STATED PRECISELY.
 *
 * This object is what `--json` prints. Every STRUCTURED field carries structure
 * only: ids, a status, a branch id, a refusal code, a truncated source checksum.
 * Never a patient name, never a Nomor RM, never a KTP/NIK, never a filename,
 * never an absolute path, never clinical content. The checksum is truncated
 * because the operator only needs enough to match a document against their own
 * manifest, not to reconstruct anything.
 *
 * `refusalMessage` is the ONE deliberate exception, and it is called out rather
 * than glossed over. It is the canonical service's operator-facing explanation,
 * verbatim — the same sentence the browser shows for the same refusal — and the
 * date rules legitimately quote the DATES a refusal is about, including, in one
 * case, the patient's date of birth ("tanggal RME lama tidak boleh mendahului
 * tanggal lahir pasien"). Redacting it would leave an operator holding a refusal
 * they cannot act on.
 *
 * That is not a widening: the message only ever reaches a caller who has already
 * passed the capability, branch-scope, permission and policy gates for that
 * import — the same audience, with the same authority, that sees the identical
 * sentence in the workspace. A name, Nomor RM or KTP/NIK still never appears,
 * and that boundary is asserted by a test rather than assumed.
 */
final class LegacyRmeLifecycleOutcome
{
    /**
     * @param  list<string>  $blockers  refusal codes a dry run found; empty when eligible
     */
    private function __construct(
        public readonly string $action,
        public readonly bool $applied,
        public readonly bool $eligible,
        public readonly ?int $importId,
        public readonly ?string $status,
        public readonly ?string $previousStatus,
        public readonly ?string $targetStatus,
        public readonly ?int $patientId,
        public readonly ?int $originBranchId,
        public readonly ?int $actorId,
        public readonly ?int $recordId,
        public readonly ?string $sourceChecksumPrefix,
        public readonly ?string $refusalCode,
        public readonly ?string $refusalMessage,
        public readonly array $blockers,
        public readonly bool $changed,
        public readonly string $channel,
    ) {}

    /**
     * A mutation that actually ran through the canonical service.
     */
    public static function applied(
        string $action,
        LegacyRmeImport $import,
        ?string $previousStatus,
        ?int $actorId,
        string $channel,
        ?LegacyRmeRecord $record = null,
        bool $changed = true,
    ): self {
        return new self(
            action: $action,
            applied: true,
            eligible: true,
            importId: $import->getKey() !== null ? (int) $import->getKey() : null,
            status: $import->status,
            previousStatus: $previousStatus,
            targetStatus: LegacyRmeLifecycleAction::targetStatus($action),
            patientId: $import->patient_id !== null ? (int) $import->patient_id : null,
            originBranchId: $import->origin_branch_id !== null ? (int) $import->origin_branch_id : null,
            actorId: $actorId,
            recordId: $record?->getKey() !== null ? (int) $record->getKey() : null,
            sourceChecksumPrefix: self::checksumPrefix($import->source_pdf_sha256),
            refusalCode: null,
            refusalMessage: null,
            blockers: [],
            changed: $changed,
            channel: $channel,
        );
    }

    /**
     * A read-only preflight. Nothing was written, whatever `eligible` says.
     *
     * @param  list<string>  $blockers
     */
    public static function preview(
        string $action,
        LegacyRmeImport $import,
        ?int $actorId,
        string $channel,
        array $blockers,
        ?string $refusalMessage = null,
    ): self {
        return new self(
            action: $action,
            applied: false,
            eligible: $blockers === [],
            importId: $import->getKey() !== null ? (int) $import->getKey() : null,
            status: $import->status,
            previousStatus: null,
            targetStatus: LegacyRmeLifecycleAction::targetStatus($action),
            patientId: $import->patient_id !== null ? (int) $import->patient_id : null,
            originBranchId: $import->origin_branch_id !== null ? (int) $import->origin_branch_id : null,
            actorId: $actorId,
            recordId: null,
            sourceChecksumPrefix: self::checksumPrefix($import->source_pdf_sha256),
            refusalCode: $blockers[0] ?? null,
            refusalMessage: $refusalMessage,
            blockers: $blockers,
            changed: false,
            channel: $channel,
        );
    }

    /**
     * A refusal that never reached an import (or reached one and was declined).
     * Nothing was written.
     */
    public static function refused(
        string $action,
        string $refusalCode,
        string $refusalMessage,
        string $channel,
        ?int $importId = null,
        ?int $actorId = null,
    ): self {
        return new self(
            action: $action,
            applied: false,
            eligible: false,
            importId: $importId,
            status: null,
            previousStatus: null,
            targetStatus: LegacyRmeLifecycleAction::targetStatus($action),
            patientId: null,
            originBranchId: null,
            actorId: $actorId,
            recordId: null,
            sourceChecksumPrefix: null,
            refusalCode: $refusalCode,
            refusalMessage: $refusalMessage,
            blockers: [$refusalCode],
            changed: false,
            channel: $channel,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'channel' => $this->channel,
            'applied' => $this->applied,
            'eligible' => $this->eligible,
            'changed' => $this->changed,
            'import_id' => $this->importId,
            'status' => $this->status,
            'previous_status' => $this->previousStatus,
            'target_status' => $this->targetStatus,
            'patient_id' => $this->patientId,
            'origin_branch_id' => $this->originBranchId,
            'actor_id' => $this->actorId,
            'legacy_record_id' => $this->recordId,
            'source_sha256_prefix' => $this->sourceChecksumPrefix,
            'refusal_code' => $this->refusalCode,
            'refusal_message' => $this->refusalMessage,
            'blockers' => $this->blockers,
        ];
    }

    private static function checksumPrefix(?string $checksum): ?string
    {
        if (! is_string($checksum) || $checksum === '') {
            return null;
        }

        return mb_substr($checksum, 0, 12);
    }
}
