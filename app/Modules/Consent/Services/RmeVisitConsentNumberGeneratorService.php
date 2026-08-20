<?php

namespace App\Modules\Consent\Services;

use App\Modules\Consent\Interfaces\RmeVisitConsentRepositoryInterface;
use App\Support\Clinical\ClinicalClock;

/**
 * FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-01 — the "No :" on the printed
 * consent form.
 *
 * Mirrors the existing RmePaymentNumberGeneratorService / RmeInvoiceNumberGeneratorService
 * pattern rather than introducing a parallel numbering scheme. The period comes
 * from the clinical clock, because the number belongs to a clinical document
 * signed at the clinic's wall clock, not to a UTC instant.
 */
class RmeVisitConsentNumberGeneratorService
{
    public function __construct(
        private readonly RmeVisitConsentRepositoryInterface $consents,
        private readonly ClinicalClock $clock,
    ) {}

    public function generate(): string
    {
        $month = $this->clock->today()->format('Ym');
        $latest = $this->consents->latestConsentNumberForMonth($month);
        $next = 1;

        if ($latest && preg_match('/^CONSENT-'.$month.'-(\d{6})$/', $latest, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return 'CONSENT-'.$month.'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
