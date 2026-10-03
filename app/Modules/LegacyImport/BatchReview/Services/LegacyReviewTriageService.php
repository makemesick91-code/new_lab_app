<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Services;

use App\Models\User;
use App\Modules\LegacyImport\BatchReview\Adapters\LegacyBatchReviewAdapter;
use App\Modules\LegacyImport\BatchReview\Models\LegacyBatchReviewSession;
use App\Modules\LegacyImport\BatchReview\Models\LegacyReviewTriage;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewReason;
use App\Modules\LegacyImport\BatchReview\Support\LegacyReviewTriageStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The ONE place sticky review triage is set, changed and cleared — PR1 §5.
 *
 * THE INVARIANT THIS SERVICE PROTECTS
 * -----------------------------------
 * Triage is the SECOND axis. It never touches the first:
 *
 *     canonical import status = READY_FOR_REVIEW   (never written here)
 *     review_triage_status    = BLOCKED            (written only here)
 *
 * Nothing in this class calls a canonical import service, writes a canonical
 * import column, or invokes cancel(). Grep it: the only model it persists is
 * LegacyReviewTriage. That is what makes "Block" non-destructive, and it is
 * also why blocking cannot release the patient's single-active-document slot —
 * that slot is held by the canonical staging row's own status, and
 * LegacySingleActiveDocumentService does not consult this table at all.
 *
 * WHY CLEARING IS AUTHORIZED SEPARATELY FROM VIEWING
 * --------------------------------------------------
 * A block is a clinical judgement withholding a document from a patient's
 * permanent archive. Releasing it is therefore a review-authority act, not a
 * convenience. The owner's rule is that a block "cannot be cleared by the
 * uploader unless current review authorization explicitly permits that actor",
 * and this service enforces it by asking the ADAPTER — which answers using the
 * canonical policy truth for that document type:
 *
 *   - RME consults SeparatePublisherGuard, so an uploader who may not review
 *     their own document equally may not clear a block on it.
 *   - Odontogram has no separation guard, so an actor holding review permission
 *     within scope may clear even a document they uploaded — because that is
 *     exactly what the canonical odontogram policy permits for reviewing it.
 *
 * The rule is REUSED, never reinvented. A second expression of it here would be
 * free to drift away from the canonical one.
 */
class LegacyReviewTriageService
{
    public function __construct(
        private readonly LegacyBatchReviewAuditService $audit,
    ) {}

    /** The current triage row for one import, whatever its status. */
    public function currentFor(string $importType, string $foreignKey, int $importId): ?LegacyReviewTriage
    {
        return LegacyReviewTriage::query()
            ->where('import_type', $importType)
            ->where($foreignKey, $importId)
            ->first();
    }

    /**
     * THE CONTRACT PR2 CONSUMES: the triage row currently withholding this item
     * from publishing, or null.
     *
     * A CLEARED row answers null — the history is kept, the withholding is not.
     */
    public function blockingFor(string $importType, string $foreignKey, int $importId): ?LegacyReviewTriage
    {
        $triage = $this->currentFor($importType, $foreignKey, $importId);

        return $triage !== null && $triage->isBlocking() ? $triage : null;
    }

    /**
     * Which of these import ids are currently withheld?
     *
     * Bulk form so a queue listing can mark triage without one query per row.
     *
     * @param  list<int>  $importIds
     * @return array<int, LegacyReviewTriage> keyed by import id
     */
    public function blockingMap(string $importType, string $foreignKey, array $importIds): array
    {
        if ($importIds === []) {
            return [];
        }

        return LegacyReviewTriage::query()
            ->where('import_type', $importType)
            ->whereIn($foreignKey, $importIds)
            ->whereIn('triage_status', LegacyReviewTriageStatus::blocking())
            ->get()
            ->keyBy(fn (LegacyReviewTriage $t): int => (int) $t->getAttribute($foreignKey))
            ->all();
    }

