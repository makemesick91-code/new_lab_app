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
            'approval_recorded_by' => 'integer',
            'approval_recorded_at' => 'datetime',
        ];
    }

    /**
     * FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1 — true only when this tariff
     * arrived through the governed import with an owner-approval reference.
     * The approval_* columns are deliberately NOT fillable: no request can forge
     * an approval; only TariffRepository::recordApprovalProvenance() writes them.
     */
    public function hasApprovalProvenance(): bool
    {
        return is_string($this->approval_reference) && trim($this->approval_reference) !== '';
    }

    /** What an approval covers: a change to any of these withdraws it. */
    public const APPROVAL_COVERED_ATTRIBUTES = ['price', 'effective_date', 'treatment_id', 'branch_id'];

    /**
     * FEATURE-RME-BRANCH-TARIFF-PROVISIONING-1 (security review) — the laundering
     * guard lives on the MODEL, so EVERY Eloquent save is covered (master data,
     * a seeder's updateOrCreate, an operator repair), not only TariffService.
     * A save that moves price / effective date / treatment / branch without
     * itself writing an approval drops the approval in the same UPDATE.
     * Raw query-builder writes bypass Eloquent; none exists outside the
     * stress-only harness (rule 193 §4).
     */
    protected static function booted(): void
    {
        static::updating(function (Tariff $tariff): void {
            if ($tariff->isDirty(self::APPROVAL_COVERED_ATTRIBUTES) && ! $tariff->isDirty('approval_reference')) {
                $tariff->forceFill([
                    'approval_reference' => null,
                    'approval_recorded_by' => null,
                    'approval_recorded_at' => null,
                ]);
            }
        });
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
