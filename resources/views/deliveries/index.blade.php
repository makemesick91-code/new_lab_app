@php
    $statusLabels = [
        'READY_FOR_DELIVERY' => 'Siap Dikirim',
        'IN_DELIVERY' => 'Dalam Pengiriman',
        'DELIVERED' => 'Terkirim',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
    ];
    $priorityLabels = [
        'NORMAL' => 'Normal',
        'URGENT' => 'Mendesak',
        'SUPER_URGENT' => 'Sangat Mendesak',
    ];
@endphp

<x-settings-shell title="Antrean Pengiriman">
    <div class="space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="p-6 space-y-4">
                <form method="GET" action="{{ route('deliveries.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="No. order, no. pengiriman, klinik, dokter, pasien"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <select name="status" class="rounded-md border-gray-300 text-sm">
                        <option value="">Semua status pengiriman</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                    <select name="clinic_id" class="rounded-md border-gray-300 text-sm">
                        <option value="">Semua klinik</option>
                        @foreach ($clinics as $clinic)
                            <option value="{{ $clinic->id }}" @selected($filters['clinic_id'] === $clinic->id)>{{ $clinic->name }}</option>
                        @endforeach
                    </select>
                    <select name="courier_id" class="rounded-md border-gray-300 text-sm">
                        <option value="">Semua kurir</option>
                        @foreach ($couriers as $courier)
                            <option value="{{ $courier->id }}" @selected($filters['courier_id'] === $courier->id)>{{ $courier->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filter</button>
                    <a href="{{ route('deliveries.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
                </form>
            </div>
        </div>

        @canany(['create_delivery', 'manage_delivery'])
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Siap Diproses</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead><tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">No. Order</th>
                            <th class="px-3 py-2 font-medium">Klinik</th>
                            <th class="px-3 py-2 font-medium">Pasien</th>
                            <th class="px-3 py-2 font-medium">Prioritas</th>
                            <th class="px-3 py-2 font-medium">Kurir</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($readyOrders as $order)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-gray-900">{{ $order->order_number }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ $order->clinic?->name }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ $order->patient?->name ?? '-' }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ $priorityLabels[$order->priority] ?? $order->priority }}</td>
                                    <td class="px-3 py-2">
                                        <form id="create-delivery-{{ $order->id }}" method="POST" action="{{ route('deliveries.store') }}" class="flex items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="lab_order_id" value="{{ $order->id }}">
                                            <select name="courier_id" class="rounded-md border-gray-300 text-xs">
                                                <option value="">Tugaskan nanti</option>
                                                @foreach ($couriers as $courier)
                                                    <option value="{{ $courier->id }}">{{ $courier->name }}</option>
                                                @endforeach
                                            </select>
                                            <input type="text" name="delivery_notes" placeholder="Catatan" class="rounded-md border-gray-300 text-xs">
                                        </form>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <button form="create-delivery-{{ $order->id }}" class="text-indigo-600 hover:text-indigo-500">Buat Pengiriman</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada order QC lulus yang menunggu pengiriman.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endcanany

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800">Pengiriman Aktif</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead><tr class="text-left text-gray-500">
                        <th class="px-3 py-2 font-medium">No. Pengiriman</th>
                        <th class="px-3 py-2 font-medium">No. Order</th>
                        <th class="px-3 py-2 font-medium">Klinik</th>
                        <th class="px-3 py-2 font-medium">Kurir</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Tenggat</th>
                        <th class="px-3 py-2 font-medium text-right">Aksi</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($deliveries as $delivery)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $delivery->delivery_number }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $delivery->labOrder?->order_number }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $delivery->labOrder?->clinic?->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $delivery->courier?->name ?? '-' }}</td>
                                <td class="px-3 py-2"><span class="inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ $statusLabels[$delivery->status] ?? $delivery->status }}</span></td>
                                <td class="px-3 py-2 text-gray-600">{{ format_date_id($delivery->labOrder?->due_date) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <a href="{{ route('deliveries.show', $delivery) }}" class="text-indigo-600 hover:text-indigo-500">Lihat Detail</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada data pengiriman.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $deliveries->links() }}</div>
        </div>
    </div>
</x-settings-shell>
