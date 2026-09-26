{{--
    REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1 — tanggal pra-verifikasi,
    ditampilkan kepada PEMERIKSA sebagai bukti READ-ONLY.

    INI ADALAH INTI SPRINT INI. Tanggal historis sudah diverifikasi sekali oleh
    petugas yang mengunggah, pada sebuah kunjungan nyata, terikat pada hash
    berkas. Pemeriksa TIDAK mengetik ulang, TIDAK memilih ulang, dan TIDAK
    diberi kolom edit.

    TIDAK ADA INPUT DI SINI — SENGAJA. Bila pemeriksa menilai tanggalnya salah,
    jalur yang benar adalah MENOLAK / mengembalikan untuk koreksi (Batalkan),
    lalu impor ulang. Mengizinkan pemeriksa mengubah tanggal lalu menerbitkan
    akan membuat bukti attestation tidak lagi cocok dengan catatan klinis yang
    diterbitkan — persis mutasi diam-diam yang dilarang.

    Partial ini tidak merender apa pun untuk dokumen jalur backlog: mereka tidak
    punya attestation, dan menampilkan panel kosong akan menyiratkan bukti yang
    tidak ada.

    Parameter:
      $import        — LegacyRmeImport | LegacyOdontogramImport
      $cancelUrl     — (opsional) URL aksi tolak/koreksi kanonik
      $showLatest    — (opsional, default true) tampilkan tanggal akhir (RME saja)
--}}
@php
    $showLatest = $showLatest ?? true;
@endphp

@if ($import->isVisitPreverified())
    <x-ui.card title="Tanggal Telah Diverifikasi">
        {{-- Rendered as real content, NOT as an x-ui.card slot: the card
             component has no `subtitle` slot, so passing one silently drops
             the label the operator is required to see. --}}
        <p class="-mt-1 mb-4 text-sm font-medium text-brand-700">
            Telah diverifikasi Admin Klinik saat upload
        </p>

        @unless ($import->hasCompleteVisitAttestation())
            {{-- Fail loudly rather than render a half-attestation as if it were
                 whole. Finalization refuses this row too. --}}
            <x-ui.alert variant="danger" title="Bukti verifikasi tidak lengkap">
                Dokumen ini ditandai sebagai terverifikasi pada kunjungan, tetapi buktinya
                tidak lengkap. Dokumen tidak dapat diterbitkan. Batalkan dan impor ulang
                melalui proses koreksi.
            </x-ui.alert>
        @endunless

        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <dt class="text-gray-500">Tanggal RME paling awal</dt>
                <dd class="font-semibold text-ink">
                    {{ optional($import->verified_selected_date)->format('d-m-Y') ?? '—' }}
                </dd>
            </div>

            @if ($showLatest)
                <div>
                    <dt class="text-gray-500">Tanggal RME paling akhir</dt>
                    <dd class="font-semibold text-ink">
                        {{ optional($import->verified_latest_date)->format('d-m-Y') ?? '—' }}
                    </dd>
                </div>
            @endif

            <div>
                <dt class="text-gray-500">Diverifikasi oleh</dt>
                <dd class="font-medium text-ink">{{ $import->verifiedBy?->name ?? '—' }}</dd>
            </div>

            <div>
                <dt class="text-gray-500">Waktu verifikasi</dt>
                <dd class="font-medium text-ink">
                    {{ optional($import->verified_at)->format('d-m-Y H:i') ?? '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">Kunjungan terkait</dt>
                <dd class="font-medium text-ink">
                    @if ($import->verification_visit_id)
                        <a href="{{ route('rme.visits.show', $import->verification_visit_id) }}"
                           class="text-brand-700 hover:text-brand-800 hover:underline">
                            #{{ $import->verification_visit_id }}
                        </a>
                    @else
                        —
                    @endif
                </dd>
            </div>

            <div>
                <dt class="text-gray-500">Tanggal kunjungan (batas historis)</dt>
                <dd class="font-medium text-ink">
                    {{ optional($import->verification_visit_date)->format('d-m-Y') ?? '—' }}
                </dd>
            </div>
        </dl>

        <x-ui.alert variant="info" class="mt-4">
            <p>
                <strong>Tanggal di atas tidak perlu diperiksa ulang.</strong>
                Tugas Anda adalah memastikan dokumen benar-benar milik pasien ini, halaman
                hasil render terbaca, dan tidak ada ketidakcocokan dokumen yang jelas.
            </p>
            <p class="mt-2">
                Bila Anda menilai tanggalnya salah, <strong>jangan terbitkan</strong>.
                Batalkan dokumen ini dan minta impor ulang melalui proses koreksi —
                tanggal tidak dapat diubah di tahap ini.
            </p>
        </x-ui.alert>

        @if (! empty($cancelUrl))
            <div class="mt-4">
                <x-ui.button variant="secondary" :href="$cancelUrl">
                    Lihat aksi koreksi
                </x-ui.button>
            </div>
        @endif
    </x-ui.card>
@endif
