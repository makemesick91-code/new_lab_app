<?php

namespace App\Modules\LabCapacity\Models;

use App\Models\User;
use App\Modules\LabService\Models\LabService;
use App\Modules\Technician\Models\Technician;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAB-PROD-3 — Which lab services a technician is eligible to produce.
 *
 * Used to filter recommendation candidates. Effective-dated. Absence of any
 * capability row means "no declared capability", never an implicit grant.
 */
class TechnicianCapability extends Model
{
    protected $table = 'mst_lab_technician_capabilities';

    protected $fillable = [
        'technician_id',
        'lab_service_id',
        'is_eligible',
        'effective_from',
        'effective_until',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'technician_id' => 'integer',
            'lab_service_id' => 'integer',
            'is_eligible' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id');
    }

    public function labService(): BelongsTo
    {
        return $this->belongsTo(LabService::class, 'lab_service_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Eligible capabilities whose effective window covers the given date. */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('is_eligible', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
            });
    }
}
