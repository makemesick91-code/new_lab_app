<x-settings-shell title="Odontogram">
    @php
        $payload   = $odontogram->tooth_map_payload ?? [];
        $teethArr  = ! empty($payload['teeth']) && is_array($payload['teeth']) ? $payload['teeth'] : [];
        $isFinalized = $odontogram->isFinalized();
        // Sprint 60.2 (table-only hotfix) — odontogram input is table-only. The
        // FDI visual is a generated, read-only output rendered from saved table
        // data; the doctor never draws on the image. Odontogram remains editable
        // after finalization (Sprint 59).
        $canUpdate = auth()->user()?->can('update', $odontogram) ?? false;
        $canFinalize = (! $isFinalized) && (auth()->user()?->can('finalize', $odontogram) ?? false);

        // FDI display order for the generated visual: center → outer.
        $upperRight = [18, 17, 16, 15, 14, 13, 12, 11]; // Q1 right-to-center
        $upperLeft  = [21, 22, 23, 24, 25, 26, 27, 28]; // Q2 center-to-left
        $lowerRight = [48, 47, 46, 45, 44, 43, 42, 41]; // Q4 right-to-center
        $lowerLeft  = [31, 32, 33, 34, 35, 36, 37, 38]; // Q3 center-to-left

        // Two vertical input tables (canonical FDI set, split upper / lower jaw).
        // "Gigi 1–41" requested by the user maps to the app's canonical FDI
        // numbering (11–18, 21–28, 31–38, 41–48 = 32 teeth).
        $toothTables = [
            'Rahang Atas' => array_merge($upperRight, $upperLeft),
            'Rahang Bawah' => array_merge(array_reverse($lowerRight), $lowerLeft),
        ];

        // Odontogram status → human label (Kondisi Odontogram column).
        $statusLabels = [
            'normal'       => 'Normal',
            'caries'       => 'Karies',
            'missing'      => 'Hilang',
            'crown'        => 'Crown',
            'root_treated' => 'PSA',
        ];

        // Selected odontogram results = teeth that carry a status. Drives the
        // "appears only after save" generated visual and the read-only table.
        $selectedTeeth = [];
        foreach ($teethArr as $toothNum => $toothEntry) {
            $toothEntry = (array) $toothEntry;
            if (! empty($toothEntry['status'])) {
                $selectedTeeth[(string) $toothNum] = $toothEntry;
            }
        }
        ksort($selectedTeeth, SORT_NATURAL);

        // Tailwind classes for the generated (read-only) FDI visual cells.
        $visualClass = function (string $st): string {
            return match ($st) {
                'caries'       => 'bg-red-200 text-red-900 ring-red-400',
                'missing'      => 'bg-gray-800 text-white ring-gray-600',
                'crown'        => 'bg-amber-200 text-amber-900 ring-amber-400',
                'root_treated' => 'bg-sky-200 text-sky-900 ring-sky-400',
                'normal'       => 'bg-green-100 text-green-900 ring-green-400',
                default        => 'bg-white text-gray-600 ring-gray-200',
            };
        };

        $odontogramEditorConfig = [
            'canEdit' => $canUpdate,
            'teeth' => $teethArr,
            'statusLabels' => $statusLabels,
        ];
    @endphp

    <div
        class="space-y-6"
        x-data="odontogramEditor(@js($odontogramEditorConfig))"
    >

        {{-- Flash --}}
        @if (session('status'))
            <div class="rounded-lg bg-emerald-50 border border-emerald-200 p-4 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        {{-- Finalized notice --}}
        @if ($isFinalized)
            <div class="rounded-lg bg-sky-50 border border-sky-200 p-4 text-sm text-sky-800">
                Odontogram sudah final namun masih dapat direvisi oleh dokter.
                @if ($odontogram->finalized_at)
                    Difinalisasi pada {{ $odontogram->finalized_at->format('d/m/Y H:i') }}
                    @if ($odontogram->finalizer)
                        oleh {{ $odontogram->finalizer->name }}.
                    @else
                        .
                    @endif
                @endif
            </div>
        @endif

        {{-- Header --}}
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <h2 class="text-xl font-semibold text-gray-900">Odontogram</h2>
                    @if ($isFinalized)
                        <x-ui.badge tone="info">Final</x-ui.badge>
                    @else
                        <x-ui.badge tone="warning">Draft</x-ui.badge>
                    @endif
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $clinicVisit->visit_number }} &mdash; {{ $clinicVisit->visit_date?->format('d/m/Y') }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                {{-- Prev/next visit navigation (same patient, Odontogram page) — Sprint 59 --}}
                @include('rme.visits.partials.visit-nav-arrows', [
                    'prev' => $adjacentVisits['previous'] ?? null,
                    'next' => $adjacentVisits['next'] ?? null,
                    'routeName' => 'rme.visits.odontogram.show',
                ])

                <x-ui.button variant="secondary" :href="route('rme.visits.show', $clinicVisit)">
                    &larr; Kembali ke Kunjungan
                </x-ui.button>

                @can('print', $odontogram)
                    <x-ui.button variant="secondary" :href="route('rme.odontograms.print', $odontogram)" target="_blank">
                        Cetak Odontogram
                    </x-ui.button>
                @endcan

                @if ($canFinalize)
                    <form method="POST" action="{{ route('rme.odontograms.finalize', $odontogram) }}"
                          onsubmit="return confirm('Finalisasi odontogram ini? Data masih dapat direvisi setelah final.')">
                        @csrf
                        <x-ui.button type="submit" variant="primary">Finalisasi Odontogram</x-ui.button>
                    </form>
                @endif
            </div>
        </div>

        <x-ui.card title="Informasi Kunjungan">
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Pasien</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->patient?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dokter</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->doctor?->name ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        @if (! empty($parentOdontogram))
            <x-ui.card title="Odontogram Kunjungan Sebelumnya">
                <p class="text-sm text-gray-600 mb-3">
                    Referensi dari kunjungan
                    <a href="{{ route('rme.visits.show', $clinicVisit->followUpOf) }}" class="font-mono text-teal-700 hover:text-teal-900">{{ $clinicVisit->followUpOf?->visit_number }}</a>.
                    Odontogram kunjungan kontrol tetap terpisah dan tidak menimpa data lama.
                </p>
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.badge :tone="$parentOdontogram->isFinalized() ? 'success' : 'warning'">
                        {{ $parentOdontogram->isFinalized() ? 'Final' : 'Draft' }}
                    </x-ui.badge>
                    @if ($clinicVisit->followUpOf)
                        <x-ui.button variant="secondary" :href="route('rme.visits.odontogram.show', $clinicVisit->followUpOf)">
                            Lihat Odontogram Sebelumnya
                        </x-ui.button>
                    @endif
                </div>
            </x-ui.card>
        @endif

        {{-- ============================================================ --}}
        {{-- INPUT — table only. Doctor fills the two vertical tables.      --}}
        {{-- The doctor never draws on an image; the visual below is output. --}}
        {{-- ============================================================ --}}
        @if ($canUpdate)
            <form method="POST" action="{{ route('rme.odontograms.update', $odontogram) }}" class="space-y-6">
                @csrf
                @method('PATCH')

                {{-- Hidden reactive payload — Alpine.js keeps this current --}}
                <input type="hidden" name="tooth_map_payload" :value="getPayload()" />

                @error('tooth_map_payload.teeth')
                    <div class="rounded-lg bg-rose-50 border border-rose-200 p-3 text-sm text-rose-700">
                        {{ $message }}
                    </div>
                @enderror

                <x-ui.card title="Hasil Odontogram yang Dipilih">
                    <p class="mb-4 text-xs text-gray-500">
                        Input odontogram berbasis tabel. Pilih <span class="font-medium text-gray-700">Kondisi
                        Odontogram</span> per gigi, lalu lengkapi <span class="font-medium text-gray-700">Kondisi
                        Tambahan</span> dan <span class="font-medium text-gray-700">Catatan Tambahan</span>.
                        Visual <span class="font-medium text-gray-700">Peta Gigi (FDI)</span> dihasilkan otomatis
                        dari tabel ini setelah disimpan — dokter tidak menggambar pada gambar.
                    </p>

                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        @foreach ($toothTables as $tableLabel => $tableTeeth)
                            <div>
                                <h4 class="mb-2 text-sm font-semibold text-gray-900">{{ $tableLabel }}</h4>
                                <div class="overflow-x-auto rounded-lg ring-1 ring-gray-200">
                                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                                        <thead class="bg-gray-50">
                                            <tr class="text-left text-[11px] font-medium uppercase tracking-wide text-gray-500">
                                                <th class="px-2 py-2 w-14">Gigi</th>
                                                <th class="px-2 py-2 w-28">Kondisi Odontogram</th>
                                                <th class="px-2 py-2">Kondisi Tambahan</th>
                                                <th class="px-2 py-2">Catatan Tambahan</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            @foreach ($tableTeeth as $tooth)
                                                <tr class="align-top">
                                                    <td class="px-2 py-2 font-semibold text-gray-900">{{ $tooth }}</td>
                                                    <td class="px-2 py-2">
                                                        <select
                                                            :value="rowStatus('{{ $tooth }}')"
                                                            @change="pickStatus('{{ $tooth }}', $event.target.value)"
                                                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 text-sm">
                                                            <option value="">—</option>
                                                            @foreach ($statusLabels as $value => $label)
                                                                <option value="{{ $value }}">{{ $label }}</option>
                                                            @endforeach
                                                        </select>
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <input
                                                            type="text"
                                                            maxlength="1000"
                                                            placeholder="Kondisi tambahan…"
                                                            :value="rowField('{{ $tooth }}', 'additional_condition')"
                                                            :disabled="! rowStatus('{{ $tooth }}')"
                                                            @input="setAdditional('{{ $tooth }}', 'additional_condition', $event.target.value)"
                                                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 text-sm disabled:bg-gray-100 disabled:text-gray-400">
                                                    </td>
                                                    <td class="px-2 py-2">
                                                        <textarea
                                                            rows="1"
                                                            maxlength="1000"
                                                            placeholder="Catatan tambahan…"
                                                            :value="rowField('{{ $tooth }}', 'additional_note')"
                                                            :disabled="! rowStatus('{{ $tooth }}')"
                                                            @input="setAdditional('{{ $tooth }}', 'additional_note', $event.target.value)"
                                                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 text-sm disabled:bg-gray-100 disabled:text-gray-400"></textarea>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>

                <div class="flex justify-end">
                    <x-ui.button type="submit" variant="primary">Simpan Odontogram</x-ui.button>
                </div>
            </form>
        @else
            {{-- Read-only display (viewer) — table only, no image input --}}
            <x-ui.card title="Hasil Odontogram yang Dipilih">
                @if (count($selectedTeeth) > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium uppercase tracking-wide text-gray-500">
                                    <th class="px-3 py-2 w-12">No</th>
                                    <th class="px-3 py-2 w-24">Gigi / Area</th>
                                    <th class="px-3 py-2 w-32">Kondisi Odontogram</th>
                                    <th class="px-3 py-2">Kondisi Tambahan</th>
                                    <th class="px-3 py-2">Catatan Tambahan</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-700">
                                @foreach ($selectedTeeth as $toothNum => $toothEntry)
                                    <tr class="align-top">
                                        <td class="px-3 py-2 text-gray-500">{{ $loop->iteration }}</td>
                                        <td class="px-3 py-2 font-semibold text-gray-900">{{ $toothNum }}</td>
                                        <td class="px-3 py-2">
                                            {{ $statusLabels[$toothEntry['status'] ?? ''] ?? ucfirst($toothEntry['status'] ?? '—') }}
                                        </td>
                                        <td class="px-3 py-2 whitespace-pre-wrap">{{ ($toothEntry['additional_condition'] ?? '') !== '' ? $toothEntry['additional_condition'] : '—' }}</td>
                                        <td class="px-3 py-2 whitespace-pre-wrap">{{ ($toothEntry['additional_note'] ?? '') !== '' ? $toothEntry['additional_note'] : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-gray-500 italic">Belum ada kondisi odontogram yang dipilih.</p>
                @endif
            </x-ui.card>
        @endif

        {{-- ============================================================ --}}
        {{-- OUTPUT — generated FDI visual. Read-only, derived from saved   --}}
        {{-- table data, shown only after a save produced selected teeth.   --}}
        {{-- ============================================================ --}}
        <x-ui.card title="Peta Gigi (FDI)">
            @if (count($selectedTeeth) > 0)
                <p class="mb-3 text-xs text-gray-500">
                    Visual ini dihasilkan otomatis dari tabel yang tersimpan dan tidak dapat diedit langsung.
                    Ubah tabel di atas lalu simpan untuk memperbarui visual.
                </p>

                {{-- Legend --}}
                <div class="mb-4 flex flex-wrap gap-x-4 gap-y-2 text-xs text-gray-700">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded ring-1 ring-inset ring-green-400 bg-green-100 inline-block flex-shrink-0"></span>
                        Normal
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded ring-1 ring-inset ring-red-400 bg-red-200 inline-block flex-shrink-0"></span>
                        Karies
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded ring-1 ring-inset ring-gray-600 bg-gray-800 inline-block flex-shrink-0"></span>
                        Hilang
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded ring-1 ring-inset ring-amber-400 bg-amber-200 inline-block flex-shrink-0"></span>
                        Crown
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded ring-1 ring-inset ring-sky-400 bg-sky-200 inline-block flex-shrink-0"></span>
                        PSA
                    </span>
                </div>

                {{-- Generated tooth grid (read-only output) --}}
                <div class="overflow-x-auto -mx-2 px-2">
                    <div class="inline-flex flex-col items-center min-w-max">
                        <div class="flex w-full mb-0.5 text-[10px] text-gray-400 select-none">
                            <div class="flex-1 text-right pr-3">← Kanan atas (Q1)</div>
                            <div class="w-3"></div>
                            <div class="flex-1 text-left pl-3">Kiri atas (Q2) →</div>
                        </div>

                        <div class="flex items-stretch gap-0.5">
                            @foreach (array_merge($upperRight, [null], $upperLeft) as $tooth)
                                @if ($tooth === null)
                                    <div class="w-px bg-gray-400 mx-1 self-stretch flex-shrink-0"></div>
                                @else
                                    @php $st = $selectedTeeth[(string) $tooth]['status'] ?? ''; @endphp
                                    <div class="w-9 h-9 rounded ring-1 ring-inset text-[10px] font-bold flex items-center justify-center select-none {{ $visualClass($st) }}" title="Gigi {{ $tooth }}">{{ $tooth }}</div>
                                @endif
                            @endforeach
                        </div>

                        <div class="w-full border-t-2 border-dashed border-gray-300 my-2"></div>

                        <div class="flex items-stretch gap-0.5">
                            @foreach (array_merge($lowerRight, [null], $lowerLeft) as $tooth)
                                @if ($tooth === null)
                                    <div class="w-px bg-gray-400 mx-1 self-stretch flex-shrink-0"></div>
                                @else
                                    @php $st = $selectedTeeth[(string) $tooth]['status'] ?? ''; @endphp
                                    <div class="w-9 h-9 rounded ring-1 ring-inset text-[10px] font-bold flex items-center justify-center select-none {{ $visualClass($st) }}" title="Gigi {{ $tooth }}">{{ $tooth }}</div>
                                @endif
                            @endforeach
                        </div>

                        <div class="flex w-full mt-0.5 text-[10px] text-gray-400 select-none">
                            <div class="flex-1 text-right pr-3">← Kanan bawah (Q4)</div>
                            <div class="w-3"></div>
                            <div class="flex-1 text-left pl-3">Kiri bawah (Q3) →</div>
                        </div>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500 italic">
                    Belum ada kondisi odontogram yang dipilih. Visual Peta Gigi akan muncul otomatis
                    setelah tabel odontogram disimpan.
                </p>
            @endif
        </x-ui.card>

    </div>
</x-settings-shell>
