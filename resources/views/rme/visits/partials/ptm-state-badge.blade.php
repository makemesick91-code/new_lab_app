{{--
    DMS memo M1.1–M1.3 PR2 (rule 241 §3) — the PTM state of one in-progress
    visit, as decided by RmeVisitConsentService::ptmStatesFor() in the
    controller. Presentation only.

    @param string|null $ptmState  awaiting_patient | awaiting_doctor | executed
--}}
@if (($ptmState ?? null) === \App\Modules\Consent\Services\RmeVisitConsentService::PTM_AWAITING_PATIENT)
    <x-ui.badge tone="warning" class="mt-1" data-ptm-state="awaiting_patient">PTM: menunggu pasien</x-ui.badge>
@elseif (($ptmState ?? null) === \App\Modules\Consent\Services\RmeVisitConsentService::PTM_AWAITING_DOCTOR)
    <x-ui.badge tone="warning" class="mt-1" data-ptm-state="awaiting_doctor">PTM: menunggu dokter</x-ui.badge>
@elseif (($ptmState ?? null) === \App\Modules\Consent\Services\RmeVisitConsentService::PTM_EXECUTED)
    <x-ui.badge tone="success" class="mt-1" data-ptm-state="executed">PTM: lengkap</x-ui.badge>
@endif
