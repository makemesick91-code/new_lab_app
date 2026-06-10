<x-settings-shell title="Daftar Rekam Medis">
    @php
        $statusLabels = [
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_DRAFT => 'Draft',
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL => 'Final',
        ];
        $statusBadge = [
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_DRAFT => 'bg-amber-50 text-amber-700',
            \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL => 'bg-green-50 text-green-700',
        ];
        $hasFilter = $filters['search'] || $filters['status'] || $filters['visit_date_from'] || $filters['visit_date_to'];
    @endphp

    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('rme.medical-records.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="Cari pasien, dokter, atau no. kunjungan"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <select name="status" class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                    <label class="flex flex-col gap-0.5 text-xs text-gray-500">
                        Dari tanggal
                        <input type="date" name="visit_date_from" value="{{ $filters['visit_date_from'] }}"
                               class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </label>
                    <label class="flex flex-col gap-0.5 text-xs text-gray-500">
                        Sampai tanggal
                        <input type="date" name="visit_date_to" value="{{ $filters['visit_date_to'] }}"
                               class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </label>
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                    @if ($hasFilter)
                        <a href="{{ route('rme.medical-records.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
                    @endif
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Tanggal Kunjungan</th>
                            <th class="px-3 py-2 font-medium">Nomor Kunjungan</th>
                            <th class="px-3 py-2 font-medium">Pasien</th>
                            <th class="px-3 py-2 font-medium">Dokter</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Difinalisasi Pada</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($medicalRecords as $record)
                            <tr>
                                <td class="px-3 py-2 text-gray-600">{{ $record->clinicVisit?->visit_date?->format('d/m/Y') ?? '—' }}</td>
                                <td class="px-3 py-2 font-mono text-gray-700">{{ $record->clinicVisit?->visit_number ?? '—' }}</td>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $record->patient?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $record->doctor?->name ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusBadge[$record->status] ?? 'bg-gray-100 text-gray-600' }}">
                                        {{ $statusLabels[$record->status] ?? $record->status }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-gray-600">{{ $record->finalized_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if ($record->clinicVisit)
                                        <a href="{{ route('rme.visits.medical-record.show', $record->clinicVisit) }}" class="text-indigo-600 hover:text-indigo-500">Lihat</a>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada rekam medis.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($medicalRecords->hasPages())
                <div class="mt-4">
                    {{ $medicalRecords->links() }}
                </div>
            @endif
        </div>
    </div>
</x-settings-shell>
