<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1 (owner
 * OPS-OQ-1a, rule 204) — an explicit designation of ONE account as the billing
 * dispute reviewer of ONE branch.
 *
 * It is authority over that branch's disputes only (review, escalate, annotate,
 * resolve with verified evidence). It grants no Spatie permission, so it can never
 * become a correction, price, refund, cancellation or payment power.
 *
 * IMMUTABLE HISTORY. The grant ({@see GRANT_ATTRIBUTES}) is written once. The only
 * change a row ever accepts is its single revocation; after that it is frozen. A
 * row is never deleted; re-designating after a revocation is a new row. Every
 * write goes through RmeBillingDisputeReviewerService.
 *
 * LIMIT: an application-layer control. Raw SQL bypasses model events; on
 * PostgreSQL the CHECK constraints and the one-active partial unique index still
 * hold.
 */
class RmeBillingDisputeReviewer extends Model
{
    protected $table = 'mst_rme_billing_dispute_reviewers';

    /** Written at grant time and never again. */
    public const GRANT_ATTRIBUTES = [
        'user_id',
        'branch_id',
        'granted_by',
        'granted_at',
        'grant_reason',
    ];

    /** The single change a row may receive, once. */
    public const REVOCATION_ATTRIBUTES = [
        'revoked_by',
        'revoked_at',
        'revoke_reason',
    ];

    protected $fillable = [
        'user_id',
        'branch_id',
        'granted_by',
        'granted_at',
        'grant_reason',
    ];

    protected static function booted(): void
    {
        static::updating(function (RmeBillingDisputeReviewer $designation): void {
            if ($designation->getOriginal('revoked_at') !== null) {
                throw new LogicException(sprintf('Billing dispute reviewer designation #%d is revoked and can never change.', (int) $designation->getKey()));
            }

            foreach ($designation->getDirty() as $attribute => $_) {
                if (! in_array($attribute, [...self::REVOCATION_ATTRIBUTES, 'updated_at'], true)) {
                    throw new LogicException(sprintf(
                        'Billing dispute reviewer designation #%d: "%s" is part of the immutable grant.',
                        (int) $designation->getKey(),
                        $attribute,
                    ));
                }
            }

            // A revocation is all-or-nothing: who, when and why together.
            foreach (self::REVOCATION_ATTRIBUTES as $attribute) {
                if ($designation->getAttribute($attribute) === null) {
                    throw new LogicException(sprintf('Billing dispute reviewer designation #%d: a revocation needs who, when and why.', (int) $designation->getKey()));
                }
            }
        });

        static::deleting(function (RmeBillingDisputeReviewer $designation): void {
            throw new LogicException(sprintf('Billing dispute reviewer designation #%d can never be deleted.', (int) $designation->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'branch_id' => 'integer',
            'granted_by' => 'integer',
            'granted_at' => 'datetime',
            'revoked_by' => 'integer',
            'revoked_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
