<?php

namespace App\Modules\LabCapacity\Models;

use App\Models\User;
use App\Modules\Technician\Models\Technician;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAB-PROD-3 — Effective-dated daily capacity for a lab technician.
 *
 * planning_unit is explicit ('minutes' | 'units'). daily_capacity is the
 * capacity per working day. Capacity is lab-wide (technicians have no branch).
 */
class TechnicianCapacityProfile extends Model
{
    protected $table = 'mst_lab_technician_capacity_profiles';

    public const UNIT_MINUTES = 'minutes';

    public const UNIT_UNITS = 'units';

    protected $fillable = [
        'technician_id',
        'planning_unit',
        'daily_capacity',
        'working_days',
        'effective_from',
        'effective_until',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'technician_id' => 'integer',
            'daily_capacity' => 'decimal:2',
            'working_days' => 'array',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Active profiles whose effective window covers the given date. */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
            });
    }
}
