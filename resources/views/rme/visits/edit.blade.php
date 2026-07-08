<x-settings-shell title="Ubah Kunjungan">
    @php
        $statusLabels = [
            'registered'  => 'Terdaftar',
            'waiting'     => 'Menunggu',
            'in_progress' => 'Dalam Pemeriksaan',
            'completed'   => 'Selesai',
            'cancelled'   => 'Dibatalkan',
        ];
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Ubah Kunjungan"
            :subtitle="$visit->visit_number.' — '.($visit->patient?->name ?? '—')">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.visits.show', $visit)">Kembali</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.card>
            <form method="POST" action="{{ route('rme.visits.update', $visit) }}" class="space-y-6">
                @csrf
                @method('PUT')
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <x-ui.select label="Status" name="status">
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected(old('status', $visit->status) === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                    {{-- Sprint 58.6 — Room selection removed from the visit edit form.
                         Treatment rooms are assigned by Admin Klinik from the queue. --}}
                    <div class="sm:col-span-2">
                        <x-ui.textarea label="Keluhan Utama" name="chief_complaint" rows="3" help="Opsional.">{{ old('chief_complaint', $visit->chief_complaint) }}</x-ui.textarea>
                    </div>
                    {{-- Initial Service --}}
                    <div class="sm:col-span-2 border-t border-hairline pt-4">
                        <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-soft">Layanan Awal (Triase)</p>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <x-ui.select label="Tindakan Awal" name="initial_treatment_id" help="Opsional.">
                                    <option value="">- Pilih tindakan awal -</option>
                                    @foreach ($treatments as $treatment)
                                        <option value="{{ $treatment->id }}" @selected(old('initial_treatment_id', $visit->initial_treatment_id) == $treatment->id)>
                                            {{ $treatment->name }}
                                        </option>
                                    @endforeach
                                </x-ui.select>
                            </div>
                            <div>
                                <x-ui.textarea label="Catatan Layanan Awal" name="initial_service_note" rows="2" help="Opsional.">{{ old('initial_service_note', $visit->initial_service_note) }}</x-ui.textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 border-t border-hairline pt-5">
                    <x-ui.button variant="secondary" :href="route('rme.visits.show', $visit)">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="primary">Simpan Perubahan</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-settings-shell>
