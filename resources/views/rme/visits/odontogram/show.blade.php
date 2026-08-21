<x-settings-shell title="Odontogram">
    @php
        $payload   = $odontogram->tooth_map_payload ?? [];
        $teethArr  = ! empty($payload['teeth']) && is_array($payload['teeth']) ? $payload['teeth'] : [];
        $isFinalized = $odontogram->isFinalized();
        // Hotfix Sprint 60.3 (Daengtisia PDF template parity) — odontogram input
        // stays table-only with the official columns GIGI / DIAGNOSA / PERAWATAN /
        // DOKTER. The FDI visual (permanent + primary teeth) and the Jumlah
        // D/M/F/DMF-T tally are generated, read-only outputs derived only from the
        // saved table data; the doctor never draws on the image. Odontogram remains
        // editable after finalization (Sprint 59).
        //
        // CORRECTIVE-03 — authoring additionally requires a signed Persetujuan
        // Tindakan Medis on the LIVE encounter. `$odontogramAuthoringAllowed` is
        // supplied by the controller from RmeVisitConsentService, which is the
        // authority; this is presentation only, and the server refuses the write
        // regardless of what is rendered here.
        //
        // POST-RME-ODONTOGRAM-STABILIZATION-1 / FIX-01 — `$odontogram` may be an
        // UNSAVED instance, because opening this page no longer creates a row.
        // Reading it is safe everywhere (a fresh instance is an empty draft),
        // but anything that puts the model in a URL needs `->exists` first: an
        // unsaved model has no route key and route() would throw.
        $odontogramExists = $odontogram->exists;

        // Ask for the ability the SERVER will actually enforce on submit:
        // `update` for a revision of a saved chart, `author` for the first one.
        // Asking the wrong one could render a save form the server then refuses.
        $canUpdate = ($odontogramAuthoringAllowed ?? false)
            && ($odontogramExists
                ? (auth()->user()?->can('update', $odontogram) ?? false)
                : (auth()->user()?->can('author', [\App\Modules\Odontogram\Models\Odontogram::class, $clinicVisit]) ?? false));
        $canFinalize = $odontogramExists
            && ($odontogramAuthoringAllowed ?? false)
            && (! $isFinalized)
            && (auth()->user()?->can('finalize', $odontogram) ?? false);

        // FDI display order for the generated visual: center → outer per side.
        // Permanent (quadrants 1–4).
        $upperRight = [18, 17, 16, 15, 14, 13, 12, 11]; // Q1
        $upperLeft  = [21, 22, 23, 24, 25, 26, 27, 28]; // Q2
        $lowerRight = [48, 47, 46, 45, 44, 43, 42, 41]; // Q4
        $lowerLeft  = [31, 32, 33, 34, 35, 36, 37, 38]; // Q3
        // Primary / deciduous (quadrants 5–8).
        $upperRightPrimary = [55, 54, 53, 52, 51]; // Q5
        $upperLeftPrimary  = [61, 62, 63, 64, 65]; // Q6
        $lowerRightPrimary = [85, 84, 83, 82, 81]; // Q8
        $lowerLeftPrimary  = [71, 72, 73, 74, 75]; // Q7

        // Full FDI set the doctor can add as a row (permanent + primary), ordered
        // by quadrant for the GIGI picker dropdown.
        $allTeeth = array_merge(
            range(11, 18), range(21, 28), range(31, 38), range(41, 48),
            range(51, 55), range(61, 65), range(71, 75), range(81, 85),
        );

        // DIAGNOSA classification → human label. Drives the visual + DMF-T tally.
        $statusLabels = [
            'normal'       => 'Normal',
            'caries'       => 'Karies',
            'missing'      => 'Hilang',
            'filling'      => 'Tambalan',
            'crown'        => 'Crown',
            'root_treated' => 'PSA',
        ];

        // DIAGNOSA dropdown labels annotated with the DMF-T component (D/M/F).
        $diagnosaOptions = [];
        foreach ($statusLabels as $value => $label) {
            $component = \App\Modules\Odontogram\Models\Odontogram::DMF_MAP[$value] ?? null;
            $diagnosaOptions[$value] = $component ? "{$label} ({$component})" : $label;
        }

        // Selected odontogram results = teeth that carry a DIAGNOSA (status). Drives
        // the "appears only after save" generated visual and the read-only table.
        $selectedTeeth = [];
        foreach ($teethArr as $toothNum => $toothEntry) {
            $toothEntry = (array) $toothEntry;
            if (! empty($toothEntry['status'])) {
                $selectedTeeth[(string) $toothNum] = $toothEntry;
            }
        }
        ksort($selectedTeeth, SORT_NATURAL);

        // Daengtisia DMF-T tally — computed from saved data only (source of truth).
        $dmft = $odontogram->dmftCounts();

        // Branch-aware document title (falls back to the Telkomas pilot branch).
        $branchTitle = strtoupper($clinicVisit->branch?->name ?: 'Telkomas');

        // Tailwind classes for the generated (read-only) FDI visual cells.
        $visualClass = function (string $st): string {
            return match ($st) {
                'caries'       => 'bg-red-200 text-red-900 ring-red-400',
                'missing'      => 'bg-gray-800 text-white ring-gray-600',
                'filling'      => 'bg-blue-200 text-blue-900 ring-blue-400',
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
            'allTeeth' => $allTeeth,
        ];
    @endphp

    <div
        class="space-y-6"
        x-data="odontogramEditor(@js($odontogramEditorConfig))"
    >

        {{-- Flash messages render as global toasts (see <x-flash-toasts />) --}}

        {{-- Finalized notice --}}
        @if ($isFinalized)
            <x-ui.alert variant="info">
                Odontogram sudah final namun masih dapat direvisi oleh dokter.
                @if ($odontogram->finalized_at)
                    Difinalisasi pada {{ $odontogram->finalized_at->format('d/m/Y H:i') }}
                    @if ($odontogram->finalizer)
                        oleh {{ $odontogram->finalizer->name }}.
                    @else
                        .
                    @endif
                @endif
            </x-ui.alert>
        @endif

        {{-- Header --}}
        <x-ui.page-header
            title="Odontogram"
            :subtitle="$clinicVisit->visit_number.' — '.($clinicVisit->visit_date?->format('d/m/Y') ?? '-')"
        >
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                @if ($isFinalized)
                    <x-ui.badge tone="info">Final</x-ui.badge>
                @else
                    <x-ui.badge tone="warning">Draft</x-ui.badge>
                @endif

                {{-- Prev/next visit navigation (same patient, Odontogram page) — Sprint 59 --}}
                @include('rme.visits.partials.visit-nav-arrows', [
                    'prev' => $adjacentVisits['previous'] ?? null,
                    'next' => $adjacentVisits['next'] ?? null,
                    'routeName' => 'rme.visits.odontogram.show',
                ])

                <x-ui.button variant="secondary" :href="route('rme.visits.show', $clinicVisit)">
                    &larr; Kembali ke Kunjungan
                </x-ui.button>

                {{-- FIX-01 — an unsaved chart has no route key and nothing to
                     print, so the button only exists once a chart is saved. --}}
                @if ($odontogramExists)
                    @can('print', $odontogram)
                        <x-ui.button variant="secondary" :href="route('rme.odontograms.print', $odontogram)" target="_blank">
                            Cetak Odontogram
                        </x-ui.button>
                    @endcan
                @endif

                @if ($canFinalize)
                    <form method="POST" action="{{ route('rme.odontograms.finalize', $odontogram) }}"
                          onsubmit="return confirm('Finalisasi odontogram ini? Data masih dapat direvisi setelah final.')">
                        @csrf
                        <x-ui.button type="submit" variant="primary">Finalisasi Odontogram</x-ui.button>
                    </form>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        {{-- CORRECTIVE-03 — why this chart is read-only right now. The page stays
             open: the doctor needs to SEE the chart to decide the treatment the
             patient is being asked to consent to. --}}
        @if (($odontogramConsentRequired ?? false) && (auth()->user()?->can('update', $odontogram) ?? false))
            <x-ui.alert variant="warning" title="Persetujuan Tindakan Medis belum ditandatangani">
                <p class="text-sm">
                    Odontogram dapat dilihat, tetapi belum dapat diubah.
                    Pasien harus menandatangani form consent sebelum dokter
                    dapat mencatat atau mengubah odontogram.
                </p>
                <div class="mt-3">
                    <x-ui.button variant="secondary" :href="route('rme.visits.show', $clinicVisit)">
                        Buka Detail Kunjungan
                    </x-ui.button>
                </div>
            </x-ui.alert>
        @elseif (! ($odontogramAuthoringAllowed ?? false) && (auth()->user()?->can('update', $odontogram) ?? false))
            {{-- Not the live encounter: this chart is clinical evidence of a past
                 visit, and consent signed today does not reopen it. --}}
            <x-ui.alert variant="info" title="Odontogram riwayat — hanya-baca">
                <p class="text-sm">
                    Odontogram ini milik kunjungan yang tidak sedang berjalan, sehingga
                    bersifat hanya-baca. Catat temuan pada odontogram kunjungan yang
                    sedang diperiksa.
                </p>
            </x-ui.alert>
        @endif

        @include('rme.visits.partials.visit-workflow-nav', [
            'clinicVisit' => $clinicVisit,
            'active' => 'odontogram',
        ])

        {{-- Daengtisia document identity (matches the official odontogram form) --}}
        <x-ui.card>
            <div class="text-center">
                <p class="text-sm font-bold uppercase tracking-wide text-gray-900">Klinik Gigi Daengtisia</p>
                <p class="mt-1 text-base font-semibold text-gray-800">ODONTOGRAM GIGI PASIEN {{ $branchTitle }}</p>
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Nama</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->patient?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">No. RM</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->patient?->medical_record_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dokter</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->doctor?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Tanggal Kunjungan</dt>
                    <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->visit_date?->format('d/m/Y') ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        @if (! empty($parentOdontogram))
            <x-ui.card title="Odontogram Kunjungan Sebelumnya">
                <p class="text-sm text-gray-600 mb-3">
                    Referensi dari kunjungan
                    <a href="{{ route('rme.visits.show', $clinicVisit->followUpOf) }}" class="font-mono text-brand-700 hover:text-brand-800">{{ $clinicVisit->followUpOf?->visit_number }}</a>.
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
        {{-- INPUT — table only (GIGI / DIAGNOSA / PERAWATAN / DOKTER).     --}}
        {{-- The doctor adds rows per tooth number; the FDI visual below is   --}}
        {{-- a generated, read-only output and is never drawn on directly.    --}}
        {{-- ============================================================ --}}
        @if ($canUpdate)
            {{-- FIX-01 — the save target depends on whether a chart exists yet.
                 A saved chart is revised through the model-bound update route;
                 the FIRST save goes to the visit-scoped store route, which
                 creates the row only after consent passes. Both are PATCH, both
                 sit behind manage_clinic_visits + the room gate, and both carry
                 the identical Alpine payload below. --}}
            <form method="POST"
                  action="{{ $odontogramExists
                      ? route('rme.odontograms.update', $odontogram)
                      : route('rme.visits.odontogram.store', $clinicVisit) }}"
                  class="space-y-6">
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
                        Input odontogram berbasis tabel sesuai format Daengtisia. Tambahkan baris per nomor
                        <span class="font-medium text-gray-700">Gigi</span>, pilih <span class="font-medium text-gray-700">Diagnosa</span>,
                        lalu isi <span class="font-medium text-gray-700">Perawatan</span> dan <span class="font-medium text-gray-700">Dokter</span>.
                        Visual <span class="font-medium text-gray-700">Peta Gigi (FDI)</span> dan perhitungan
                        <span class="font-medium text-gray-700">Jumlah D/M/F/DMF-T</span> dihasilkan otomatis dari tabel ini setelah disimpan —
                        dokter tidak menggambar pada gambar.
                    </p>

                    {{-- Add-row picker (server-rendered options keep every FDI number in the page) --}}
                    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-200">
                        <div>
                            <label class="block text-[11px] font-medium uppercase tracking-wide text-gray-500">Gigi</label>
                            <select x-model="newTooth"
                                    class="mt-1 block w-28 rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm">
                                <option value="">— Pilih gigi —</option>
                                @foreach ($allTeeth as $tooth)
                                    <option value="{{ $tooth }}">{{ $tooth }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium uppercase tracking-wide text-gray-500">Diagnosa</label>
                            <select x-model="newStatus"
                                    class="mt-1 block w-40 rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm">
                                @foreach ($diagnosaOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="button" @click="addRow()"
                                class="inline-flex items-center rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">
                            + Tambah Baris
                        </button>
                    </div>

                    <div class="overflow-x-auto rounded-lg ring-1 ring-gray-200">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr class="text-left text-[11px] font-medium uppercase tracking-wide text-gray-500">
                                    <th class="px-3 py-2 w-16">GIGI</th>
                                    <th class="px-3 py-2 w-56">DIAGNOSA</th>
                                    <th class="px-3 py-2">PERAWATAN</th>
                                    <th class="px-3 py-2 w-44">DOKTER</th>
                                    <th class="px-3 py-2 w-12"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <template x-for="row in selectedRows" :key="row.tooth">
                                    <tr class="align-top">
                                        <td class="px-3 py-2 font-semibold text-gray-900" x-text="row.tooth"></td>
                                        <td class="px-3 py-2 space-y-1">
                                            <select
                                                :value="row.status"
                                                @change="setStatus(row.tooth, $event.target.value)"
                                                class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm">
                                                @foreach ($diagnosaOptions as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <input
                                                type="text"
                                                maxlength="1000"
                                                placeholder="Detail diagnosa (opsional)…"
                                                :value="row.additional_condition"
                                                @input="setAdditional(row.tooth, 'additional_condition', $event.target.value)"
                                                class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm">
                                        </td>
                                        <td class="px-3 py-2">
                                            <textarea
                                                rows="2"
                                                maxlength="1000"
                                                placeholder="Perawatan…"
                                                :value="row.additional_note"
                                                @input="setAdditional(row.tooth, 'additional_note', $event.target.value)"
                                                class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm"></textarea>
                                        </td>
                                        <td class="px-3 py-2">
                                            <input
                                                type="text"
                                                maxlength="255"
                                                placeholder="Dokter…"
                                                :value="row.dokter"
                                                @input="setAdditional(row.tooth, 'dokter', $event.target.value)"
                                                class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 text-sm">
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <button type="button" @click="removeRow(row.tooth)"
                                                    class="text-rose-600 hover:text-rose-800 text-sm font-medium">Hapus</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <p x-show="selectedRows.length === 0" class="mt-3 text-sm text-gray-500 italic">
                        Belum ada kondisi odontogram yang dipilih.
                    </p>
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
                                    <th class="px-3 py-2 w-16">GIGI</th>
                                    <th class="px-3 py-2 w-48">DIAGNOSA</th>
                                    <th class="px-3 py-2">PERAWATAN</th>
                                    <th class="px-3 py-2 w-44">DOKTER</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 text-gray-700">
                                @foreach ($selectedTeeth as $toothNum => $toothEntry)
                                    <tr class="align-top">
                                        <td class="px-3 py-2 font-semibold text-gray-900">{{ $toothNum }}</td>
                                        <td class="px-3 py-2">
                                            {{ $statusLabels[$toothEntry['status'] ?? ''] ?? ucfirst($toothEntry['status'] ?? '—') }}
                                            @if (($toothEntry['additional_condition'] ?? '') !== '')
                                                <span class="block text-xs text-gray-500">{{ $toothEntry['additional_condition'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 whitespace-pre-wrap">{{ ($toothEntry['additional_note'] ?? '') !== '' ? $toothEntry['additional_note'] : '—' }}</td>
                                        <td class="px-3 py-2">{{ ($toothEntry['dokter'] ?? '') !== '' ? $toothEntry['dokter'] : '—' }}</td>
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
        {{-- OUTPUT — generated FDI visual + DMF-T tally. Read-only, derived  --}}
        {{-- from saved table data, shown only after a save produced teeth.   --}}
        {{-- ============================================================ --}}
        <x-ui.card title="Peta Gigi (FDI) & Jumlah DMF-T">
            @if (count($selectedTeeth) > 0)
                <p class="mb-3 text-xs text-gray-500">
                    Visual ini dihasilkan otomatis dari tabel yang tersimpan dan tidak dapat diedit langsung.
                    Ubah tabel di atas lalu simpan untuk memperbarui visual.
                </p>

                {{-- Jumlah D / M / F / DMF-T --}}
                <div class="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div class="rounded-lg bg-red-50 px-3 py-2 ring-1 ring-red-200">
                        <div class="text-[11px] font-medium uppercase tracking-wide text-red-700">Jumlah D</div>
                        <div class="text-lg font-bold text-red-900">{{ $dmft['D'] }}</div>
                    </div>
                    <div class="rounded-lg bg-gray-100 px-3 py-2 ring-1 ring-gray-300">
                        <div class="text-[11px] font-medium uppercase tracking-wide text-gray-700">Jumlah M</div>
                        <div class="text-lg font-bold text-gray-900">{{ $dmft['M'] }}</div>
                    </div>
                    <div class="rounded-lg bg-blue-50 px-3 py-2 ring-1 ring-blue-200">
                        <div class="text-[11px] font-medium uppercase tracking-wide text-blue-700">Jumlah F</div>
                        <div class="text-lg font-bold text-blue-900">{{ $dmft['F'] }}</div>
                    </div>
                    <div class="rounded-lg bg-brand-50 px-3 py-2 ring-1 ring-brand-200">
                        <div class="text-[11px] font-medium uppercase tracking-wide text-brand-700">Jumlah DMF-T</div>
                        <div class="text-lg font-bold text-brand-800">{{ $dmft['DMFT'] }}</div>
                    </div>
                </div>

                {{-- Legend (D = Decay, M = Missing, F = Filling) --}}
                <div class="mb-4 flex flex-wrap gap-x-4 gap-y-2 text-xs text-gray-700">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded ring-1 ring-inset ring-red-400 bg-red-200 inline-block flex-shrink-0"></span>
                        D = Decay (Karies)
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded ring-1 ring-inset ring-gray-600 bg-gray-800 inline-block flex-shrink-0"></span>
                        M = Missing (Hilang)
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="w-4 h-4 rounded ring-1 ring-inset ring-blue-400 bg-blue-200 inline-block flex-shrink-0"></span>
                        F = Filling (Tambalan)
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

                {{-- Generated tooth grid (read-only output): permanent + primary --}}
                <div class="overflow-x-auto -mx-2 px-2">
                    <div class="inline-flex flex-col items-center min-w-max gap-1">
                        <div class="flex w-full text-[10px] text-gray-400 select-none">
                            <div class="flex-1 text-right pr-3">KANAN / RIGHT</div>
                            <div class="w-3"></div>
                            <div class="flex-1 text-left pl-3">KIRI / LEFT</div>
                        </div>

                        {{-- Upper permanent --}}
                        <div class="flex items-stretch justify-center gap-0.5">
                            @foreach (array_merge($upperRight, [null], $upperLeft) as $tooth)
                                @if ($tooth === null)
                                    <div class="w-px bg-gray-400 mx-1 self-stretch flex-shrink-0"></div>
                                @else
                                    @php $st = $selectedTeeth[(string) $tooth]['status'] ?? ''; @endphp
                                    <div class="w-8 h-8 rounded ring-1 ring-inset text-[10px] font-bold flex items-center justify-center select-none {{ $visualClass($st) }}" title="Gigi {{ $tooth }}">{{ $tooth }}</div>
                                @endif
                            @endforeach
                        </div>

                        {{-- Upper primary --}}
                        <div class="flex items-stretch justify-center gap-0.5">
                            @foreach (array_merge($upperRightPrimary, [null], $upperLeftPrimary) as $tooth)
                                @if ($tooth === null)
                                    <div class="w-px bg-gray-400 mx-1 self-stretch flex-shrink-0"></div>
                                @else
                                    @php $st = $selectedTeeth[(string) $tooth]['status'] ?? ''; @endphp
                                    <div class="w-8 h-8 rounded ring-1 ring-inset text-[10px] font-bold flex items-center justify-center select-none {{ $visualClass($st) }}" title="Gigi {{ $tooth }}">{{ $tooth }}</div>
                                @endif
                            @endforeach
                        </div>

                        <div class="w-full border-t-2 border-dashed border-gray-300 my-1"></div>

                        {{-- Lower primary --}}
                        <div class="flex items-stretch justify-center gap-0.5">
                            @foreach (array_merge($lowerRightPrimary, [null], $lowerLeftPrimary) as $tooth)
                                @if ($tooth === null)
                                    <div class="w-px bg-gray-400 mx-1 self-stretch flex-shrink-0"></div>
                                @else
                                    @php $st = $selectedTeeth[(string) $tooth]['status'] ?? ''; @endphp
                                    <div class="w-8 h-8 rounded ring-1 ring-inset text-[10px] font-bold flex items-center justify-center select-none {{ $visualClass($st) }}" title="Gigi {{ $tooth }}">{{ $tooth }}</div>
                                @endif
                            @endforeach
                        </div>

                        {{-- Lower permanent --}}
                        <div class="flex items-stretch justify-center gap-0.5">
                            @foreach (array_merge($lowerRight, [null], $lowerLeft) as $tooth)
                                @if ($tooth === null)
                                    <div class="w-px bg-gray-400 mx-1 self-stretch flex-shrink-0"></div>
                                @else
                                    @php $st = $selectedTeeth[(string) $tooth]['status'] ?? ''; @endphp
                                    <div class="w-8 h-8 rounded ring-1 ring-inset text-[10px] font-bold flex items-center justify-center select-none {{ $visualClass($st) }}" title="Gigi {{ $tooth }}">{{ $tooth }}</div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            @else
                <p class="text-sm text-gray-500 italic">
                    Belum ada kondisi odontogram yang dipilih. Visual Peta Gigi dan Jumlah DMF-T akan muncul
                    otomatis setelah tabel odontogram disimpan.
                </p>
            @endif
        </x-ui.card>

        {{-- ============================================================ --}}
        {{-- OUTPUT — read-only saved-result table (Sprint 63.1.1). Sits     --}}
        {{-- directly under the FDI visual so the lower preview area shows    --}}
        {{-- both the Peta Gigi diagram AND the saved GIGI / DIAGNOSA /       --}}
        {{-- PERAWATAN / DOKTER table after a save. Rows are built by the     --}}
        {{-- Sprint 63.1 OdontogramPrintFormatter (same logic as print/PDF).  --}}
        {{-- Read-only, derived from saved data only — no input, no mutation. --}}
        {{-- ============================================================ --}}
        <x-ui.card title="Hasil Odontogram Tersimpan">
            @if (! empty($structured['table_rows']))
                <p class="mb-3 text-xs text-gray-500">
                    Ringkasan tabel ini dihasilkan otomatis dari odontogram yang tersimpan dan identik dengan
                    tampilan cetak/PDF. Ubah tabel input di atas lalu simpan untuk memperbaruinya.
                </p>
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
                            @foreach ($structured['table_rows'] as $row)
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
                    Belum ada kondisi odontogram yang dipilih. Tabel hasil tersimpan akan muncul otomatis
                    setelah tabel odontogram disimpan.
                </p>
            @endif
        </x-ui.card>

    </div>

    {{-- ============================================================ --}}
    {{-- FIX-RME-EXAM-CONSENT-ODONTOGRAM-HISTORY-3 / FIX-04            --}}
    {{-- Riwayat Odontogram Pasien — read-only, previous visits only.  --}}
    {{-- Deliberately placed AFTER the closing </div> of the           --}}
    {{-- x-data="odontogramEditor(...)" wrapper: the history card has  --}}
    {{-- its own per-row Alpine state and must never share scope with  --}}
    {{-- the active editor, so a stray binding can never reach the     --}}
    {{-- chart being edited. The active editor above is UNCHANGED.     --}}
    {{-- ============================================================ --}}
    @include('rme.visits.odontogram.partials.patient-odontogram-history')

</x-settings-shell>
