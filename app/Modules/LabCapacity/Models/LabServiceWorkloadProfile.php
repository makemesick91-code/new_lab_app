<?php

namespace App\Modules\LabCapacity\Models;

use App\Models\User;
use App\Modules\LabService\Models\LabService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAB-PROD-3 — Effective-dated planned workload per order-item of a lab service.
 *
 * planning_unit is explicit ('minutes' | 'units'). A service with no active
 * workload profile makes its demand UNPLANNABLE, never a fake zero.
 */
class LabServiceWorkloadProfile extends Model
{
    protected $table = 'mst_lab_service_workload_profiles';

    public const UNIT_MINUTES = 'minutes';

    public const UNIT_UNITS = 'units';

    protected $fillable = [
        'lab_service_id',
        'planning_unit',
        'planned_workload',
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
            'lab_service_id' => 'integer',
            'planned_workload' => 'decimal:2',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function labService(): BelongsTo
    {
        return $this->belongsTo(LabService::class, 'lab_service_id');
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
