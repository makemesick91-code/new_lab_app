@php
    $statusLabels = [
        'READY_FOR_DELIVERY' => 'Siap Dikirim',
        'IN_DELIVERY' => 'Dalam Pengiriman',
        'DELIVERED' => 'Terkirim',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
    ];
@endphp

<x-settings-shell title="Laporan Pengiriman">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" action="{{ route('reports.delivery') }}" class="flex flex-wrap items-end gap-2">
                <div><label class="block text-xs text-gray-500">Dari</label><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Sampai</label><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Klinik</label>
                    <select name="clinic_id" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($clinics as $c)<option value="{{ $c->id }}" @selected(($filters['clinic_id'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach
                    </select></div>
                <div><label class="block text-xs text-gray-500">Kurir</label>
                    <select name="courier_id" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($couriers as $u)<option value="{{ $u->id }}" @selected(($filters['courier_id'] ?? null) == $u->id)>{{ $u->name }}</option>@endforeach
                    </select></div>
                <div><label class="block text-xs text-gray-500">Status</label>
                    <select name="delivery_status" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($deliveryStatuses as $s)<option value="{{ $s }}" @selected(($filters['delivery_status'] ?? null) === $s)>{{ $statusLabels[$s] ?? $s }}</option>@endforeach
                    </select></div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                <a href="{{ route('reports.delivery') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
            </form>
            @can('reporting.export')
                <a href="{{ route('reports.delivery.export', $filters) }}" class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Ekspor CSV</a>
            @endcan
        </div>

        <div class="flex flex-wrap gap-3 text-sm">
            <span class="rounded-md bg-gray-50 px-3 py-1">Total: <strong>{{ format_number_id($summary['total']) }}</strong></span>
            @foreach ($summary['by_status'] as $row)
                <span class="rounded-md bg-gray-50 px-3 py-1">{{ $statusLabels[$row->status] ?? $row->status }}: <strong>{{ format_number_id($row->total) }}</strong></span>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="px-3 py-2 font-medium">No. Pengiriman</th><th class="px-3 py-2 font-medium">No. Order</th>
                    <th class="px-3 py-2 font-medium">Klinik</th><th class="px-3 py-2 font-medium">Kurir</th>
                    <th class="px-3 py-2 font-medium">Status</th><th class="px-3 py-2 font-medium">Penerima</th>
                    <th class="px-3 py-2 font-medium">POD</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $r->delivery_number }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->order_number }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->clinic_name }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->courier_name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $statusLabels[$r->status] ?? $r->status }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->receiver_name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ delivery_report_has_signature($r) ? 'Ditandatangani' : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada pengiriman.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $rows->links() }}</div>
    </div>
</x-settings-shell>
