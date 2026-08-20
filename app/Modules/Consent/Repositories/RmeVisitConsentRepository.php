<?php

namespace App\Modules\Consent\Repositories;

use App\Modules\Consent\Interfaces\RmeVisitConsentRepositoryInterface;
use App\Modules\Consent\Models\RmeVisitConsent;
use Illuminate\Support\Collection;

class RmeVisitConsentRepository implements RmeVisitConsentRepositoryInterface
{
    public function validForVisit(int $clinicVisitId): ?RmeVisitConsent
    {
        return RmeVisitConsent::query()
            ->where('clinic_visit_id', $clinicVisitId)
            ->valid()
            ->latest('signed_at')
            ->latest('id')
            ->first();
    }

    public function historyForVisit(int $clinicVisitId): Collection
    {
        return RmeVisitConsent::query()
            ->where('clinic_visit_id', $clinicVisitId)
            ->latest('signed_at')
            ->latest('id')
            ->get();
    }

    public function create(array $attributes): RmeVisitConsent
    {
        return RmeVisitConsent::create($attributes);
    }

    public function latestConsentNumberForMonth(string $month): ?string
    {
        return RmeVisitConsent::query()
            ->where('consent_number', 'like', 'CONSENT-'.$month.'-%')
            ->orderByDesc('consent_number')
            ->value('consent_number');
    }
}
