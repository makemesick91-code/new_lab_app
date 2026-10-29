<?php

namespace App\Modules\Delivery\Services;

use App\Modules\Delivery\Interfaces\DeliveryRepositoryInterface;
use App\Support\Clinical\ClinicalClock;

class DeliveryNumberGeneratorService
{
    public function __construct(
        private readonly DeliveryRepositoryInterface $deliveries,
        private readonly ClinicalClock $clock,
    ) {}

    public function generate(): string
    {
        $year = $this->clock->today()->format('Y');
        $latest = $this->deliveries->latestDeliveryNumberForYear($year);
        $next = 1;

        if ($latest && preg_match('/^DLV-'.$year.'-(\d{6})$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return 'DLV-'.$year.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
