<x-settings-shell title="Daftar Rekam Medis">
    @php
        $statusLabels = [
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_DRAFT => 'Draft',
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL => 'Final',
        ];
        $statusTone = [
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_DRAFT => 'warning',
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL => 'success',
        ];
        $hasFilter = $filters['search'] || $filters['status'] || $filters['visit_date_from'] || $filters['visit_date_to'];
    @endphp

    <div class="space-y-6">
        <x-ui.page-header
            title="Daftar Rekam Medis"
            subtitle="Rekam medis draft dan final pada cabang aktif."
        >
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
        </x-ui.page-header>

        @include('rme.partials.cross-branch-rm-lookup')

        <x-ui.filter-bar :action="route('rme.medical-records.index')">
            <x-ui.input
                name="search"
                label="Cari rekam medis"
                :value="$filters['search']"
                placeholder="Cari pasien, dokter, atau no. kunjungan"
                class="min-w-[14rem] flex-1"
            />
            <x-ui.select name="status" label="Status">
                <option value="">Semua status</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input type="date" name="visit_date_from" label="Dari tanggal" :value="$filters['visit_date_from']" />
            <x-ui.input type="date" name="visit_date_to" label="Sampai tanggal" :value="$filters['visit_date_to']" />
            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                @if ($hasFilter)
                    <x-ui.button variant="secondary" :href="route('rme.medical-records.index')">Atur Ulang</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ui.card padding="">
            <div class="border-b border-hairline px-4 py-3">
                <h3 class="text-base font-semibold text-navy">Rekam Medis</h3>
                <p class="text-sm text-ink-soft">{{ $medicalRecords->total() }} rekam medis ditemukan.</p>
            </div>

            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr class="text-left text-ink-soft">
                        <th scope="col" class="px-4 py-3 font-medium">Tanggal Kunjungan</th>
                        <th scope="col" class="px-3 py-3 font-medium">Nomor Kunjungan</th>
                        <th scope="col" class="px-3 py-3 font-medium">Ruangan</th>
                        <th scope="col" class="px-3 py-3 font-medium">Pasien</th>
                        <th scope="col" class="px-3 py-3 font-medium">Dokter</th>
                        <th scope="col" class="px-3 py-3 font-medium">Status</th>
                        <th scope="col" class="px-3 py-3 font-medium">Difinalisasi Pada</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @forelse ($medicalRecords as $record)
                        <tr class="hover:bg-navy-50">
                            <td class="px-4 py-3 text-ink-soft">{{ $record->clinicVisit?->visit_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="px-3 py-3 font-mono text-ink">{{ $record->clinicVisit?->visit_number ?? '—' }}</td>
                            <td class="px-3 py-3 text-ink-soft">{{ $record->clinicVisit?->clinicRoom?->name ?? '—' }}</td>
                            <td class="px-3 py-3 font-medium text-navy">{{ $record->patient?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-ink-soft">{{ $record->doctor?->name ?? '—' }}</td>
                            <td class="px-3 py-3">
                                <x-ui.badge :tone="$statusTone[$record->status] ?? 'neutral'">
                                    {{ $statusLabels[$record->status] ?? $record->status }}
                                </x-ui.badge>
                            </td>
                            <td class="px-3 py-3 text-ink-soft">{{ $record->finalized_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @if ($record->clinicVisit)
                                    <x-ui.button variant="secondary" size="sm" :href="route('rme.visits.medical-record.show', $record->clinicVisit)">Lihat</x-ui.button>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10">
                                <x-ui.empty-state
                                    title="Belum ada rekam medis."
                                    description="Rekam medis akan muncul setelah dibuat dari detail kunjungan."
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($medicalRecords->hasPages())
                <div class="border-t border-hairline px-4 py-3">
                    {{ $medicalRecords->links() }}
                </div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
