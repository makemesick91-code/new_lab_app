{{-- SATUSEHAT-4A/4B — structured diagnosis card. Handwriting RM stays the
     primary clinical input; diagnoses are recorded explicitly (never
     auto-coded from free text). Legacy records without diagnoses stay fully
     readable. SATUSEHAT-4B adds the branch-scoped rollout banner, explicit
     primary swap, terminology lifecycle visibility, and the reasoned
     emergency override (pilot_enforced branches only). --}}
@php
    $recordedDiagnoses = $medicalRecord->diagnoses()->with('clinicalDiagnosis:id,code_system,code,display,status')->get();
    $dxEnforcement = $diagnosisEnforcement ?? null;
    $dxMode = $dxEnforcement['mode'] ?? 'informational';
@endphp
<x-ui.card title="Diagnosis Terstruktur (SATUSEHAT)"
    description="Opsional untuk alur klinik — dibutuhkan untuk kesiapan SATUSEHAT. Tulisan tangan tetap menjadi input klinis utama.">

    @if ($dxEnforcement !== null && ! $dxEnforcement['has_active_primary'])
        @if ($dxMode === 'pilot_enforced')
            <x-ui.alert variant="danger">
                <strong>Cabang pilot:</strong> minimal satu <strong>diagnosis utama terstruktur</strong> wajib dicatat
                sebelum RME dapat difinalkan.
                @if ($dxEnforcement['override_active'])
                    Override darurat aktif — finalisasi diizinkan; isu diagnosis tetap terbuka untuk review klinis.
                @endif
            </x-ui.alert>
        @elseif ($dxMode === 'warning')
            <x-ui.alert variant="warning">
                Rekam medis ini belum memiliki diagnosis utama terstruktur. Finalisasi tetap diizinkan,
                namun kelengkapan diagnosis dipantau pada dasbor adopsi &amp; kualitas data SATUSEHAT.
            </x-ui.alert>
        @endif
    @endif

    @if ($recordedDiagnoses->isEmpty())
        <x-ui.alert variant="info">
            Belum ada diagnosis terstruktur pada rekam medis ini. RM lama tetap sah — status kesiapan SATUSEHAT
            menandainya sebagai <strong>MISSING_STRUCTURED_DIAGNOSIS</strong> tanpa memblokir alur klinik.
        </x-ui.alert>
    @else
        <div class="overflow-x-auto">
            <x-ui.table>
                <thead>
                    <tr>
                        <th class="px-3 py-2 text-left">Kode</th>
                        <th class="px-3 py-2 text-left">Diagnosis</th>
                        <th class="px-3 py-2 text-left">Peran</th>
                        <th class="px-3 py-2 text-left">Terminologi</th>
                        <th class="px-3 py-2 text-left">Dicatat</th>
                        @if ($canUpdate ?? false)
                            <th class="px-3 py-2 text-left">Aksi</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recordedDiagnoses as $dx)
                        <tr class="border-t border-hairline">
                            <td class="px-3 py-2 font-mono text-sm">{{ $dx->clinicalDiagnosis?->code }}</td>
                            <td class="px-3 py-2">{{ $dx->clinicalDiagnosis?->display }}</td>
                            <td class="px-3 py-2">
                                <x-ui.badge :tone="$dx->diagnosis_role === 'primary' ? 'success' : 'info'">
                                    {{ $dx->diagnosis_role === 'primary' ? 'Utama' : 'Sekunder' }}
                                </x-ui.badge>
                            </td>
                            <td class="px-3 py-2">
                                @if ($dx->clinicalDiagnosis?->status === 'active')
                                    <x-ui.badge tone="success">Aktif</x-ui.badge>
                                @else
                                    <x-ui.badge tone="warning">{{ $dx->clinicalDiagnosis?->status ?? 'tidak dikenal' }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-ink-muted">{{ $dx->diagnosed_at?->format('d M Y H:i') }}</td>
                            @if ($canUpdate ?? false)
                                <td class="px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        @if ($dx->diagnosis_role !== 'primary')
                                            <form method="POST"
                                                  action="{{ route('rme.visits.medical-record.diagnoses.make-primary', [$clinicVisit, $medicalRecord, $dx]) }}">
                                                @csrf
                                                <x-ui.button size="sm" type="submit" variant="secondary">Jadikan Utama</x-ui.button>
                                            </form>
                                        @endif
                                        <form method="POST"
                                              action="{{ route('rme.visits.medical-record.diagnoses.destroy', [$clinicVisit, $medicalRecord, $dx]) }}"
                                              onsubmit="return confirm('Hapus diagnosis ini dari rekam medis?');">
                                            @csrf
                                            @method('DELETE')
                                            <x-ui.button size="sm" type="submit" variant="danger">Hapus</x-ui.button>
                                        </form>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </div>
    @endif

    @if ($canUpdate ?? false)
        <div class="mt-4 border-t border-hairline pt-4"
             x-data="{
                q: '',
                results: [],
                selected: null,
                async search() {
                    this.selected = null;
                    if (this.q.trim().length < 2) { this.results = []; return; }
                    const res = await fetch(`{{ route('rme.diagnoses.search') }}?q=${encodeURIComponent(this.q)}`, { headers: { 'Accept': 'application/json' } });
                    if (res.ok) { this.results = (await res.json()).data; }
                },
                pick(item) { this.selected = item; this.q = item.label; this.results = []; },
             }">
            <form method="POST" action="{{ route('rme.visits.medical-record.diagnoses.store', [$clinicVisit, $medicalRecord]) }}"
                  class="grid grid-cols-1 gap-3 md:grid-cols-3">
                @csrf
                <div class="relative md:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-ink">Cari diagnosis (kode / nama)</label>
                    <input type="text" x-model="q" @input.debounce.300ms="search()"
                           placeholder="mis. K02 atau karies"
                           class="w-full rounded-lg border border-hairline px-3 py-2 text-sm focus:border-brand-500 focus:ring-brand-100" />
                    <input type="hidden" name="clinical_diagnosis_id" :value="selected ? selected.id : ''" />
                    <div x-show="results.length" x-cloak
                         class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-hairline bg-white shadow-card">
                        <template x-for="item in results" :key="item.id">
                            <button type="button" @click="pick(item)"
                                    class="block w-full px-3 py-2 text-left text-sm hover:bg-brand-50"
                                    x-text="item.label"></button>
                        </template>
                    </div>
                </div>
                <div>
                    <x-ui.select label="Peran diagnosis" name="diagnosis_role">
                        <option value="primary">Utama (primary)</option>
                        <option value="secondary">Sekunder (secondary)</option>
                    </x-ui.select>
                </div>
                <div class="md:col-span-3">
                    <x-ui.button size="sm" type="submit" x-bind:disabled="!selected">Catat Diagnosis</x-ui.button>
                    @error('clinical_diagnosis_id')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    @error('diagnosis_role')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
            </form>

            {{-- SATUSEHAT-4B — reasoned emergency override: shown only on a
                 pilot_enforced branch while the primary requirement is unmet.
                 Server-side: dedicated permission + policy re-check + audit. --}}
            @if (($dxEnforcement['blocking'] ?? false) && auth()->user()?->can('override_diagnosis_requirement'))
                <div class="mt-4 rounded-lg border border-danger/40 bg-danger-50/40 p-3">
                    <p class="mb-2 text-sm font-medium text-ink">Override darurat (wajib beralasan, teraudit)</p>
                    <form method="POST" action="{{ route('rme.visits.medical-record.diagnosis-override', [$clinicVisit, $medicalRecord]) }}"
                          class="flex flex-col gap-2 md:flex-row md:items-end">
                        @csrf
                        <div class="flex-1">
                            <x-ui.input name="reason" label="Alasan klinis override (min. 10 karakter)" required />
                        </div>
                        <x-ui.button size="sm" type="submit" variant="warning">Gunakan Override</x-ui.button>
                    </form>
                    @error('reason')<p class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    <p class="mt-2 text-xs text-ink-muted">
                        Override tidak membuat data SATUSEHAT siap — isu diagnosis tetap terbuka untuk review klinis.
                    </p>
                </div>
            @endif
        </div>
    @endif
</x-ui.card>
