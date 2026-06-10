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
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Daftar Rekam Medis</h2>
            <p class="mt-1 text-sm text-gray-500">Rekam medis draft dan final pada cabang aktif.</p>
        </div>

        <x-ui.card padding="p-4">
            <form method="GET" action="{{ route('rme.medical-records.index') }}">
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[12rem] flex-1">
                        <label for="record-search" class="text-sm font-medium text-gray-700">Cari rekam medis</label>
                        <input id="record-search" type="text" name="search" value="{{ $filters['search'] }}" placeholder="Cari pasien, dokter, atau no. kunjungan"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <div>
                        <label for="record-status" class="text-sm font-medium text-gray-700">Status</label>
                        <select id="record-status" name="status" class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">Semua status</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="record-date-from" class="text-sm font-medium text-gray-700">Dari tanggal</label>
                        <input id="record-date-from" type="date" name="visit_date_from" value="{{ $filters['visit_date_from'] }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <div>
                        <label for="record-date-to" class="text-sm font-medium text-gray-700">Sampai tanggal</label>
                        <input id="record-date-to" type="date" name="visit_date_to" value="{{ $filters['visit_date_to'] }}"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <x-ui.button type="submit" variant="neutral">Terapkan</x-ui.button>
                    @if ($hasFilter)
                        <x-ui.button variant="secondary" :href="route('rme.medical-records.index')">Atur Ulang</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="">
            <div class="border-b border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Rekam Medis</h3>
                <p class="text-sm text-gray-500">{{ $medicalRecords->total() }} rekam medis ditemukan.</p>
            </div>

            <x-ui.table>
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">Tanggal Kunjungan</th>
                        <th scope="col" class="px-3 py-3 font-medium">Nomor Kunjungan</th>
                        <th scope="col" class="px-3 py-3 font-medium">Pasien</th>
                        <th scope="col" class="px-3 py-3 font-medium">Dokter</th>
                        <th scope="col" class="px-3 py-3 font-medium">Status</th>
                        <th scope="col" class="px-3 py-3 font-medium">Difinalisasi Pada</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($medicalRecords as $record)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-gray-600">{{ $record->clinicVisit?->visit_date?->format('d/m/Y') ?? '—' }}</td>
                            <td class="px-3 py-3 font-mono text-gray-700">{{ $record->clinicVisit?->visit_number ?? '—' }}</td>
                            <td class="px-3 py-3 font-medium text-gray-900">{{ $record->patient?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $record->doctor?->name ?? '—' }}</td>
                            <td class="px-3 py-3">
                                <x-ui.badge :tone="$statusTone[$record->status] ?? 'neutral'">
                                    {{ $statusLabels[$record->status] ?? $record->status }}
                                </x-ui.badge>
                            </td>
                            <td class="px-3 py-3 text-gray-600">{{ $record->finalized_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-right">
                                @if ($record->clinicVisit)
                                    <x-ui.button variant="secondary" :href="route('rme.visits.medical-record.show', $record->clinicVisit)" class="!px-3 !py-1.5 !text-xs">Lihat</x-ui.button>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center">
                                <p class="text-sm font-medium text-gray-900">Belum ada rekam medis.</p>
                                <p class="mt-1 text-sm text-gray-500">Rekam medis akan muncul setelah dibuat dari detail kunjungan.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($medicalRecords->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">
                    {{ $medicalRecords->links() }}
                </div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
