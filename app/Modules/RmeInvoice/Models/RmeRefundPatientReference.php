<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215; RF-5 = B) — a consumed
 * patient request reference, bound for good to the obligation that first used it.
 * Append-only; written only through RmeRefundRequestRepository::claimPatientReference().
 */
class RmeRefundPatientReference extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'trx_rme_refund_patient_references';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (RmeRefundPatientReference $reference): void {
            throw new LogicException(sprintf('RME refund patient reference #%d is immutable.', (int) $reference->getKey()));
        });

        static::deleting(function (RmeRefundPatientReference $reference): void {
            throw new LogicException(sprintf('RME refund patient reference #%d can never be deleted.', (int) $reference->getKey()));
        });
    }

    protected function casts(): array
    {
        return [
            'rme_overpayment_obligation_id' => 'integer',
            'first_consumed_by' => 'integer',
            'first_consumed_at' => 'datetime',
        ];
    }
}
