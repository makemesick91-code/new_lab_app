<?php

namespace App\Modules\ClinicVisit\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\Treatment\Models\Treatment;
use Database\Factories\ClinicVisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClinicVisit extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_CASHIER_PENDING = 'cashier_pending';

    public const STATUSES = [
        self::STATUS_REGISTERED,
        self::STATUS_WAITING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_CASHIER_PENDING,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Sprint 62.1 — Doctor → Cashier completion gate.
     * `in_progress` may only advance to `cashier_pending` ("Selesai Pemeriksaan"),
     * never directly to `completed`. `completed` ("Selesai Visit") is reachable
     * ONLY from `cashier_pending`, and in practice only via RmePaymentService once
     * the invoice is settled — the doctor can never mark a visit fully completed.
     */
    public const VALID_TRANSITIONS = [
        self::STATUS_REGISTERED => [self::STATUS_WAITING, self::STATUS_CANCELLED],
        self::STATUS_WAITING => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_CASHIER_PENDING, self::STATUS_CANCELLED],
        self::STATUS_CASHIER_PENDING => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    public const VISIT_TYPE_NEW = 'new';

    public const VISIT_TYPE_CONTROL = 'control';

    public const VISIT_TYPE_CONTINUED_TREATMENT = 'continued_treatment';

    public const VISIT_TYPE_EMERGENCY = 'emergency';

    public const VISIT_TYPES = [
        self::VISIT_TYPE_NEW,
        self::VISIT_TYPE_CONTROL,
        self::VISIT_TYPE_CONTINUED_TREATMENT,
        self::VISIT_TYPE_EMERGENCY,
    ];

    public const FOLLOW_UP_VISIT_TYPES = [
        self::VISIT_TYPE_CONTROL,
        self::VISIT_TYPE_CONTINUED_TREATMENT,
    ];

    /**
     * Hotfix Sprint 60.8 — statuses in the "before doctor examination" window.
     * A visit in one of these statuses must have an assigned treatment room
     * before the doctor may open/continue examination input (RM / odontogram).
     * cashier_pending and the terminal statuses are past examination and are
     * intentionally exempt so post-exam editing (Sprint 59) is never blocked.
     */
    public const PRE_EXAM_STATUSES = [
        self::STATUS_REGISTERED,
        self::STATUS_WAITING,
        self::STATUS_IN_PROGRESS,
    ];

    protected $table = 'trx_clinic_visits';

    protected $fillable = [
        'visit_number', 'branch_id', 'clinic_id', 'patient_id', 'doctor_id',
        'clinic_room_id', 'visit_date', 'queue_number', 'status',
        'visit_type', 'follow_up_of_visit_id',
        'chief_complaint', 'initial_treatment_id', 'initial_service_note',
        'consent_signed_by_patient', 'consent_signed_by_doctor',
        'consent_verified_at', 'consent_verified_by',
        'check_in_at', 'started_at', 'completed_at', 'cancelled_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'clinic_id' => 'integer',
            'patient_id' => 'integer',
            'doctor_id' => 'integer',
            'clinic_room_id' => 'integer',
            'queue_number' => 'integer',
            'created_by' => 'integer',
            'visit_date' => 'date',
            'check_in_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'initial_treatment_id' => 'integer',
            'follow_up_of_visit_id' => 'integer',
            'consent_signed_by_patient' => 'boolean',
            'consent_signed_by_doctor' => 'boolean',
            'consent_verified_at' => 'datetime',
            'consent_verified_by' => 'integer',
        ];
    }

    public function consentVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_verified_by');
    }

    public function hasVerifiedConsent(): bool
    {
        return $this->consent_signed_by_patient === true
            && $this->consent_signed_by_doctor === true;
    }

    public function hasAssignedRoom(): bool
    {
        return $this->clinic_room_id !== null;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    /**
     * Hotfix Sprint 60.8 — the room-assignment gate. An active pre-examination
     * visit (registered/waiting/in_progress) must have a treatment room before
     * the doctor can start/open examination. Returns true when examination must
     * be blocked because no room has been assigned yet.
     */
    public function requiresRoomBeforeExam(): bool
    {
        return ! $this->hasAssignedRoom()
            && in_array($this->status, self::PRE_EXAM_STATUSES, true);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function clinicRoom(): BelongsTo
    {
        return $this->belongsTo(ClinicRoom::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function initialTreatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class, 'initial_treatment_id');
    }

    public function followUpOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'follow_up_of_visit_id');
    }

    public function parentVisit(): BelongsTo
    {
        return $this->followUpOf();
    }

    public function followUpVisits(): HasMany
    {
        return $this->hasMany(self::class, 'follow_up_of_visit_id');
    }

    public function controlVisits(): HasMany
    {
        return $this->followUpVisits()->where('visit_type', self::VISIT_TYPE_CONTROL);
    }

    public function isControlVisit(): bool
    {
        return $this->visit_type === self::VISIT_TYPE_CONTROL;
    }

    public function isNewVisit(): bool
    {
        return ($this->visit_type ?? self::VISIT_TYPE_NEW) === self::VISIT_TYPE_NEW;
    }

    public function isFollowUpVisit(): bool
    {
        return in_array($this->visit_type, self::FOLLOW_UP_VISIT_TYPES, true);
    }

    public function visitTypeLabel(): string
    {
        return match ($this->visit_type ?? self::VISIT_TYPE_NEW) {
            self::VISIT_TYPE_CONTROL => 'Kontrol',
            self::VISIT_TYPE_CONTINUED_TREATMENT => 'Lanjutan Tindakan',
            self::VISIT_TYPE_EMERGENCY => 'Emergency',
            default => 'Baru',
        };
    }

    public function medicalRecord(): HasOne
    {
        return $this->hasOne(MedicalRecord::class);
    }

    public function odontogram(): HasOne
    {
        return $this->hasOne(Odontogram::class);
    }

    public function rmePrescription(): HasOne
    {
        return $this->hasOne(RmePrescription::class, 'clinic_visit_id');
    }

    /** Sprint 66.2 — auto assignment row created from this visit (if any). */
    public function patientDoctorAssignment(): HasOne
    {
        return $this->hasOne(PatientDoctorAssignment::class, 'source_visit_id');
    }

    public function rmeInvoice(): HasOne
    {
        return $this->hasOne(RmeInvoice::class, 'clinic_visit_id')
            ->whereIn('status', [RmeInvoice::STATUS_DRAFT, RmeInvoice::STATUS_UNPAID, RmeInvoice::STATUS_PARTIAL]);
    }

    protected static function newFactory(): ClinicVisitFactory
    {
        return ClinicVisitFactory::new();
    }
}
