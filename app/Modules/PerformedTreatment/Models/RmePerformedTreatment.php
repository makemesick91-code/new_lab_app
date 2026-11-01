<?php

declare(strict_types=1);

namespace App\Modules\PerformedTreatment\Models;

use App\Models\User;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FEATURE-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219) — one treatment the
 * treating doctor recorded as ACTUALLY performed at a visit.
 *
 * Append-only clinical evidence: a row is written once and may only be VOIDED
 * (with its voider, time and reason) — never updated or deleted. Nothing is
 * mass-assignable: the service writes explicit allow-listed arrays through the
 * repository, so a request field can never land on a column.
 *
 * It carries no patient, branch, price or payment column: patient and branch
 * come from the visit, and a clinical row is never a financial record.
 */
class RmePerformedTreatment extends Model
{
    public const STATUS_PERFORMED = 'performed';

    public const STATUS_VOIDED = 'voided';

    public const STATUSES = [self::STATUS_PERFORMED, self::STATUS_VOIDED];

    public const MAX_QUANTITY = 32;

    public const VOID_REASON_MAX = 500;

    protected $table = 'trx_rme_performed_treatments';

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'clinic_visit_id' => 'integer',
            'treatment_id' => 'integer',
            'requires_lab_snapshot' => 'boolean',
            'quantity' => 'integer',
            'tooth_numbers' => 'array',
            'recorded_by' => 'integer',
            'recorded_by_doctor_id' => 'integer',
            'recorded_at' => 'datetime',
            'voided_by' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function isPerformed(): bool
    {
        return $this->status === self::STATUS_PERFORMED;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_PERFORMED);
    }

    public function clinicVisit(): BelongsTo
    {
        return $this->belongsTo(ClinicVisit::class, 'clinic_visit_id');
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class, 'treatment_id')->withTrashed();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
