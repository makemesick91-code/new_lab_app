<x-settings-shell title="Odontogram">
    @php
        $payload   = $odontogram->tooth_map_payload ?? [];
        $teethData = ! empty($payload['teeth']) ? (object) $payload['teeth'] : new \stdClass();
        $isFinalized = $odontogram->isFinalized();
        $canUpdate = (! $isFinalized) && (auth()->user()?->can('update', $odontogram) ?? false);
        $canFinalize = (! $isFinalized) && (auth()->user()?->can('finalize', $odontogram) ?? false);

        // FDI display order: center → outer
        $upperRight = [18, 17, 16, 15, 14, 13, 12, 11]; // Q1 right-to-center
        $upperLeft  = [21, 22, 23, 24, 25, 26, 27, 28]; // Q2 center-to-left
        $lowerRight = [48, 47, 46, 45, 44, 43, 42, 41]; // Q4 right-to-center
        $lowerLeft  = [31, 32, 33, 34, 35, 36, 37, 38]; // Q3 center-to-left

        $odontogramEditorConfig = [
            'canEdit' => $canUpdate,
            'teeth' => (array) $teethData,
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
                Odontogram sudah final dan tidak bisa diedit.
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
                          onsubmit="return confirm('Finalisasi odontogram ini? Data tidak akan bisa diedit setelah difinalisasi.')">
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

        {{-- Legend --}}
        <x-ui.card padding="p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Keterangan Status</p>
            <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs text-gray-700">
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-4 h-4 rounded ring-1 ring-inset ring-gray-200 bg-white inline-block flex-shrink-0"></span>
                    Normal (default)
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-4 h-4 rounded ring-1 ring-inset ring-green-400 bg-green-100 inline-block flex-shrink-0"></span>
                    Normal (ditandai)
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
                    PSA (Perawatan Saluran Akar)
                </span>
            </div>
        </x-ui.card>

        {{-- Tooth Map --}}
        <x-ui.card>
            <div class="flex items-center justify-between mb-4">
                <h4 class="text-base font-semibold text-gray-900">Peta Gigi (FDI)</h4>
                @if ($isFinalized)
                    <span class="text-xs italic text-gray-400">Read-only (sudah final)</span>
                @elseif (! $canUpdate)
                    <span class="text-xs italic text-gray-400">Hanya lihat</span>
                @endif
            </div>

            {{-- Status selector — manager only, draft only --}}
            @if ($canUpdate)
                <div class="mb-5">
                    <p class="text-xs text-gray-500 mb-2">Status aktif — klik gigi untuk menerapkan (klik ulang untuk hapus):</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" @click="activeStatus = 'normal'"
                            :class="activeStatus === 'normal' ? 'ring-2 ring-offset-1 ring-green-600' : ''"
                            class="px-3 py-1.5 text-xs font-medium rounded-md bg-green-100 text-green-900 ring-1 ring-inset ring-green-400 hover:bg-green-200 transition-all">
                            Normal
                        </button>
                        <button type="button" @click="activeStatus = 'caries'"
                            :class="activeStatus === 'caries' ? 'ring-2 ring-offset-1 ring-red-600' : ''"
                            class="px-3 py-1.5 text-xs font-medium rounded-md bg-red-200 text-red-900 ring-1 ring-inset ring-red-400 hover:bg-red-300 transition-all">
                            Karies
                        </button>
                        <button type="button" @click="activeStatus = 'missing'"
                            :class="activeStatus === 'missing' ? 'ring-2 ring-offset-1 ring-gray-700' : ''"
                            class="px-3 py-1.5 text-xs font-medium rounded-md bg-gray-800 text-white ring-1 ring-inset ring-gray-600 hover:bg-gray-700 transition-all">
                            Hilang
                        </button>
                        <button type="button" @click="activeStatus = 'crown'"
                            :class="activeStatus === 'crown' ? 'ring-2 ring-offset-1 ring-amber-600' : ''"
                            class="px-3 py-1.5 text-xs font-medium rounded-md bg-amber-200 text-amber-900 ring-1 ring-inset ring-amber-400 hover:bg-amber-300 transition-all">
                            Crown
                        </button>
                        <button type="button" @click="activeStatus = 'root_treated'"
                            :class="activeStatus === 'root_treated' ? 'ring-2 ring-offset-1 ring-sky-600' : ''"
                            class="px-3 py-1.5 text-xs font-medium rounded-md bg-sky-200 text-sky-900 ring-1 ring-inset ring-sky-400 hover:bg-sky-300 transition-all">
                            PSA
                        </button>
                    </div>
                </div>
            @endif

            {{-- Tooth grid --}}
            <div class="overflow-x-auto -mx-2 px-2">
                <div class="inline-flex flex-col items-center min-w-max">

                    {{-- Upper jaw labels --}}
                    <div class="flex w-full mb-0.5 text-[10px] text-gray-400 select-none">
                        <div class="flex-1 text-right pr-3">← Kanan atas (Q1)</div>
                        <div class="w-3"></div>
                        <div class="flex-1 text-left pl-3">Kiri atas (Q2) →</div>
                    </div>

                    {{-- Upper jaw row --}}
                    <div class="flex items-stretch gap-0.5">
                        @foreach ($upperRight as $tooth)
                            <button
                                type="button"
                                @click="clickTooth({{ $tooth }})"
                                :class="cellClass({{ $tooth }})"
                                class="w-9 h-9 rounded ring-1 ring-inset text-[10px] font-bold transition-all select-none flex items-center justify-center"
                                title="Gigi {{ $tooth }}">
                                {{ $tooth }}
                            </button>
                        @endforeach
                        <div class="w-px bg-gray-400 mx-1 self-stretch flex-shrink-0"></div>
                        @foreach ($upperLeft as $tooth)
                            <button
                                type="button"
                                @click="clickTooth({{ $tooth }})"
                                :class="cellClass({{ $tooth }})"
                                class="w-9 h-9 rounded ring-1 ring-inset text-[10px] font-bold transition-all select-none flex items-center justify-center"
                                title="Gigi {{ $tooth }}">
                                {{ $tooth }}
                            </button>
                        @endforeach
                    </div>

                    {{-- Jaw divider --}}
                    <div class="w-full border-t-2 border-dashed border-gray-300 my-2"></div>

                    {{-- Lower jaw row --}}
                    <div class="flex items-stretch gap-0.5">
                        @foreach ($lowerRight as $tooth)
                            <button
                                type="button"
                                @click="clickTooth({{ $tooth }})"
                                :class="cellClass({{ $tooth }})"
                                class="w-9 h-9 rounded ring-1 ring-inset text-[10px] font-bold transition-all select-none flex items-center justify-center"
                                title="Gigi {{ $tooth }}">
                                {{ $tooth }}
                            </button>
                        @endforeach
                        <div class="w-px bg-gray-400 mx-1 self-stretch flex-shrink-0"></div>
                        @foreach ($lowerLeft as $tooth)
                            <button
                                type="button"
                                @click="clickTooth({{ $tooth }})"
                                :class="cellClass({{ $tooth }})"
                                class="w-9 h-9 rounded ring-1 ring-inset text-[10px] font-bold transition-all select-none flex items-center justify-center"
                                title="Gigi {{ $tooth }}">
                                {{ $tooth }}
                            </button>
                        @endforeach
                    </div>

                    {{-- Lower jaw labels --}}
                    <div class="flex w-full mt-0.5 text-[10px] text-gray-400 select-none">
                        <div class="flex-1 text-right pr-3">← Kanan bawah (Q4)</div>
                        <div class="w-3"></div>
                        <div class="flex-1 text-left pl-3">Kiri bawah (Q3) →</div>
                    </div>

                </div>
            </div>

            {{-- Per-tooth note panel — appears when a tooth is selected --}}
            <div x-show="selectedTooth !== null" class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-sm font-semibold text-gray-800">
                        Gigi <span x-text="selectedTooth"></span>
                        <span
                            x-show="teeth[String(selectedTooth)] && teeth[String(selectedTooth)].status"
                            class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                            :class="{
                                'bg-red-100 text-red-700 ring-red-300':   teeth[String(selectedTooth)] && teeth[String(selectedTooth)].status === 'caries',
                                'bg-gray-700 text-white ring-gray-500':   teeth[String(selectedTooth)] && teeth[String(selectedTooth)].status === 'missing',
                                'bg-amber-100 text-amber-700 ring-amber-300': teeth[String(selectedTooth)] && teeth[String(selectedTooth)].status === 'crown',
                                'bg-sky-100 text-sky-700 ring-sky-300':   teeth[String(selectedTooth)] && teeth[String(selectedTooth)].status === 'root_treated',
                                'bg-green-100 text-green-700 ring-green-300': teeth[String(selectedTooth)] && teeth[String(selectedTooth)].status === 'normal',
                            }"
                            x-text="teeth[String(selectedTooth)] ? teeth[String(selectedTooth)].status : ''">
                        </span>
                    </p>
                    <button type="button" @click="selectedTooth = null"
                            class="text-xs text-gray-400 hover:text-gray-700 focus:outline-none">&#10005; Tutup</button>
                </div>

                {{-- Tooth has a status: show conditions + note area --}}
                <div x-show="teeth[String(selectedTooth)]">
                    {{-- Conditions --}}
                    <div class="mb-3">
                        <p class="text-xs font-medium text-gray-600 mb-2">Kondisi Tambahan:</p>
                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                            @php
                                $conditionLabels = [
                                    'caries'       => 'Karies',
                                    'missing'      => 'Hilang',
                                    'crown'        => 'Crown',
                                    'root_treated' => 'PSA',
                                    'mobility'     => 'Mobility',
                                    'impaction'    => 'Impaksi',
                                    'filling'      => 'Tambalan',
                                ];
                            @endphp
                            @foreach ($conditionLabels as $condValue => $condLabel)
                                @if ($canUpdate)
                                    <label class="inline-flex items-center gap-1.5 cursor-pointer select-none">
                                        <input
                                            type="checkbox"
                                            :checked="hasCondition('{{ $condValue }}')"
                                            @change="toggleCondition('{{ $condValue }}')"
                                            class="rounded border-gray-300 text-teal-600 shadow-sm focus:ring-teal-500 focus:ring-offset-0">
                                        <span class="text-xs text-gray-700">{{ $condLabel }}</span>
                                    </label>
                                @else
                                    <span
                                        x-show="hasCondition('{{ $condValue }}')"
                                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-teal-50 text-teal-700 ring-1 ring-inset ring-teal-200">
                                        {{ $condLabel }}
                                    </span>
                                @endif
                            @endforeach
                            @if (! $canUpdate)
                                <span
                                    x-show="! teeth[String(selectedTooth)] || ! Array.isArray(teeth[String(selectedTooth)].conditions) || teeth[String(selectedTooth)].conditions.length === 0"
                                    class="text-xs text-gray-400 italic">
                                    Tidak ada kondisi tambahan.
                                </span>
                            @endif
                        </div>
                    </div>

                    @if ($canUpdate)
                        <label class="block text-xs font-medium text-gray-600 mb-1">
                            Catatan Gigi <span class="text-gray-400 font-normal">(maks. 1000 karakter)</span>
                        </label>
                        <textarea
                            x-model="toothNote"
                            @input="syncNote()"
                            rows="3"
                            maxlength="1000"
                            placeholder="Catatan untuk gigi ini…"
                            class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 text-sm">
                        </textarea>
                    @else
                        <label class="block text-xs font-medium text-gray-600 mb-1">Catatan Gigi</label>
                        <p class="text-sm text-gray-700 whitespace-pre-wrap min-h-[2rem]"
                           x-text="(teeth[String(selectedTooth)] && teeth[String(selectedTooth)].note) ? teeth[String(selectedTooth)].note : '—'">
                        </p>
                    @endif
                </div>

                {{-- Tooth has no status yet --}}
                <div x-show="!teeth[String(selectedTooth)]">
                    <p class="text-xs text-gray-400 italic">
                        @if ($canUpdate)
                            Pilih status di atas lalu klik gigi untuk memberi tanda, kemudian tambahkan catatan.
                        @else
                            Gigi ini belum ditandai.
                        @endif
                    </p>
                </div>
            </div>
        </x-ui.card>

        {{-- Save form (manager + draft only) --}}
        @if ($canUpdate)
            <x-ui.card title="Simpan Odontogram">
                <form method="POST" action="{{ route('rme.odontograms.update', $odontogram) }}">
                    @csrf
                    @method('PATCH')

                    {{-- Hidden reactive payload — Alpine.js keeps this current --}}
                    <input type="hidden" name="tooth_map_payload" :value="getPayload()" />

                    @error('tooth_map_payload.teeth')
                        <div class="mb-3 rounded-lg bg-rose-50 border border-rose-200 p-3 text-sm text-rose-700">
                            {{ $message }}
                        </div>
                    @enderror

                    <div class="mb-4">
                        <label for="summary_notes" class="block text-sm font-medium text-gray-700">
                            Catatan Ringkasan
                        </label>
                        <textarea
                            id="summary_notes"
                            name="summary_notes"
                            rows="4"
                            maxlength="5000"
                            placeholder="Catatan kondisi gigi…"
                            class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm @error('summary_notes') border-rose-300 @enderror">{{ old('summary_notes', $odontogram->summary_notes) }}</textarea>
                        @error('summary_notes')
                            <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end">
                        <x-ui.button type="submit" variant="primary">Simpan Odontogram</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @else
            {{-- Read-only notes --}}
            @if ($odontogram->summary_notes)
                <x-ui.card title="Catatan Ringkasan">
                    <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $odontogram->summary_notes }}</p>
                </x-ui.card>
            @endif
        @endif

    </div>
</x-settings-shell>
