<?php

namespace App\Modules\Consent\Repositories;

use App\Modules\Consent\Interfaces\RmeVisitConsentRepositoryInterface;
use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Models\RmeVisitConsentDoctorSignature;
use App\Support\Database\DocumentNumberSequenceLock;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

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

    public function validForVisits(array $clinicVisitIds): Collection
    {
        if ($clinicVisitIds === []) {
            return new Collection;
        }

        // Latest live consent per visit, the same order validForVisit() uses.
        return RmeVisitConsent::query()
            ->with('doctorCountersignature')
            ->whereIn('clinic_visit_id', $clinicVisitIds)
            ->valid()
            ->orderBy('signed_at')
            ->orderBy('id')
            ->get()
            ->keyBy('clinic_visit_id')
            ->toBase();
    }

    public function doctorSignatureFor(int $consentId): ?RmeVisitConsentDoctorSignature
    {
        return RmeVisitConsentDoctorSignature::query()
            ->where('rme_visit_consent_id', $consentId)
            ->first();
    }

    public function createDoctorSignature(array $attributes): RmeVisitConsentDoctorSignature
    {
        return RmeVisitConsentDoctorSignature::create($attributes);
    }

    /**
     * DMS-RME-REVISION-PROGRAM-8B / D2 — serialise the month's consent numbering
     * (rule 225 pattern). Must be called inside the transaction that inserts the
     * consent, before {@see latestConsentNumberForMonth()}. Consents have no
     * soft delete: a voided consent stays a row and keeps its number.
     */
    public function lockNumberSequence(string $month): void
    {
        DocumentNumberSequenceLock::acquire(DocumentNumberSequenceLock::RME_VISIT_CONSENT, $month);
    }

    public function latestConsentNumberForMonth(string $month): ?string
    {
        return RmeVisitConsent::query()
            ->where('consent_number', 'like', 'CONSENT-'.$month.'-%')
            ->orderByDesc('consent_number')
            ->value('consent_number');
    }

    public function signatureReferences(): LazyCollection
    {
        return RmeVisitConsent::query()
            ->select(['id', 'consenter_signature_path', 'doctor_signature_path'])
            ->lazyById(200);
    }
}
