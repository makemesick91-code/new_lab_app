{{--
    FEATURE-LEGACY-IMPORT-HUB-1 — Import Data Legacy.

    PRESENTATION ONLY, AND ADVISORY ONLY. Every value on this page was computed
    server-side in LegacyImportHubService from the actor's own branch scope. The
    ceiling that actually admits a record is taken inside the transaction that
    writes it, so a number here is a report of the recent past, never a promise
    about the next upload.

    No patient name, no Nomor RM, no KTP/NIK, no filename and no document path
    is rendered here — counts, limits, branch codes and labels only.
--}}
<x-settings-shell title="Import Data Legacy">
    <div class="space-y-6">
        <x-ui.page-header
            title="Import Data Legacy"
            subtitle="Satu tempat untuk seluruh impor data lama: pasien, rekam medis, dan odontogram."
        >
            <x-slot:breadcrumb>Import Data Legacy</x-slot:breadcrumb>
        </x-ui.page-header>

        <x-ui.alert variant="info" title="Data lama, bukan pemeriksaan berjalan">
            Impor legacy hanya menambahkan <strong>arsip riwayat</strong>. Tidak ada kunjungan, rekam medis
            berjalan, odontogram baru, tagihan, pembayaran, order lab, maupun pengiriman SATUSEHAT yang dibuat
            dari proses ini, dan antrean serta laporan pendapatan tidak terpengaruh.
        </x-ui.alert>

        <x-ui.card>
            <div class="text-sm text-ink-soft">
                <p>
                    Kuota harian berlaku <strong>per cabang</strong>, <strong>per hari klinis</strong>, dan
                    <strong>per jenis impor</strong>. Ketiga jenis dihitung terpisah — kuota RME yang habis
                    tidak mengurangi kuota Pasien maupun Odontogram.
                </p>
                <p class="mt-2">
                    Hari klinis berjalan: <strong>{{ $overview['clinical_date'] }}</strong>
                    <span class="text-ink-muted">({{ $overview['timezone'] }})</span>.
                </p>
            </div>
        </x-ui.card>

        @if ($overview['branches'] === [])
            <x-ui.alert variant="warning" title="Cabang belum dapat ditentukan">
                Akun Anda belum terhubung ke cabang RME aktif mana pun, sehingga kuota harian tidak dapat
                ditampilkan. Hubungi admin untuk menetapkan cabang. Ini hanya menyembunyikan angka —
                setiap unggahan tetap divalidasi dan dibatasi di sisi server.
            </x-ui.alert>
        @endif

        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($overview['types'] as $card)
                <x-ui.card>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-navy">{{ $card['label'] }}</h3>
                            <p class="mt-1 text-xs text-ink-muted">{{ $card['description'] }}</p>
                        </div>

                        @if ($card['status'] === 'aktif')
                            <x-ui.badge tone="success">Aktif</x-ui.badge>
                        @elseif ($card['status'] === 'belum_dibuka')
                            <x-ui.badge tone="warning">Belum Dibuka</x-ui.badge>
                        @elseif ($card['status'] === 'nonaktif')
                            <x-ui.badge tone="warning">Nonaktif</x-ui.badge>
                        @elseif ($card['status'] === 'tanpa_akses')
                            <x-ui.badge tone="neutral">Tanpa Akses</x-ui.badge>
                        @else
                            <x-ui.badge tone="danger">Tidak Tersedia</x-ui.badge>
                        @endif
                    </div>

                    <dl class="mt-4 space-y-1 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-ink-muted">Limit / cabang / hari</dt>
                            <dd class="font-semibold text-navy">
                                {{ $card['limit'] === null ? 'Tanpa batas' : $card['limit'] }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-ink-muted">Terpakai hari ini</dt>
                            <dd class="font-semibold text-navy">{{ $card['used_today'] }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-ink-muted">Sisa</dt>
                            <dd class="font-semibold text-navy">
                                {{ $card['remaining_today'] === null ? 'Tanpa batas' : $card['remaining_today'] }}
                            </dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-xs text-ink-muted">Satuan: {{ $card['unit'] }}.</p>

                    @if ($card['status'] === 'nonaktif')
                        <p class="mt-3 text-xs text-warning-700">
                            Kapabilitas ini sedang dimatikan lewat feature flag, sehingga setiap unggahan ditolak
                            di sisi server. Arsip yang sudah terbit tetap dapat dibaca.
                        </p>
                    @endif

                    {{--
                        FEATURE-LEGACY-IMPORT-HUB-1A — the extra gates are now REPORTED, not
                        merely disclaimed. `blocker` is a stable machine code produced by
                        LegacyRmeActivationStateService; the sentence for each one lives here
                        so the service never has to carry operator-facing prose.
                    --}}
                    @if ($card['additional_gates'] !== null && $card['additional_gates']['open'])
                        <p class="mt-3 text-xs text-success-700">
                            Cabang yang diizinkan: {{ implode(', ', $card['additional_gates']['admitted_branch_codes']) }}.
                            Gelombang migrasi {{ $card['additional_gates']['registered_wave'] }} sedang aktif, sehingga
                            impor baru dapat dimulai untuk operator yang ditugaskan.
                        </p>
                    @elseif ($card['additional_gates'] !== null && $card['status'] !== 'nonaktif')
                        <p class="mt-3 text-xs text-warning-700">
                            @switch ($card['additional_gates']['blocker'])
                                @case ('NO_BRANCH_ADMITTED')
                                    Kapabilitas sudah menyala, tetapi belum ada cabang yang diizinkan memulai migrasi,
                                    sehingga setiap unggahan masih ditolak di sisi server.
                                    @break
                                @case ('APPROVAL_MISSING')
                                    Cabang sudah diizinkan, tetapi gelombang migrasi belum memiliki referensi persetujuan.
                                    @break
                                @case ('APPROVAL_INCOMPLETE')
                                    Ada cabang yang diizinkan di luar cakupan persetujuan yang berlaku:
                                    {{ implode(', ', $card['additional_gates']['unapproved_admitted_branch_codes']) }}.
                                    @break
                                @case ('WAVE_NOT_DECLARED')
                                    Cabang sudah diizinkan, tetapi belum ada gelombang migrasi yang dideklarasikan.
                                    @break
                                @case ('WAVE_NOT_REGISTERED')
                                    Gelombang {{ $card['additional_gates']['declared_wave'] }} belum terdaftar sebagai
                                    gelombang migrasi operasional.
                                    @break
                                @case ('WAVE_NOT_ACTIVE')
                                    Gelombang {{ $card['additional_gates']['registered_wave'] }} berstatus
                                    {{ $card['additional_gates']['wave_status'] }}, sehingga belum menerima dokumen baru.
                                    @break
                                @case ('WAVE_BINDING_MISMATCH')
                                    Catatan gelombang migrasi tidak cocok dengan persetujuan yang berlaku pada deployment ini.
                                    @break
                                @case ('WAVE_UNREADABLE')
                                    Catatan gelombang migrasi tidak dapat dibaca, sehingga impor baru ditahan.
                                    @break
                                @default
                                    Impor RME belum dapat dimulai karena gerbang admission atau gelombang migrasi belum terbuka.
                            @endswitch
                        </p>
                    @endif

                    @if ($card['limit_clamped'])
                        <p class="mt-3 text-xs text-warning-700">
                            Limit yang dikonfigurasi melebihi batas maksimum yang boleh dideklarasikan, sehingga
                            dipotong ke {{ $card['limit'] }}.
                        </p>
                    @endif

                    @if ($card['may_view'] && $card['index_route'] !== null)
                        <div class="mt-4 flex flex-wrap gap-2">
                            <x-ui.button size="sm" variant="secondary" :href="route($card['index_route'])">
                                Buka
                            </x-ui.button>

                            @if ($card['may_create'] && $card['create_route'] !== null)
                                <x-ui.button size="sm" :href="route($card['create_route'])">
                                    Unggah
                                </x-ui.button>
                            @endif
                        </div>
                    @endif
                </x-ui.card>
            @endforeach
        </div>

        @if ($overview['branches'] !== [])
            <x-ui.card title="Pemakaian Kuota per Cabang Hari Ini">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-hairline text-sm">
                        <thead class="bg-navy-50 text-left text-xs uppercase tracking-wide text-ink-soft">
                            <tr>
                                <th class="px-3 py-2">Cabang</th>
                                @foreach ($overview['types'] as $card)
                                    <th class="px-3 py-2 text-right">{{ $card['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($overview['branches'] as $branch)
                                <tr>
                                    <td class="px-3 py-2">
                                        <span class="font-medium text-navy">{{ $branch['code'] }}</span>
                                        <span class="text-ink-muted">— {{ $branch['name'] }}</span>
                                    </td>

                                    @foreach ($overview['types'] as $card)
                                        @php
                                            $row = collect($card['rows'])->firstWhere('branch_id', $branch['id']);
                                        @endphp
                                        <td class="px-3 py-2 text-right text-ink">
                                            @if ($row === null)
                                                <span class="text-ink-muted">&mdash;</span>
                                            @elseif ($row['limit'] === null)
                                                {{ $row['used'] }} <span class="text-ink-muted">/ &infin;</span>
                                            @else
                                                {{ $row['used'] }} <span class="text-ink-muted">/ {{ $row['limit'] }}</span>
                                                <span class="ml-1 text-xs text-ink-muted">(sisa {{ $row['remaining'] }})</span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.card>
        @endif
    </div>
</x-settings-shell>
