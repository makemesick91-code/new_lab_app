<?php

namespace App\Modules\LabOrder\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\Treatment\Models\Treatment;
use Database\Factories\LabCaseCandidateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LabCaseCandidate extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_CONVERTED_TO_LAB_ORDER = 'converted_to_lab_order';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_CONVERTED_TO_LAB_ORDER,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $table = 'trx_lab_case_candidates';

    protected $fillable = [
        'branch_id',
        'clinic_visit_id',
        'rme_invoice_id',
        'rme_invoice_item_id',
        'patient_id',
        'doctor_id',
        'treatment_id',
        'medical_record_id',
        'source_description',
        'quantity',
        'estimated_price',
        'status',
        'converted_lab_order_id',
        'reviewed_by',
        'reviewed_at',
        'notes',
        'metadata',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'clinic_visit_id' => 'integer',
            'rme_invoice_id' => 'integer',
            'rme_invoice_item_id' => 'integer',
            'patient_id' => 'integer',
            'doctor_id' => 'integer',
            'treatment_id' => 'integer',
            'medical_record_id' => 'integer',
            'quantity' => 'integer',
            'estimated_price' => 'decimal:2',
            'converted_lab_order_id' => 'integer',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
            'created_by' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    public function rmeInvoice(): BelongsTo
    {
        return $this->belongsTo(RmeInvoice::class);
    }

    public function rmeInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(RmeInvoiceItem::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function newFactory(): LabCaseCandidateFactory
    {
        return LabCaseCandidateFactory::new();
    }
}