    /**
     * Raise or change triage on one import.
     *
     * Authorized, reasoned, transactional and idempotent-by-upsert. The unique
     * index on the foreign key is what makes two concurrent reviewers converge
     * on one row instead of creating two.
     *
     * @throws AuthorizationException when this actor may not triage this import
     * @throws ValidationException when the reason is missing or not offered
     */
    public function raise(
        LegacyBatchReviewAdapter $adapter,
        Model $import,
        User $actor,
        string $triageStatus,
        ?string $reasonCode,
        ?string $note = null,
        ?LegacyBatchReviewSession $session = null,
    ): LegacyReviewTriage {
        if (! LegacyReviewTriageStatus::isBlocking($triageStatus)) {
            throw ValidationException::withMessages([
                'triage_status' => 'Status triase tidak dikenal.',
            ]);
        }

        $this->assertMayTriage($adapter, $import, $actor);
        $this->assertReasonAcceptable($reasonCode, $note);

        $foreignKey = $adapter->importForeignKey();
        $importId = (int) $import->getKey();

        [$triage, $previousStatus, $previousReason] = DB::transaction(
            function () use ($adapter, $import, $actor, $triageStatus, $reasonCode, $note, $session, $foreignKey, $importId): array {
                $existing = LegacyReviewTriage::query()
                    ->where('import_type', $adapter->importType())
                    ->where($foreignKey, $importId)
                    ->lockForUpdate()
                    ->first();

                $previousStatus = $existing?->triage_status;
                $previousReason = $existing?->reason_code;

                $attributes = [
                    'triage_status' => $triageStatus,
                    'reason_code' => $reasonCode,
                    'reason_note' => $this->normalizeNote($note),
                    'raised_in_session_id' => $session?->getKey(),
                    'decided_by' => $actor->getKey(),
                    'decided_at' => now(),
                    // Re-raising after a clear wipes the previous release, so the
                    // row never reads as both withheld and cleared.
                    'cleared_by' => null,
                    'cleared_at' => null,
                ];

                if ($existing !== null) {
                    $existing->fill($attributes)->save();

                    return [$existing->refresh(), $previousStatus, $previousReason];
                }

                $created = LegacyReviewTriage::query()->create($attributes + [
                    'import_type' => $adapter->importType(),
                    $foreignKey => $importId,
                    'patient_id' => $adapter->patientId($import),
                ]);

                return [$created, null, null];
            }
        );

        $this->audit->logTriageEvent(
            $previousStatus === null
                ? LegacyBatchReviewAuditService::TRIAGE_RAISED
                : LegacyBatchReviewAuditService::TRIAGE_CHANGED,
            $triage,
            [
                'previous_triage_status' => $previousStatus,
                'session_uuid' => $session?->uuid,
            ],
            $actor,
        );

        return $triage;
    }

    /**
     * Release triage on one import.
     *
     * The row survives as CLEARED so that "this was withheld and someone with
     * review authority released it" stays answerable. Clearing an item that is
     * not currently withheld is a no-op rather than an error — two reviewers
     * racing to release the same document should both succeed.
     *
     * @throws AuthorizationException when this actor may not clear this triage
     */
    public function clear(
        LegacyBatchReviewAdapter $adapter,
        Model $import,
        User $actor,
    ): ?LegacyReviewTriage {
        // The uploader-cannot-clear-own rule lives here, delegated to the
        // adapter so it matches the canonical review policy for this type.
        $this->assertMayTriage($adapter, $import, $actor);

        $foreignKey = $adapter->importForeignKey();
        $importId = (int) $import->getKey();

        [$triage, $previousStatus] = DB::transaction(
            function () use ($adapter, $foreignKey, $importId, $actor): array {
                $existing = LegacyReviewTriage::query()
                    ->where('import_type', $adapter->importType())
                    ->where($foreignKey, $importId)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null || ! $existing->isBlocking()) {
                    return [$existing, null];
                }

                $previousStatus = (string) $existing->triage_status;

                $existing->fill([
                    'triage_status' => LegacyReviewTriageStatus::CLEARED,
                    'cleared_by' => $actor->getKey(),
                    'cleared_at' => now(),
                ])->save();

                return [$existing->refresh(), $previousStatus];
            }
        );

        if ($triage !== null && $previousStatus !== null) {
            $this->audit->logTriageEvent(
                LegacyBatchReviewAuditService::TRIAGE_CLEARED,
                $triage,
                ['previous_triage_status' => $previousStatus],
                $actor,
            );
        }

        return $triage;
    }

    /**
     * @throws AuthorizationException
     */
    private function assertMayTriage(LegacyBatchReviewAdapter $adapter, Model $import, User $actor): void
    {
        if (! $adapter->migrationEnabled()) {
            throw new AuthorizationException('Kapabilitas migrasi tidak aktif.');
        }

        if (! $adapter->canClearTriage($actor, $import)) {
            throw new AuthorizationException(
                'Tidak berwenang mengubah status triase dokumen ini.'
            );
        }
    }

    /**
     * A withheld document must say why, and the why must come from the closed
     * list the UI offered.
     *
     * Validating against LegacyBatchReviewReason::triageCodes() is what stops a
     * crafted reason_code from introducing a value no operator could have
     * chosen — the field is operator input, so it is treated as untrusted.
     *
     * @throws ValidationException
     */
    private function assertReasonAcceptable(?string $reasonCode, ?string $note): void
    {
        if (! LegacyBatchReviewReason::isTriageCode($reasonCode)) {
            throw ValidationException::withMessages([
                'reason_code' => 'Alasan triase wajib dipilih dari daftar yang tersedia.',
            ]);
        }

        if (LegacyBatchReviewReason::requiresNote($reasonCode) && $this->normalizeNote($note) === null) {
            throw ValidationException::withMessages([
                'reason_note' => 'Alasan lain wajib disertai catatan singkat.',
            ]);
        }
    }

    /**
     * Bound the operator's note. It is stored as text and escaped at render; it
     * is never used as a key, never parsed, and never written to an audit
     * payload (it is operator prose that may name a patient).
     */
    private function normalizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $trimmed = trim($note);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 2000);
    }
}
