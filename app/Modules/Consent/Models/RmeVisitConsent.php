<?php

namespace App\Modules\Consent\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — a signed PERSETUJUAN TINDAKAN
 * MEDIS for one clinic visit.
 *
 * This is clinical/legal evidence. It is written once, by the consent service,
 * and is never edited afterwards: a mistake is corrected by voiding this row
 * and signing a new one. There is deliberately no update path in the service,
 * and no soft delete — voiding is recorded in place so the evidence survives.
 */
class RmeVisitConsent extends Model
{
    use HasFactory;

    protected $table = 'trx_rme_visit_consents';

    protected $fillable = [
        'consent_number',
        'branch_id',
        'clinic_visit_id',
        'patient_id',
        'doctor_id',
        'template_code',
        'template_version',
        'content_snapshot',
        'consenter_relationship',
        'consenter_name',
        'consenter_age',
        'consenter_gender',
        'consenter_address',
        'consenter_identity_number',
        'patient_name_snapshot',
        'patient_age_snapshot',
        'patient_gender_snapshot',
        'patient_address_snapshot',
        'patient_identity_number_snapshot',
        'medical_record_number_snapshot',
        'medical_action',
        'treatment_summary',
        'documentation_consent',
        'consenter_signature_path',
        'doctor_signature_path',
        'doctor_name_snapshot',
        'signed_location',
        'signed_at',
        'signed_by',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    /**
     * The identity numbers are part of the legal document but must never leak
     * through incidental serialisation (JSON responses, logs, array dumps).
     * The consent document view reads them explicitly; nothing else should.
     */
    protected $hidden = [
        'consenter_identity_number',
        'patient_identity_number_snapshot',
        'consenter_signature_path',
        'doctor_signature_path',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'clinic_visit_id' => 'integer',
            'patient_id' => 'integer',
            'doctor_id' => 'integer',
            'signed_by' => 'integer',
            'voided_by' => 'integer',
            'content_snapshot' => 'array',
            'documentation_consent' => 'boolean',
            'signed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'clinic_visit_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    /**
     * A consent that still counts. This is the single predicate the payment
     * gate depends on, so it stays deliberately narrow: signed, and not voided.
     */
    public function isValid(): bool
    {
        return $this->voided_at === null;
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    /**
     * Clause 8 is recorded separately from the consent itself: a patient may
     * refuse publication and still be treated and still pay. Callers must not
     * infer this from the existence of the consent.
     */
    public function allowsDocumentationPublication(): bool
    {
        return $this->documentation_consent === true;
    }

    protected static function newFactory()
    {
        return \Database\Factories\RmeVisitConsentFactory::new();
    }
}
