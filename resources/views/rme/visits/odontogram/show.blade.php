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
    @endphp

    <div
        class="space-y-6"
        x-data="{
            activeStatus: 'caries',
            canEdit: @json($canUpdate),
            teeth: @json($teethData),

            clickTooth(num) {
                if (! this.canEdit) return;
                const key = String(num);
                const current = this.teeth[key] ? this.teeth[key].status : null;
                if (current === this.activeStatus) {
                    const copy = Object.assign({}, this.teeth);
                    delete copy[key];
                    this.teeth = copy;
                } else {
                    this.teeth = Object.assign({}, this.teeth, { [key]: { status: this.activeStatus } });
                }
            },

            cellClass(num) {
                const s = this.teeth[String(num)] ? this.teeth[String(num)].status : null;
                const cur = this.canEdit ? 'cursor-pointer hover:opacity-75 active:scale-95 ' : 'cursor-default ';
                if (s === 'caries')       return cur + 'bg-red-200 text-red-900 ring-red-400';
                if (s === 'missing')      return cur + 'bg-gray-800 text-white ring-gray-600';
                if (s === 'crown')        return cur + 'bg-amber-200 text-amber-900 ring-amber-400';
                if (s === 'root_treated') return cur + 'bg-sky-200 text-sky-900 ring-sky-400';
                if (s === 'normal')       return cur + 'bg-green-100 text-green-900 ring-green-400';
                return cur + 'bg-white text-gray-600 ring-gray-200';
            },

            getPayload() {
                return JSON.stringify({ teeth: this.teeth });
            }
        }"
    >

        {{-- Flash --}}
        @if (session('status'))
            <div class="rounded-md bg-green-50 border border-green-200 p-4 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        {{-- Finalized notice --}}
        @if ($isFinalized)
            <div class="rounded-md bg-blue-50 border border-blue-200 p-4 text-sm text-blue-800">
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
        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Odontogram</h3>
                        <p class="text-sm text-gray-500">
                            {{ $clinicVisit->visit_number }} &mdash; {{ $clinicVisit->visit_date?->format('d/m/Y') }}
                        </p>
                    </div>
                    @if ($isFinalized)
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset bg-blue-50 text-blue-700 ring-blue-600/20">
                            Final
                        </span>
                    @else
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset bg-amber-50 text-amber-700 ring-amber-600/20">
                            Draft
                        </span>
                    @endif
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Pasien</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->patient?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Dokter</dt>
                        <dd class="mt-1 text-sm text-gray-900">{{ $clinicVisit->doctor?->name ?? '—' }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex items-center gap-3">
                    <a href="{{ route('rme.visits.show', $clinicVisit) }}"
                       class="inline-flex items-center rounded-md bg-gray-100 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">
                        &larr; Kembali ke Kunjungan
                    </a>

                    @if ($canFinalize)
                        <form method="POST" action="{{ route('rme.odontograms.finalize', $odontogram) }}"
                              onsubmit="return confirm('Finalisasi odontogram ini? Data tidak akan bisa diedit setelah difinalisasi.')">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center rounded-md bg-blue-700 px-3 py-2 text-sm font-medium text-white hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                Finalisasi Odontogram
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- Legend --}}
        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="p-4">
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
            </div>
        </div>

        {{-- Tooth Map --}}
        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="p-6">
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
            </div>
        </div>

        {{-- Save form (manager + draft only) --}}
        @if ($canUpdate)
            <div class="bg-white shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h4 class="text-base font-semibold text-gray-900 mb-4">Simpan Odontogram</h4>

                    <form method="POST" action="{{ route('rme.odontograms.update', $odontogram) }}">
                        @csrf
                        @method('PATCH')

                        {{-- Hidden reactive payload — Alpine.js keeps this current --}}
                        <input type="hidden" name="tooth_map_payload" :value="getPayload()" />

                        @error('tooth_map_payload.teeth')
                            <div class="mb-3 rounded-md bg-red-50 border border-red-200 p-3 text-sm text-red-700">
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
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-teal-500 focus:ring-teal-500 sm:text-sm @error('summary_notes') border-red-300 @enderror">{{ old('summary_notes', $odontogram->summary_notes) }}</textarea>
                            @error('summary_notes')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex justify-end">
                            <button type="submit"
                                    class="inline-flex items-center rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2">
                                Simpan Odontogram
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @else
            {{-- Read-only notes --}}
            @if ($odontogram->summary_notes)
                <div class="bg-white shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h4 class="text-base font-semibold text-gray-900 mb-2">Catatan Ringkasan</h4>
                        <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $odontogram->summary_notes }}</p>
                    </div>
                </div>
            @endif
        @endif

    </div>
</x-settings-shell>
