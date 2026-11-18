<?php

namespace App\Modules\ClinicalPhoto\Models;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Consent\Models\RmeVisitConsent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * DMS memo M4.4 / M1.x, PTM PR4 (rule 242) — one clinical photo of an encounter.
 *
 * Clinical evidence: written once by RmeClinicalPhotoService::upload() together
 * with its private object (rule 224), never deleted, and never edited except by
 * the void transition (voided_at / voided_by / void_reason, once, from not
 * voided). The object key is never serialised (rule 121: possession of a key is
 * not authorization); the image is read only through the policy-gated route.
 */
class RmeClinicalPhoto extends Model
{
    protected $table = 'trx_rme_clinical_photos';

    /** The only columns the void transition may write (plus updated_at). */
    public const VOID_COLUMNS = ['voided_at', 'voided_by', 'void_reason'];

    protected $fillable = [
        'clinic_visit_id',
        'patient_id',
        'branch_id',
        'rme_visit_consent_id',
        'uploaded_by',
        'photo_path',
        'mime_type',
        'byte_size',
        'width',
        'height',
        'sha256',
        'original_sha256',
        'uploaded_at',
    ];

    /**
     * Never serialised: the object key (rule 121) and the original's hash, and
     * the void reason (free text a supervisor wrote, rule 242 §9.6), which a
     * caller reads deliberately as an attribute or not at all.
     */
    protected $hidden = [
        'photo_path',
        'original_sha256',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'clinic_visit_id' => 'integer',
            'patient_id' => 'integer',
            'branch_id' => 'integer',
            'rme_visit_consent_id' => 'integer',
            'uploaded_by' => 'integer',
            'byte_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'uploaded_at' => 'datetime',
            'voided_at' => 'datetime',
            'voided_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (RmeClinicalPhoto $photo): void {
            $changed = array_diff(array_keys($photo->getDirty()), [...self::VOID_COLUMNS, 'updated_at']);

            if ($changed !== [] || $photo->getOriginal('voided_at') !== null) {
                throw new LogicException('A clinical photo is evidence: only a not-yet-voided photo may be voided, and nothing else is ever edited.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('A clinical photo is evidence and is never deleted; void it with a reason.');
        });
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'clinic_visit_id');
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(RmeVisitConsent::class, 'rme_visit_consent_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
