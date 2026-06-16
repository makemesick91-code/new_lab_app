@php
    $statusLabels = $statusLabels ?? [
        'registered' => 'Terdaftar',
        'waiting' => 'Menunggu',
        'in_progress' => 'Dalam Pemeriksaan',
        'cashier_pending' => 'Menunggu Kasir',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
    ];
    $currentVisitId = $currentVisitId ?? null;
@endphp

<x-ui.card title="Riwayat Kunjungan Pasien">
    @if ($patientVisitHistory->isEmpty())
        <p class="text-sm text-gray-500">Tidak ada riwayat kunjungan sebelumnya.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-3 py-2 font-medium">No. Kunjungan</th>
                        <th scope="col" class="px-3 py-2 font-medium">Tanggal</th>
                        <th scope="col" class="px-3 py-2 font-medium">Jenis</th>
                        <th scope="col" class="px-3 py-2 font-medium">Dokter</th>
                        <th scope="col" class="px-3 py-2 font-medium">Status</th>
                        <th scope="col" class="px-3 py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($patientVisitHistory as $historyVisit)
                        <tr @class(['bg-teal-50/60' => (int) $historyVisit->id === (int) $currentVisitId])>
                            <td class="px-3 py-2 font-mono text-gray-900">{{ $historyVisit->visit_number }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $historyVisit->visit_date?->format('d/m/Y') }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $historyVisit->visitTypeLabel() }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $historyVisit->doctor?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $statusLabels[$historyVisit->status] ?? $historyVisit->status }}</td>
                            <td class="px-3 py-2 text-right">
                                @if ((int) $historyVisit->id === (int) $currentVisitId)
                                    <span class="text-xs font-medium text-teal-700">Kunjungan ini</span>
                                @else
                                    @can('view', $historyVisit)
                                        <a href="{{ route('rme.visits.show', $historyVisit) }}" class="text-teal-700 hover:text-teal-900 text-xs font-medium">Detail</a>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-ui.card>
