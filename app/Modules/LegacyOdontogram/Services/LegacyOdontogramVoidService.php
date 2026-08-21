<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Services;

use App\Models\User;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramRecordRepositoryInterface;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramAuditEvent;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramRecordStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FIX-04b — VOID: the single exception to immutability, and the whole
 * correction story.
 *
 * A published legacy odontogram is never edited and never hard-deleted. When
 * one is wrong — the canonical case being a chart filed under the WRONG PATIENT
 * — it is VOIDed with a written reason and a fresh import is made. Voiding
 * RETRACTS without ERASING: the row stays readable and auditable (who published
 * it, who retracted it, why), while its bytes stop streaming immediately, so
 * the leak the void was meant to stop actually stops.
 *
 * VOID IS TERMINAL. There is no un-void: the status map allows PUBLISHED → VOID
 * and nothing out of VOID. Reinstating a retracted document by flipping a flag
 * would make "retracted" a suggestion rather than a fact; the supported path is
 * a fresh import, which produces a new record with its own provenance.
 *
 * The reason is required and minimum-length because "correction" is not a
 * reason, and a colleague reading the trail a year later needs to know what
 * actually happened.
 */
class LegacyOdontogramVoidService
{
    public function __construct(
        private readonly LegacyOdontogramRecordRepositoryInterface $records,
        private readonly LegacyOdontogramAuditService $audit,
    ) {}

    public function minReasonLength(): int
    {
        return max(1, (int) config('legacy_odontogram.void.min_reason_length', 10));
    }

    public function maxReasonLength(): int
    {
        return max($this->minReasonLength(), (int) config('legacy_odontogram.void.max_reason_length', 500));
    }

    /**
     * @throws ValidationException
     */
    public function void(LegacyOdontogramRecord $record, string $reason, ?User $actor = null): LegacyOdontogramRecord
    {
        $reason = $this->normalizeReason($reason);

        $outcome = DB::transaction(function () use ($record, $reason, $actor): array {
            $locked = $this->records->lockForUpdate((int) $record->getKey());

            if ($locked === null) {
                $this->refuse('Arsip odontogram lama tidak ditemukan.');
            }

            // Idempotent: voiding an already-voided record is a no-op, and
            // deliberately does NOT overwrite the original reason or actor.
            if ($locked->status === LegacyOdontogramRecordStatus::VOID) {
                return ['record' => $locked, 'voided' => false];
            }

            if (! LegacyOdontogramRecordStatus::canTransition($locked->status, LegacyOdontogramRecordStatus::VOID)) {
                $this->refuse('Arsip odontogram lama ini tidak dapat dibatalkan.');
            }

            return [
                'record' => $this->records->markVoided($locked, $actor?->getKey(), $reason),
                'voided' => true,
            ];
        });

        if ($outcome['voided']) {
            $this->audit->logRecordEvent(LegacyOdontogramAuditEvent::VOIDED, $outcome['record'], [
                'status' => LegacyOdontogramRecordStatus::VOID,
                // The LENGTH, never the text: a free-text reason may name a
                // patient, and the audit allow-list carries no free text.
                'void_reason_length' => mb_strlen($reason),
            ], $actor);
        }

        return $outcome['record'];
    }

    /**
     * @throws ValidationException
     */
    private function normalizeReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < $this->minReasonLength()) {
            throw ValidationException::withMessages([
                'void_reason' => sprintf(
                    'Alasan pembatalan wajib diisi minimal %d karakter.',
                    $this->minReasonLength(),
                ),
            ]);
        }

        return mb_substr($reason, 0, $this->maxReasonLength());
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['void_reason' => $message]);
    }
}
