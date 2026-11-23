<?php

namespace App\Modules\LabOrder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable status timeline record. Only created_at is managed (no updated_at).
 *
 * Append-only at the model layer (rule 248 layer 1); PostgreSQL also refuses
 * UPDATE, DELETE and TRUNCATE by trigger, and a lab order with status logs
 * cannot be deleted (the foreign key restricts).
 */
class LabOrderStatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'trx_lab_order_status_logs';

    protected $fillable = [
        'lab_order_id',
        'old_status',
        'new_status',
        'notes',
        'changed_by',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (LabOrderStatusLog $log): void {
            throw new LogicException(sprintf('Lab order status log #%d is append-only (rule 248).', (int) $log->getKey()));
        });

        static::deleting(function (LabOrderStatusLog $log): void {
            throw new LogicException(sprintf('Lab order status log #%d can never be deleted (rule 248).', (int) $log->getKey()));
        });
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
