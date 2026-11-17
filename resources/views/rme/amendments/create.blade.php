{{-- DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR4 (rule 240 §6) — the request form
     of a supervised amendment. Reachable only when RmeAmendmentService would
     accept this account now (the controller asks it). The form starts from the
     record as it stands; the service keeps only what changes, validates it
     against the locked record and leaves the record untouched until PR5's
     approve-and-apply. Performed treatments and prescriptions are not amended
     here (plan default 5). --}}
@php
    $subjectLabel = $isChart ? 'Odontogram' : 'Rekam Medis';
    $action = $isChart
        ? route('rme.amendments.store-chart', $subject)
        : route('rme.amendments.store-sheet', $subject);

    if ($isChart) {
        // The same table editor as the chart page (resources/js/app.js odontogramEditor).
        $statusLabels = ['normal' => 'Normal', 'caries' => 'Karies', 'missing' => 'Hilang', 'filling' => 'Tambalan', 'crown' => 'Crown', 'root_treated' => 'PSA'];
        $allTeeth = array_merge(range(11, 18), range(21, 28), range(31, 38), range(41, 48), range(51, 55), range(61, 65), range(71, 75), range(81, 85));
        $editorConfig = ['canEdit' => true, 'teeth' => old('proposed.tooth_map_payload.teeth', $current['teeth']), 'statusLabels' => $statusLabels, 'allTeeth' => $allTeeth];
    }
