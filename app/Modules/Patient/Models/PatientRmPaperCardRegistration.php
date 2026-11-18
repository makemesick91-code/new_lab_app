<?php

declare(strict_types=1);

namespace App\Modules\Patient\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 / memo M4.1 (rule 244 §4.3, PR-3b) — one
 * paper-card registration: the structured reason code and the optional note
 * the operator gave when a patient was registered under an old paper-card
 * Nomor RM (owner decision 2026-11-17, "PAPER-CARD REASON: store text,
 * restricted").
 *
 * Immutable for the life of the patient record: never updated, never deleted,
 * no `updated_at`. A future change path must write a NEW row with its own
 * audit. The note is free text that may carry personal data, so it is read only
 * through the restricted surface (`view_patient_rm_paper_card_reasons`) and
 * never written to a log, an audit payload or an export.
 */
class PatientRmPaperCardRegistration extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'trx_patient_rm_paper_card_registrations';

    protected $fillable = [
        'patient_id',
        'branch_id',
        'year',
        'reason_code',
        'reason_note',
        'actor_user_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (PatientRmPaperCardRegistration $registration): void {
            throw new LogicException(sprintf('Paper-card registration #%d is immutable; record a new row instead.', (int) $registration->getKey()));
        });

        static::deleting(function (PatientRmPaperCardRegistration $registration): void {
            throw new LogicException(sprintf('Paper-card registration #%d is kept for the life of the patient record.', (int) $registration->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'patient_id' => 'integer',
            'branch_id' => 'integer',
            'year' => 'integer',
            'actor_user_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withTrashed();
    }
}
