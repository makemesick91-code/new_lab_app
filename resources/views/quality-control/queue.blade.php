@php
    $statusLabels = [
        'QC_PENDING' => 'Menunggu QC',
        'QC_PASSED' => 'QC Lulus',
        'REMAKE' => 'Perbaikan',
    ];
    $priorityLabels = [
        'NORMAL' => 'Normal',
        'URGENT' => 'Mendesak',
        'SUPER_URGENT' => 'Sangat Mendesak',
    ];
@endphp

<x-settings-shell title="Antrean QC">
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <form method="GET" action="{{ route('quality-control.queue') }}" class="flex flex-wrap items-center gap-2">
                <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="No. order, klinik, dokter, pasien"
                       class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                <select name="priority" class="rounded-md border-gray-300 text-sm">
                    <option value="">Semua prioritas</option>
                    @foreach ($priorities as $priority)
                        <option value="{{ $priority }}" @selected($filters['priority'] === $priority)>{{ $priorityLabels[$priority] ?? $priority }}</option>
                    @endforeach
                </select>
                <select name="clinic_id" class="rounded-md border-gray-300 text-sm">
                    <option value="">Semua klinik</option>
                    @foreach ($clinics as $clinic)
                        <option value="{{ $clinic->id }}" @selected($filters['clinic_id'] === $clinic->id)>{{ $clinic->name }}</option>
                    @endforeach
                </select>
                <select name="technician_id" class="rounded-md border-gray-300 text-sm">
                    <option value="">Semua teknisi</option>
                    @foreach ($technicians as $technician)
                        <option value="{{ $technician->id }}" @selected($filters['technician_id'] === $technician->id)>{{ $technician->name }}</option>
                    @endforeach
                </select>
                <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                <a href="{{ route('quality-control.queue') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
            </form>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">No. Order</th>
                            <th class="px-3 py-2 font-medium">Klinik</th>
                            <th class="px-3 py-2 font-medium">Pasien</th>
                            <th class="px-3 py-2 font-medium">Teknisi</th>
                            <th class="px-3 py-2 font-medium">Prioritas</th>
                            <th class="px-3 py-2 font-medium">Tenggat</th>
                            <th class="px-3 py-2 font-medium">Status QC</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($orders as $order)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $order->order_number }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->clinic?->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->patient?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->activeAssignment?->technician?->name ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $priorityLabels[$order->priority] ?? $order->priority }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ format_date_id($order->due_date, '—') }}</td>
                                <td class="px-3 py-2"><span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ $statusLabels[$order->status] ?? $order->status }}</span></td>
                                <td class="px-3 py-2 text-right">
                                    <a href="{{ route('quality-control.show', $order) }}" class="text-indigo-600 hover:text-indigo-500">Tinjau</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Antrean QC kosong.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $orders->links() }}</div>
        </div>
    </div>
</x-settings-shell>
