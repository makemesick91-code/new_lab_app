<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * HARDENING-RME-OWNER-REFERENCE-REGISTRY-1 — one consumed Owner approval
 * reference, bound to the one decision scope it authorises (decision PP-2).
 *
 * Written only by RmeOwnerApprovalReferenceRegistry through the repository
 * (INSERT … ON CONFLICT DO NOTHING). Append-only: a claim is never moved to
 * another scope, released or deleted — whatever later happens to the decision
 * it authorised (rejected, stale, cancelled).
 *
 * @property int $id
 * @property string $reference_folded
 * @property string $scope_type
 * @property int $scope_id
 * @property int $first_consumed_by
 */
class RmeOwnerApprovalReference extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'trx_rme_owner_approval_references';

    /** Nothing is mass-assignable: the registry writes through the query builder only. */
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (RmeOwnerApprovalReference $reference): void {
            throw new LogicException(sprintf('Owner approval reference claim #%d is immutable.', (int) $reference->getKey()));
        });

        static::deleting(function (RmeOwnerApprovalReference $reference): void {
            throw new LogicException(sprintf('Owner approval reference claim #%d can never be deleted.', (int) $reference->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'scope_id' => 'integer',
            'first_consumed_by' => 'integer',
            'first_consumed_at' => 'datetime',
        ];
    }

    public function firstConsumer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_consumed_by');
    }
}
