{{--
    DMS memo M1.1–M1.3 PR2 (rule 241 §3; rule 135 mirror) — why the active RME
    is not writable for this account right now, named honestly. Rendered only
    when the controller says the account may not author (canAuthorRme /
    addSheetConsentRequired); every fact below is decided in the controller
    (MEDIUM: MEMO-F5), never by a service call here. Reading stays open.

    @param \App\Modules\ClinicVisit\Models\ClinicVisit|null $activeEncounter
    @param string|null $activeEncounterPtmState  awaiting_patient | awaiting_doctor | executed
    @param \App\Modules\Consent\Models\RmeVisitConsent|null $activeEncounterCountersignConsent
    @param string|null $activeEncounterCountersignRefusal
    @param string $ptmBannerSubject  what waits: the first sheet or the record
--}}
@if (($activeEncounter ?? null) === null)
    <x-ui.alert variant="warning" title="Belum ada pemeriksaan yang berjalan">
        <p class="max-w-2xl text-sm">
            Rekam medis hanya dapat ditulis saat pasien sedang diperiksa. Mulai
            pemeriksaan dari halaman kunjungan terlebih dahulu. Riwayat rekam medis,
            arsip RME lama dan odontogram pasien tetap dapat dibaca.
        </p>
    </x-ui.alert>
@elseif (($activeEncounterPtmState ?? null) === \App\Modules\Consent\Services\RmeVisitConsentService::PTM_EXECUTED)
    {{-- DMS-RME-REVISION-PROGRAM-5 (CW5-05) — the encounter IS consented by
         both, so the reason is this account, not a signature. --}}
    <x-ui.alert variant="info" title="Hanya dapat dibaca">
        <p class="max-w-2xl text-sm">
            Rekam medis pemeriksaan yang sedang berjalan
            (Kunjungan #{{ $activeEncounter->visit_number }}) ditulis oleh tenaga
            klinis yang memeriksa pasien. Akun ini dapat membaca rekam medis,
            arsip RME lama dan odontogram pasien.
        </p>
    </x-ui.alert>
@elseif (($activeEncounterPtmState ?? null) === \App\Modules\Consent\Services\RmeVisitConsentService::PTM_AWAITING_DOCTOR)
    <div data-ptm-state="awaiting_doctor">
        <x-ui.alert variant="warning" title="Persetujuan Tindakan Medis menunggu tanda tangan dokter">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <p class="max-w-2xl text-sm">
                    Pasien sudah menandatangani Persetujuan Tindakan Medis untuk pemeriksaan yang
                    sedang berjalan (Kunjungan #{{ $activeEncounter->visit_number }}); {{ $ptmBannerSubject ?? 'penulisan rekam medis' }}
                    menunggu tanda tangan dokter yang menangani. Riwayat rekam medis, arsip RME lama dan
                    odontogram pasien tetap dapat dibaca.
                </p>
                @if ($activeEncounterCountersignConsent ?? null)
                    <x-ui.button variant="primary" :href="route('rme.consents.countersign.create', $activeEncounterCountersignConsent)" data-testid="rme-countersign-ptm">
                        Tanda Tangani PTM
                    </x-ui.button>
                @endif
            </div>
            @if (($activeEncounterCountersignRefusal ?? null) === \App\Modules\Consent\Services\RmeVisitConsentService::MESSAGE_COUNTERSIGN_SELF)
                <p class="mt-2 text-xs" data-testid="rme-countersign-self-captured">{{ $activeEncounterCountersignRefusal }}</p>
            @endif
        </x-ui.alert>
    </div>
@else
    <div data-ptm-state="awaiting_patient">
        <x-ui.alert variant="warning" title="Persetujuan Tindakan Medis belum ditandatangani">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <p class="max-w-2xl text-sm">
                    {{ ucfirst($ptmBannerSubject ?? 'penulisan rekam medis') }} untuk pemeriksaan yang sedang berjalan
                    (Kunjungan #{{ $activeEncounter->visit_number }}) menunggu Surat Persetujuan Tindakan
                    Medis ditandatangani pasien, lalu oleh dokter yang menangani. Riwayat rekam medis, arsip
                    RME lama dan odontogram pasien tetap dapat dibaca.
                </p>
                @can('create', [\App\Modules\Consent\Models\RmeVisitConsent::class, $activeEncounter])
                    <x-ui.button variant="primary" :href="route('rme.visits.consent.create', $activeEncounter)">
                        Pilih Form Consent
                    </x-ui.button>
                @endcan
            </div>
        </x-ui.alert>
    </div>
@endif
