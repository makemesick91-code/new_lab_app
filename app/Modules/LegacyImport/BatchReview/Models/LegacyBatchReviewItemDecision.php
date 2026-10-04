<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\BatchReview\Models;

use App\Models\User;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewDecision;
use App\Modules\LegacyImport\BatchReview\Support\LegacyBatchReviewSubmitStatus;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ONE reviewer's attestation about ONE document — PR1 §4.
 *
 * §4 forbids storing an opaque "batch reviewed = true" in place of item review
 * state, so every decision is its own row carrying the full identification:
 * session, import type, import id, patient, source checksum, decision, reason
 * and the deciding actor with a timestamp.
 *
 * The two nullable import foreign keys mirror `stg_legacy_mass_upload_items`:
 * exactly one is set, the discriminator says which, and the database still
 * refuses a row pointing at a non-existent import.
 *
 * @property int $id
 * @property int $batch_review_session_id
 * @property string $import_type
 * @property int|null $rme_legacy_import_id
 * @property int|null $odontogram_legacy_import_id
 * @property int|null $patient_id
 * @property string|null $source_sha256
 * @property string $decision
 * @property string|null $reason_code
 * @property string|null $reason_note
 * @property int $decided_by
 * @property string $submit_status
 * @property string|null $refusal_code
 * @property string|null $refusal_message
 * @property int $pages_viewed
 */
class LegacyBatchReviewItemDecision extends Model
{
    protected $table = 'stg_legacy_batch_review_decisions';

    protected $fillable = [
        'batch_review_session_id',
        'import_type',
        'rme_legacy_import_id',
        'odontogram_legacy_import_id',
        'patient_id',
        'source_sha256',
        'decision',
        'reason_code',
        'reason_note',
        'decided_by',
        'decided_at',
        'first_viewed_at',
        'pages_viewed',
        'submit_status',
        'refusal_code',
        'refusal_message',
        'applied_at',
    ];

    protected $casts = [
        'batch_review_session_id' => 'integer',
        'rme_legacy_import_id' => 'integer',
        'odontogram_legacy_import_id' => 'integer',
        'patient_id' => 'integer',
        'decided_by' => 'integer',
        'pages_viewed' => 'integer',
        'decided_at' => 'datetime',
        'first_viewed_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(LegacyBatchReviewSession::class, 'batch_review_session_id');
    }

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

    /**
     * The canonical import id this decision is about, whichever column holds it.
     *
     * Single accessor so no caller has to re-derive the discriminator, which is
     * how a type confusion bug would get in.
     */
    public function importId(): ?int
    {
        $id = $this->import_type === LegacyImportType::LEGACY_RME
            ? $this->rme_legacy_import_id
            : $this->odontogram_legacy_import_id;

        return $id !== null ? (int) $id : null;
    }

    /** Does this attestation drive a canonical review? */
    public function isReviewed(): bool
    {
        return $this->decision === LegacyBatchReviewDecision::REVIEWED;
    }

    /** Does this attestation raise sticky triage instead? */
    public function isTriaging(): bool
    {
        return LegacyBatchReviewDecision::isTriaging($this->decision);
    }

    public function isApplied(): bool
    {
        return $this->submit_status === LegacyBatchReviewSubmitStatus::APPLIED;
    }

    public function decisionLabel(): string
    {
        return LegacyBatchReviewDecision::label($this->decision);
    }

    public function submitStatusLabel(): string
    {
        return LegacyBatchReviewSubmitStatus::label($this->submit_status);
    }
}