@endphp
<x-settings-shell title="Ajukan Amandemen {{ $subjectLabel }}">
    <div class="space-y-6">
        <x-ui.page-header :title="'Ajukan Amandemen '.$subjectLabel" subtitle="Rekam medis final terkunci. Usulan ini diperiksa Supervisor RME sebelum dapat diterapkan; rekam medis tidak berubah saat diajukan.">
            <x-slot:breadcrumb>Rekam Medis Elektronik · Amandemen</x-slot:breadcrumb>
        </x-ui.page-header>

        @error('amendment')
            <x-ui.alert variant="warning">{{ $message }}</x-ui.alert>
        @enderror
        @error('proposed')
            <x-ui.alert variant="warning">{{ $message }}</x-ui.alert>
        @enderror

        <form method="POST" action="{{ $action }}" class="space-y-6" data-amendment-request-form
              @if ($isChart) x-data="odontogramEditor(@js($editorConfig))" @endif>
            @csrf

            <x-ui.card title="Alasan amandemen">
                <x-ui.textarea name="reason" label="Alasan (10–1000 karakter)" rows="3" required :value="old('reason')" />
            </x-ui.card>

            @if ($isChart)
                <input type="hidden" name="proposed[tooth_map_payload]" :value="getPayload()" />

                <x-ui.card title="Usulan odontogram" description="Ubah baris gigi yang perlu dikoreksi. Seluruh tabel ini menjadi usulan odontogram.">
                    @error('proposed.tooth_map_payload.teeth')<p class="mb-2 text-sm text-danger">{{ $message }}</p>@enderror
                    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg bg-gray-50 p-3 ring-1 ring-gray-200">
                        <div>
                            <label class="block text-[11px] font-medium uppercase tracking-wide text-gray-500">Gigi</label>
                            <select x-model="newTooth" class="mt-1 block w-28 rounded-lg border-gray-300 text-sm">
                                <option value="">— Pilih gigi —</option>
                                @foreach ($allTeeth as $tooth)
                                    <option value="{{ $tooth }}">{{ $tooth }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium uppercase tracking-wide text-gray-500">Diagnosa</label>
                            <select x-model="newStatus" class="mt-1 block w-40 rounded-lg border-gray-300 text-sm">
                                @foreach ($statusLabels as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="button" @click="addRow()" class="inline-flex items-center rounded-lg bg-brand-600 px-3 py-2 text-sm font-semibold text-white">+ Tambah Baris</button>
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
                                            <select :value="row.status" @change="setStatus(row.tooth, $event.target.value)" class="block w-full rounded-lg border-gray-300 text-sm">
                                                @foreach ($statusLabels as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            <input type="text" maxlength="1000" placeholder="Detail diagnosa (opsional)…" :value="row.additional_condition"
                                                   @input="setAdditional(row.tooth, 'additional_condition', $event.target.value)" class="block w-full rounded-lg border-gray-300 text-sm">
                                        </td>
                                        <td class="px-3 py-2">
                                            <textarea rows="2" maxlength="1000" placeholder="Perawatan…" :value="row.additional_note"
                                                      @input="setAdditional(row.tooth, 'additional_note', $event.target.value)" class="block w-full rounded-lg border-gray-300 text-sm"></textarea>
                                        </td>
                                        <td class="px-3 py-2">
                                            <input type="text" maxlength="255" placeholder="Dokter…" :value="row.dokter"
                                                   @input="setAdditional(row.tooth, 'dokter', $event.target.value)" class="block w-full rounded-lg border-gray-300 text-sm">
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            <button type="button" @click="removeRow(row.tooth)" class="text-sm font-medium text-rose-600">Hapus</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>

                <x-ui.card title="Catatan odontogram">
                    <div class="space-y-4">
                        <x-ui.textarea name="proposed[summary_notes]" id="proposed_summary_notes" label="Ringkasan" rows="3" :value="old('proposed.summary_notes', $current['summary_notes'])" />
                        <x-ui.textarea name="proposed[additional_conditions]" id="proposed_additional_conditions" label="Kondisi tambahan" rows="3" :value="old('proposed.additional_conditions', $current['additional_conditions'])" />
                    </div>
                </x-ui.card>
            @else
                <x-ui.card title="Usulan isi rekam medis" description="Ubah hanya kolom yang perlu dikoreksi; kolom yang tidak diubah tidak termasuk usulan.">
                    <div class="space-y-4">
                        @foreach ($current['fields'] as $field => $fieldData)
                            <x-ui.textarea :name="'proposed[fields]['.$field.']'" :id="'proposed_'.$field" :label="$fieldData['label']" rows="3"
                                           :value="old('proposed.fields.'.$field, $fieldData['value'])" />
                            @error('proposed.fields.'.$field)<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        @endforeach
                    </div>
                </x-ui.card>

                <x-ui.card title="Usulan diagnosis terstruktur">
                    @if ($current['diagnoses'] !== [])
                        <p class="mb-2 text-sm text-ink-soft">Centang diagnosis yang diusulkan untuk dihapus.</p>
                        <ul class="mb-4 space-y-1 text-sm">
                            @foreach ($current['diagnoses'] as $diagnosis)
                                <li>
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" name="proposed[diagnoses][remove][]" value="{{ $diagnosis['id'] }}"
                                               @checked(in_array((string) $diagnosis['id'], array_map('strval', (array) old('proposed.diagnoses.remove', [])), true))>
                                        {{ $diagnosis['label'] }} ({{ $diagnosis['role'] === 'primary' ? 'utama' : 'sekunder' }})
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @error('proposed.diagnoses.remove')<p class="mb-2 text-sm text-danger">{{ $message }}</p>@enderror

                    <div x-data="{
                            q: '', results: [], selected: null,
                            async search() {
                                this.selected = null;
                                if (this.q.trim().length < 2) { this.results = []; return; }
                                const res = await fetch(`{{ route('rme.diagnoses.search') }}?q=${encodeURIComponent(this.q)}`, { headers: { 'Accept': 'application/json' } });
                                if (res.ok) { this.results = (await res.json()).data; }
                            },
                            pick(item) { this.selected = item; this.q = item.label; this.results = []; },
                         }" class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div class="relative md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-ink">Tambah diagnosis (kode / nama)</label>
                            <input type="text" x-model="q" @input.debounce.300ms="search()" placeholder="mis. K02 atau karies"
                                   class="w-full rounded-lg border border-hairline px-3 py-2 text-sm" />
                            <input type="hidden" name="proposed[diagnoses][add][0][clinical_diagnosis_id]" :value="selected ? selected.id : ''" />
                            <div x-show="results.length" x-cloak class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-hairline bg-white shadow-card">
                                <template x-for="item in results" :key="item.id">
                                    <button type="button" @click="pick(item)" class="block w-full px-3 py-2 text-left text-sm hover:bg-brand-50" x-text="item.label"></button>
                                </template>
                            </div>
                        </div>
                        <div>
                            <x-ui.select label="Peran diagnosis" name="proposed[diagnoses][add][0][diagnosis_role]">
                                <option value="secondary">Sekunder (secondary)</option>
                                <option value="primary">Utama (primary)</option>
                            </x-ui.select>
                        </div>
                        <div class="md:col-span-3">
                            <x-ui.input label="Catatan diagnosis (opsional)" name="proposed[diagnoses][add][0][notes]" maxlength="500" />
                        </div>
                    </div>
                    @error('proposed.diagnoses.add')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
                </x-ui.card>
            @endif

            <div class="flex justify-end gap-3">
                <x-ui.button type="submit" variant="primary">Ajukan Amandemen</x-ui.button>
            </div>
        </form>
    </div>
</x-settings-shell>
