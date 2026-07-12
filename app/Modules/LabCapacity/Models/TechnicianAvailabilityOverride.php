<?php

namespace App\Modules\LabCapacity\Models;

use App\Models\User;
use App\Modules\Technician\Models\Technician;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LAB-PROD-3 — Date-specific capacity override for a technician.
 *
 * capacity_override (if set) wins as the absolute capacity for that day;
 * otherwise capacity_reduction is subtracted from the profile's daily
 * capacity. reason_category is a generic operational category ONLY — never a
 * diagnosis or health information.
 */
class TechnicianAvailabilityOverride extends Model
{
    protected $table = 'mst_lab_technician_availability_overrides';

    protected $fillable = [
        'technician_id',
        'override_date',
        'capacity_override',
        'capacity_reduction',
        'reason_category',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'technician_id' => 'integer',
            'override_date' => 'date',
            'capacity_override' => 'decimal:2',
            'capacity_reduction' => 'decimal:2',
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
}
