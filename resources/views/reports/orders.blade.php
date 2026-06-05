<x-settings-shell title="Laporan Order">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" action="{{ route('reports.orders') }}" class="flex flex-wrap items-end gap-2">
                <div><label class="block text-xs text-gray-500">Dari</label><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Sampai</label><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Klinik</label>
                    <select name="clinic_id" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($clinics as $c)<option value="{{ $c->id }}" @selected(($filters['clinic_id'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach
                    </select></div>
                <div><label class="block text-xs text-gray-500">Status</label>
                    <select name="status" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? null) === $s)>{{ $s }}</option>@endforeach
                    </select></div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filter</button>
                <a href="{{ route('reports.orders') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            </form>
            @can('reporting.export')
                <a href="{{ route('reports.orders.export', $filters) }}" class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Export CSV</a>
            @endcan
        </div>

        <div class="flex flex-wrap gap-3 text-sm">
            <span class="rounded-md bg-gray-50 px-3 py-1">Total: <strong>{{ number_format($summary['total']) }}</strong></span>
            @foreach ($summary['by_status'] as $row)
                <span class="rounded-md bg-gray-50 px-3 py-1">{{ $row->status }}: <strong>{{ number_format($row->total) }}</strong></span>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="px-3 py-2 font-medium">No. Order</th><th class="px-3 py-2 font-medium">Klinik</th>
                    <th class="px-3 py-2 font-medium">Dokter</th><th class="px-3 py-2 font-medium">Pasien</th>
                    <th class="px-3 py-2 font-medium">Tanggal Order</th><th class="px-3 py-2 font-medium">Jatuh Tempo</th>
                    <th class="px-3 py-2 font-medium">Prioritas</th><th class="px-3 py-2 font-medium">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $r->order_number }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->clinic_name }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->doctor_name }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->patient_name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->order_date }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->due_date ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->priority }}</td>
                            <td class="px-3 py-2"><span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">{{ $r->status }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Belum ada order.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $rows->links() }}</div>
    </div>
</x-settings-shell>
