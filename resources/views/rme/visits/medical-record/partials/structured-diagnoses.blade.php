{{-- SATUSEHAT-4A — structured diagnosis card. Handwriting RM stays the primary
     clinical input; diagnoses are recorded explicitly (never auto-coded from
     free text). Legacy records without diagnoses stay fully readable. --}}
@php
    $recordedDiagnoses = $medicalRecord->diagnoses()->with('clinicalDiagnosis:id,code_system,code,display')->get();
@endphp
<x-ui.card title="Diagnosis Terstruktur (SATUSEHAT)"
    description="Opsional untuk alur klinik — dibutuhkan untuk kesiapan SATUSEHAT. Tulisan tangan tetap menjadi input klinis utama.">

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
                            <td class="px-3 py-2 text-xs text-ink-muted">{{ $dx->diagnosed_at?->format('d M Y H:i') }}</td>
                            @if ($canUpdate ?? false)
                                <td class="px-3 py-2">
                                    <form method="POST"
                                          action="{{ route('rme.visits.medical-record.diagnoses.destroy', [$clinicVisit, $medicalRecord, $dx]) }}"
                                          onsubmit="return confirm('Hapus diagnosis ini dari rekam medis?');">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button size="sm" type="submit" variant="danger">Hapus</x-ui.button>
                                    </form>
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
        </div>
    @endif
</x-ui.card>
