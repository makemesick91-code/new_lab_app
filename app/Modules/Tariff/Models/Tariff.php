<?php

namespace App\Modules\Tariff\Models;

use App\Modules\Branch\Models\Branch;
use App\Modules\Treatment\Models\Treatment;
use Database\Factories\TariffFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Tariff extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mst_tariffs';

    protected $fillable = [
        'branch_id',
        'treatment_id',
        'price',
        'effective_date',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'treatment_id' => 'integer',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Persist effective_date as a date-only string ('Y-m-d') so branch+treatment+date
     * uniqueness and idempotent seeding compare reliably across drivers, while still
     * exposing a Carbon instance on read.
     */
    protected function effectiveDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value ? Carbon::parse($value) : null,
            set: fn ($value) => $value ? Carbon::parse($value)->toDateString() : null,
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function treatment(): BelongsTo
    {
        return $this->belongsTo(Treatment::class);
    }

    protected static function newFactory(): TariffFactory
    {
        return TariffFactory::new();
    }
}
