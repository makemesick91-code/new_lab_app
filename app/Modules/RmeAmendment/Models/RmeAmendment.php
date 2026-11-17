<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 (rule 240 §6) — a supervised
 * amendment request of a final sheet or of a finalized odontogram.
 *
 * `pending` → `cancelled` (by the requester), `rejected` (by a checker who is
 * neither the requester nor the doctor of record, with a written reason) or
 * `applied` (M3.3 PR5, approve-and-apply in one transaction). The subject is
 * named by `subject_type` (RmeRecordVersion::SUBJECT_*) and `subject_id`; the
 * branch, visit and patient are copied from the subject by the service, never
 * taken from a request. Status and decision columns are written only by
 * RmeAmendmentRepository::transition() (not mass-assignable).
 *
 * @property int $id
 * @property string $subject_type
 * @property int $subject_id
 * @property int $branch_id
 * @property int $clinic_visit_id
 * @property int $patient_id
 * @property int $base_version_id
 * @property int $base_version
 * @property string $status
 * @property string $reason
 * @property array<string, mixed> $proposed_content
 * @property int $requested_by
 * @property int|null $decided_by
 * @property string|null $decision_reason
 */
class RmeAmendment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REJECTED = 'rejected';

    /** Written by M3.3 PR5 (approve and apply). */
    public const STATUS_APPLIED = 'applied';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_CANCELLED, self::STATUS_REJECTED, self::STATUS_APPLIED];

    public const STATUS_LABELS = [
        self::STATUS_PENDING => 'Menunggu persetujuan',
        self::STATUS_CANCELLED => 'Dibatalkan pengaju',
        self::STATUS_REJECTED => 'Ditolak',
        self::STATUS_APPLIED => 'Diterapkan',
    ];

    public const SUBJECT_LABELS = [
        RmeRecordVersion::SUBJECT_MEDICAL_RECORD => 'Rekam Medis',
        RmeRecordVersion::SUBJECT_ODONTOGRAM => 'Odontogram',
    ];

    /** Bounds of the requester's reason and of the checker's decision reason. */
    public const REASON_MIN = 10;

    public const REASON_MAX = 1000;

    protected $table = 'trx_rme_amendments';

    protected $fillable = [
        'subject_type',
        'subject_id',
        'branch_id',
        'clinic_visit_id',
        'patient_id',
        'base_version_id',
        'base_version',
        'status',
        'reason',
        'proposed_content',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'branch_id' => 'integer',
            'clinic_visit_id' => 'integer',
            'patient_id' => 'integer',
            'base_version_id' => 'integer',
            'base_version' => 'integer',
            'proposed_content' => 'array',
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'cancelled_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function subjectLabel(): string
    {
        return self::SUBJECT_LABELS[$this->subject_type] ?? $this->subject_type;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(RmeRecordVersion::class, 'base_version_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
