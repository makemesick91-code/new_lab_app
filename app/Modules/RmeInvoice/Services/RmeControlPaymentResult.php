<?php

namespace App\Modules\RmeInvoice\Services;

use App\Modules\RmeInvoice\Models\RmePayment;
use Illuminate\Support\Collection;

class RmeControlPaymentResult
{
    /**
     * @param  Collection<int, RmePayment>  $parentPayments
     */
    public function __construct(
        public readonly Collection $parentPayments,
        public readonly ?RmePayment $controlPayment,
        public readonly float $allocatedToParent,
        public readonly float $allocatedToControl,
        public readonly string $paymentBatchUuid,
    ) {}

    public function primaryPayment(): ?RmePayment
    {
        return $this->controlPayment ?? $this->parentPayments->last();
    }

    public function totalPaid(): float
    {
        return round($this->allocatedToParent + $this->allocatedToControl, 2);
    }
}
