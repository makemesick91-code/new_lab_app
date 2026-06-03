<?php

namespace App\Modules\LabOrder\Services;

use App\Modules\LabOrder\Interfaces\LabOrderRepositoryInterface;
use Illuminate\Support\Carbon;

/**
 * Generates a unique yearly Lab Order number: ADL-YYYY-XXXXXX.
 * Called inside the order-creation transaction; DB unique constraint is the
 * final collision guard.
 */
class OrderNumberGeneratorService
{
    public function __construct(
        private readonly LabOrderRepositoryInterface $labOrders,
    ) {}

    public function generate(?string $orderDate = null): string
    {
        $year = Carbon::parse($orderDate ?? now())->format('Y');

        $latest = $this->labOrders->latestOrderNumberForYear($year);
        $sequence = $latest ? ((int) substr($latest, -6)) + 1 : 1;

        do {
            $candidate = sprintf('ADL-%s-%06d', $year, $sequence);
            $sequence++;
        } while ($this->labOrders->existsOrderNumber($candidate));

        return $candidate;
    }
}
