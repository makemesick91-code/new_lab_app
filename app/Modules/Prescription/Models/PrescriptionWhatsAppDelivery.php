<?php

namespace App\Modules\Prescription\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-02) — the record of a prescription
 * handed to a patient over WhatsApp: who sent it, where it went, and what the
 * provider answered.
 */
class PrescriptionWhatsAppDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    protected $table = 'trx_rme_prescription_whatsapp_deliveries';

    protected $fillable = [
        'rme_prescription_id',
        'clinic_visit_id',
        'patient_id',
        'branch_id',
        'recipient_msisdn',
        'status',
        'template_name',
        'template_language',
        'provider_message_id',
        'provider_error_code',
        'provider_error_message',
        'idempotency_key',
        'sent_by',
        'sent_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(RmePrescription::class, 'rme_prescription_id');
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'clinic_visit_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /** Masked for display — the full number is never rendered in a list. */
    public function maskedRecipient(): string
    {
        $v = (string) $this->recipient_msisdn;

        return strlen($v) <= 4 ? str_repeat('*', strlen($v)) : substr($v, 0, 4).str_repeat('*', max(0, strlen($v) - 8)).substr($v, -4);
    }
}
