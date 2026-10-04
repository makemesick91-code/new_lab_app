<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Models;

use App\Models\User;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewReason;
use App\Modules\LegacyImport\BatchReview\Support\LegacyReviewTriageStatus;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The sticky, non-destructive second axis — PR1 §5.
 *
 *     canonical import status = READY_FOR_REVIEW   (untouched)
 *     review_triage_status    = BLOCKED            (this row)
 *
 * At most one row per canonical import, enforced by two unique indexes over the
 * nullable foreign keys. Setting, changing and clearing triage all mutate this
 * row and nothing else — no canonical import is written, and the patient's
 * single-active-document slot is never released, because that slot is held by
 * the canonical staging row's own status and this table is not consulted by
 * LegacySingleActiveDocumentService at all.
 *
 * @property int $id
 * @property string $import_type
 * @property int|null $rme_legacy_import_id
 * @property int|null $odontogram_legacy_import_id
 * @property int|null $patient_id
 * @property string $triage_status
 * @property string|null $reason_code
 * @property string|null $reason_note
 * @property int|null $raised_in_session_id
 * @property int $decided_by
 * @property int|null $cleared_by
 */
class LegacyReviewTriage extends Model
{
    protected $table = 'stg_legacy_review_triage';

    protected $fillable = [
        'import_type',
        'rme_legacy_import_id',
        'odontogram_legacy_import_id',
        'patient_id',
        'triage_status',
        'reason_code',
        'reason_note',
        'raised_in_session_id',
        'decided_by',
        'decided_at',
        'cleared_by',
        'cleared_at',
    ];

    protected $casts = [
        'rme_legacy_import_id' => 'integer',
        'odontogram_legacy_import_id' => 'integer',
        'patient_id' => 'integer',
        'raised_in_session_id' => 'integer',
        'decided_by' => 'integer',
        'cleared_by' => 'integer',
        'decided_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];

    public function rmeImport(): BelongsTo
    {
        return $this->belongsTo(LegacyRmeImport::class, 'rme_legacy_import_id');
    }

    public function odontogramImport(): BelongsTo
    {
        return $this->belongsTo(LegacyOdontogramImport::class, 'odontogram_legacy_import_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    public function raisedInSession(): BelongsTo
    {
        return $this->belongsTo(LegacyBatchReviewSession::class, 'raised_in_session_id');
    }

    public function importId(): ?int
    {
        $id = $this->import_type === LegacyImportType::LEGACY_RME
            ? $this->rme_legacy_import_id
            : $this->odontogram_legacy_import_id;

        return $id !== null ? (int) $id : null;
    }

    /**
     * Is this item currently withheld from publishing?
     *
     * THE contract PR2 consumes. A CLEARED row answers false — the history is
     * kept, the withholding is not.
     */
    public function isBlocking(): bool
    {
        return LegacyReviewTriageStatus::isBlocking($this->triage_status);
    }

    public function statusLabel(): string
    {
        return LegacyReviewTriageStatus::label($this->triage_status);
    }

    public function reasonLabel(): string
    {
        return LegacyBatchReviewReason::label($this->reason_code);
    }
}
