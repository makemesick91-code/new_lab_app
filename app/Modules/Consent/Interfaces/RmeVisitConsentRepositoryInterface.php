<?php

namespace App\Modules\Consent\Interfaces;

use App\Modules\Consent\Models\RmeVisitConsent;
use Illuminate\Support\Collection;

interface RmeVisitConsentRepositoryInterface
{
    /**
     * The live consent for a visit, or null. "Live" means signed and not
     * voided. This is what the payment gate reads.
     */
    public function validForVisit(int $clinicVisitId): ?RmeVisitConsent;

    /**
     * Every consent ever recorded for a visit, newest first, including voided
     * ones — the evidence trail.
     *
     * @return Collection<int, RmeVisitConsent>
     */
    public function historyForVisit(int $clinicVisitId): Collection;

    public function create(array $attributes): RmeVisitConsent;

    /**
     * The most recent consent number issued in a given YYYYMM period, used to
     * derive the next sequence value.
     */
    public function latestConsentNumberForMonth(string $month): ?string;
}
