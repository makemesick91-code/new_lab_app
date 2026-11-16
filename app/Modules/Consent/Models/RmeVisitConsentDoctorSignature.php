<?php

namespace App\Modules\Consent\Models;

use App\Models\User;
use App\Modules\Doctor\Models\Doctor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * DMS memo M1.1–M1.3, PR1 (rule 241 §1) — the treating doctor's countersignature
 * on one PERSETUJUAN TINDAKAN MEDIS.
 *
 * Clinical evidence and INSERT-ONLY: written once by
 * RmeVisitConsentService::countersign() and never updated or deleted. The
 * consent row it belongs to is never touched either (AIR-RMC-12). A wrong
 * consent is corrected by the supervised void; the countersignature row stays
 * with the voided consent, and the replacement consent is countersigned anew.
 */
class RmeVisitConsentDoctorSignature extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'trx_rme_visit_consent_doctor_signatures';

    protected $fillable = [
        'rme_visit_consent_id',
        'doctor_id',
        'signed_by',
        'signature_path',
        'signature_sha256',
        'signed_at',
    ];

    /** The private object key is never serialised (rule 121: possession is not authorization). */
    protected $hidden = [
        'signature_path',
    ];

    protected function casts(): array
    {
        return [
            'rme_visit_consent_id' => 'integer',
            'doctor_id' => 'integer',
            'signed_by' => 'integer',
            'signed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('A consent doctor countersignature is insert-only evidence and is never updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('A consent doctor countersignature is insert-only evidence and is never deleted.');
        });
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(RmeVisitConsent::class, 'rme_visit_consent_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }
}
