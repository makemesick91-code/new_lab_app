<?php

namespace App\Modules\Consent\Interfaces;

use App\Modules\Consent\Models\RmeVisitConsent;
use App\Modules\Consent\Models\RmeVisitConsentDoctorSignature;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

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
     * DMS memo M1.1–M1.3 (rule 241 §1) — the live consent of each given visit,
     * keyed by clinic_visit_id, with its doctor countersignature eager-loaded.
     *
     * @param  list<int>  $clinicVisitIds
     * @return Collection<int, RmeVisitConsent>
     */
    public function validForVisits(array $clinicVisitIds): Collection;

    /** Rule 241 §1 — the consent's doctor countersignature, or null. */
    public function doctorSignatureFor(int $consentId): ?RmeVisitConsentDoctorSignature;

    /** Rule 241 §1 — insert the countersignature (UNIQUE per consent; insert-only). */
    public function createDoctorSignature(array $attributes): RmeVisitConsentDoctorSignature;

    /**
     * The most recent consent number issued in a given YYYYMM period, used to
     * derive the next sequence value.
     */
    /** DMS-RME-REVISION-PROGRAM-8B / D2 — the month's number sequence lock (inside a transaction). */
    public function lockNumberSequence(string $month): void;

    public function latestConsentNumberForMonth(string $month): ?string;

    /**
     * DMS-RME-COMPREHENSIVE-CLOSURE-1 (OD-5-9) — every stored signature reference,
     * voided consents included (evidence), in id order, lazily: one row per
     * consent with `id`, `consenter_signature_path`, `doctor_signature_path` only.
     *
     * @return LazyCollection<int, RmeVisitConsent>
     */
    public function signatureReferences(): LazyCollection;
}
