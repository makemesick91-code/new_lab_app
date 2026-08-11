<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\LegacyRme\Interfaces\LegacyRmeRecordRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeRecordStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-1D — retracting a published legacy RME record.
 *
 * WHAT VOID IS. A reasoned, audited retraction. The row is never deleted and
 * never edited: patient, date, file, checksums and pages all stay exactly as
 * published, and only status/voided_by/voided_at/void_reason are written. The
 * archive stops being part of the patient's active history and stops streaming
 * its bytes, but it remains readable as evidence of what was filed and why it
 * was withdrawn — retracted, not erased.
 *
 * WHAT VOID IS NOT. It is not a correction and not an undo. A corrected archive
 * is a VOID plus a FRESH import, because rewriting a published record in place
 * would destroy the very evidence trail the archive exists to provide. VOID is
 * therefore TERMINAL: the 1A transition map allows PUBLISHED → VOID and nothing
 * out of VOID, so there is no un-void and no republish.
 *
 * WHY A REASON IS MANDATORY. The canonical trigger is a mis-filed document —
 * an archive attached to the WRONG patient. Whoever reads this record later
 * must be able to tell a mis-file from a duplicate from a superseded scan, and
 * only the operator who retracted it knows which.
 *
 * ATOMICITY. One transaction that opens with a row lock and re-reads the status
 * under that lock, so a double click or two concurrent operators converge on a
 * single void with a single audit row rather than racing.
 *
 * SIDE EFFECTS. None. Voiding writes to exactly one table. It creates no clinic
 * visit, medical record, invoice, payment, consent, odontogram, lab
 * candidate/order or SATUSEHAT candidate, touches no visit status, deletes no
 * file from the private disk, and never contributes to visit or revenue KPI.
 */
class LegacyRmeVoidService
{
    /**
     * A reason short enough to be meaningless ("x", "salah") helps nobody
     * reading the trail years later, so a floor is enforced server-side rather
     * than only in the form.
     */
    public const MIN_REASON_LENGTH = 10;

    public const MAX_REASON_LENGTH = 500;

    public function __construct(
        private readonly LegacyRmeRecordRepositoryInterface $records,
        private readonly LegacyRmeAuditService $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function void(LegacyRmeRecord $record, string $reason, ?User $actor = null): LegacyRmeRecord
    {
        $reason = $this->normalizeReason($reason);

        /** @var array{record: LegacyRmeRecord, voided: bool} $outcome */
        $outcome = DB::transaction(function () use ($record, $reason, $actor): array {
            $locked = $this->records->lockForUpdate((int) $record->getKey());

            if ($locked === null) {
                $this->refuse('Arsip RME lama tidak ditemukan.');
            }

            // Voiding an already-voided archive is a harmless no-op: the
            // operator pressed the button twice, or two requests raced. It must
            // not look like an error and must not overwrite the original reason
            // or actor — the FIRST retraction is the one that counts.
            if ($locked->status === LegacyRmeRecordStatus::VOID) {
                return ['record' => $locked, 'voided' => false];
            }

            if (! LegacyRmeRecordStatus::canTransition($locked->status, LegacyRmeRecordStatus::VOID)) {
                $this->refuse('Arsip RME lama ini tidak dapat dibatalkan.');
            }

            return [
                'record' => $this->records->markVoided($locked, $actor?->getKey(), $reason),
                'voided' => true,
            ];
        });

        if ($outcome['voided']) {
            // Written after the transaction commits, so a rolled-back void can
            // never leave a "voided" row in the trail.
            //
            // The reason itself is NOT in the payload: it is operator free text
            // that may name a patient, and the audit payload allow-list is
            // structure-only. Its permanent home is the record's own
            // void_reason column, which is where a reader looks anyway.
            $this->audit->logRecordEvent(LegacyRmeAuditEvent::VOIDED, $outcome['record'], [
                'status' => LegacyRmeRecordStatus::VOID,
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

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'void_reason' => sprintf(
                    'Alasan pembatalan wajib diisi minimal %d karakter.',
                    self::MIN_REASON_LENGTH,
                ),
            ]);
        }

        return mb_substr($reason, 0, self::MAX_REASON_LENGTH);
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['void_reason' => $message]);
    }
}
