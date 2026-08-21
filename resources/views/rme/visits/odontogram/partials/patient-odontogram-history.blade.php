{{--
    FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-04 — Riwayat Odontogram Pasien.

    The patient's PREVIOUS odontograms — native charts from earlier visits and,
    when the archive is in use, published Legacy Odontogram documents — rendered
    strictly READ-ONLY beneath the active chart.

    READ-ONLY BY CONSTRUCTION, not by hiding:
      * every row is server-rendered from data the controller already authorized;
      * there is no form, no input, no save/finalize/delete control and no
        mutation endpoint that accepts a history row;
      * the active odontogram editor above is a separate surface and is
        deliberately UNCHANGED by this sprint.

    Correcting a past chart is still done by opening that visit's own odontogram
    page, which keeps the Sprint 59 "any visit is revisable" behaviour intact.

    Expects:
      $odontogramHistory  Collection<int, array>  — read-only view-model rows
      $clinicVisit        ClinicVisit             — the visit being charted now

    Privacy: date, branch, doctor and clinical findings only. Never KTP/NIK.
--}}
<x-ui.card title="Riwayat Odontogram Pasien">
    <x-slot:actions>
        <x-ui.badge tone="neutral">Hanya Baca</x-ui.badge>
    </x-slot:actions>

    <p class="mb-4 text-xs text-gray-500">
        Odontogram dari kunjungan sebelumnya milik pasien ini. Bagian ini hanya untuk dibaca —
        odontogram kunjungan aktif tetap diisi pada tabel di atas. Untuk memperbaiki odontogram
        lama, buka halaman odontogram kunjungan tersebut.
    </p>

    @if ($odontogramHistory->isEmpty())
        <x-ui.empty-state
            title="Belum ada riwayat odontogram"
            description="Pasien ini belum memiliki odontogram tersimpan dari kunjungan sebelumnya." />
    @else
        <div class="space-y-3" data-odontogram-history>
            @foreach ($odontogramHistory as $entry)
                <div class="rounded-lg ring-1 ring-hairline" x-data="{ open: false }">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-navy">
                                    {{ $entry['date'] ? \Illuminate\Support\Carbon::parse($entry['date'])->format('d/m/Y') : '—' }}
                                </span>
                                <x-ui.badge :tone="$entry['source'] === 'legacy' ? 'warning' : 'info'">
                                    {{ $entry['source_label'] }}
                                </x-ui.badge>
                                @if ($entry['source'] === 'native' && ($entry['status'] ?? null) === 'finalized')
                                    <x-ui.badge tone="success">Final</x-ui.badge>
                                @endif
                            </div>
                            <p class="mt-0.5 truncate text-xs text-ink-muted">
                                {{ $entry['branch_code'] ?? '—' }}
                                @if (! empty($entry['branch_name']))
                                    — {{ $entry['branch_name'] }}
                                @endif
                                &middot; Dokter: {{ $entry['doctor_name'] ?? '—' }}
                                @if (! empty($entry['visit_number']))
                                    &middot; {{ $entry['visit_number'] }}
                                @endif
                            </p>
                        </div>

                        <div class="flex items-center gap-3">
                            @if (! empty($entry['dmft']))
                                <span class="text-xs text-ink-soft">
                                    D {{ $entry['dmft']['D'] ?? 0 }}
                                    &middot; M {{ $entry['dmft']['M'] ?? 0 }}
                                    &middot; F {{ $entry['dmft']['F'] ?? 0 }}
                                    &middot; DMF-T {{ $entry['dmft']['DMFT'] ?? 0 }}
                                </span>
                            @endif

                            @if (! empty($entry['view_url']))
                                {{-- Legacy archive documents live on a private disk and are
                                     served only through their own policy-gated read route. --}}
                                <x-ui.button variant="secondary" size="sm" :href="$entry['view_url']">
                                    Lihat
                                </x-ui.button>
                            @else
                                <button type="button"
                                    class="rounded-md border border-hairline px-3 py-1.5 text-sm font-medium text-ink-soft hover:bg-navy-50 focus:outline-none focus:ring-2 focus:ring-brand-500"
                                    x-on:click="open = ! open"
                                    x-bind:aria-expanded="open ? 'true' : 'false'">
                                    <span x-show="! open">Lihat</span>
                                    <span x-show="open" x-cloak>Tutup</span>
                                </button>
                            @endif
                        </div>
                    </div>

                    @if (empty($entry['view_url']))
                        <div class="border-t border-hairline px-4 py-3" x-show="open" x-cloak>
                            <p class="mb-2 text-xs text-ink-muted">
                                Riwayat — hanya baca. Tidak dapat diubah dari halaman ini.
                            </p>
                            @if (! empty($entry['structured']['table_rows']))
                                <div class="overflow-x-auto rounded-lg ring-1 ring-gray-200">
                                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50">
                                            <tr class="text-left text-[11px] font-medium uppercase tracking-wide text-gray-500">
                                                <th class="px-3 py-2 w-16">GIGI</th>
                                                <th class="px-3 py-2 w-56">DIAGNOSA</th>
                                                <th class="px-3 py-2">PERAWATAN</th>
                                                <th class="px-3 py-2 w-44">DOKTER</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 text-gray-700">
                                            @foreach ($entry['structured']['table_rows'] as $row)
                                                <tr class="align-top">
                                                    <td class="px-3 py-2 font-semibold text-gray-900">{{ $row['gigi'] }}</td>
                                                    <td class="px-3 py-2 whitespace-pre-wrap">{{ $row['diagnosa'] !== '' ? $row['diagnosa'] : '—' }}</td>
                                                    <td class="px-3 py-2 whitespace-pre-wrap">{{ $row['perawatan'] !== '' ? $row['perawatan'] : '—' }}</td>
                                                    <td class="px-3 py-2">{{ $row['dokter'] !== '' ? $row['dokter'] : '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @else
                                <p class="text-sm text-gray-500 italic">
                                    Tidak ada rincian gigi yang tersimpan pada odontogram ini.
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-ui.card>
