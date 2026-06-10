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
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\Treatment\Models\Treatment;
use Database\Factories\ClinicVisitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public const VALID_TRANSITIONS = [
        self::STATUS_REGISTERED => [self::STATUS_WAITING, self::STATUS_CANCELLED],
        self::STATUS_WAITING => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_CASHIER_PENDING, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_CASHIER_PENDING => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $table = 'trx_clinic_visits';

    protected $fillable = [
        'visit_number', 'branch_id', 'clinic_id', 'patient_id', 'doctor_id',
        'clinic_room_id', 'visit_date', 'queue_number', 'status',
        'chief_complaint', 'initial_treatment_id', 'initial_service_note',
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
        ];
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

    public function medicalRecord(): HasOne
    {
        return $this->hasOne(MedicalRecord::class);
    }

    public function odontogram(): HasOne
    {
        return $this->hasOne(Odontogram::class);
    }

    public function rmeInvoice(): HasOne
    {
        return $this->hasOne(RmeInvoice::class, 'clinic_visit_id')
            ->whereIn('status', [RmeInvoice::STATUS_DRAFT, RmeInvoice::STATUS_UNPAID]);
    }

    protected static function newFactory(): ClinicVisitFactory
    {
        return ClinicVisitFactory::new();
    }
}
