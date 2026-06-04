<?php

namespace App\Modules\Branch\Models;

use App\Modules\Delivery\Models\Delivery;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Models\Payment;
use App\Modules\LabOrder\Models\LabOrder;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

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
