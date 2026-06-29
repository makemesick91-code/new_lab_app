<?php

namespace App\Modules\Branch\Models;

use App\Modules\Delivery\Models\Delivery;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\LabOrder\Models\LabOrder;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Business code of the default head-office branch. Legacy/unscoped rows are
     * anchored here by the Sprint 9 backfill migration and BranchSeeder.
     */
    public const MAIN_CODE = 'MAIN';

    protected $table = 'mst_branches';

    protected $fillable = [
        'code',
        'name',
        'address',
        'phone',
        'is_active',
        'is_rme_enabled',
        'is_inventory_enabled',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_rme_enabled' => 'boolean',
        'is_inventory_enabled' => 'boolean',
    ];

    /**
     * Scope to branches that participate in RME (multi-branch module).
     */
    public function scopeRmeEnabled($query)
    {
        return $query->where('is_rme_enabled', true);
    }

    /**
     * Scope to branches that participate in Inventory (multi-branch module).
     */
    public function scopeInventoryEnabled($query)
    {
        return $query->where('is_inventory_enabled', true);
    }

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'mst_doctor_branches', 'branch_id', 'doctor_id')
            ->withTimestamps();
    }

    public function labOrders(): HasMany
    {
        return $this->hasMany(LabOrder::class, 'branch_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'branch_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'branch_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'branch_id');
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }
}
