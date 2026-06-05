<x-settings-shell title="Laporan Produksi">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" action="{{ route('reports.production') }}" class="flex flex-wrap items-end gap-2">
                <div><label class="block text-xs text-gray-500">Dari</label><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Sampai</label><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Teknisi</label>
                    <select name="technician_id" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($technicians as $t)<option value="{{ $t->id }}" @selected(($filters['technician_id'] ?? null) == $t->id)>{{ $t->name }}</option>@endforeach
                    </select></div>
                <div><label class="block text-xs text-gray-500">Status</label>
                    <select name="status" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($statuses as $s)<option value="{{ $s }}" @selected(($filters['status'] ?? null) === $s)>{{ $s }}</option>@endforeach
                    </select></div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filter</button>
                <a href="{{ route('reports.production') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            </form>
            @can('reporting.export')
                <a href="{{ route('reports.production.export', $filters) }}" class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Export CSV</a>
            @endcan
        </div>

        <div>
            <h3 class="text-sm font-semibold text-gray-800">Beban Kerja Teknisi</h3>
            <div class="mt-2 flex flex-wrap gap-3 text-sm">
                @forelse ($summary['workload'] as $row)
                    <span class="rounded-md bg-gray-50 px-3 py-1">{{ $row->technician_name ?? '—' }}: <strong>{{ format_number_id($row->total_assignments) }}</strong> ({{ format_number_id($row->completed) }} selesai)</span>
                @empty
                    <span class="text-gray-400">Belum ada data.</span>
                @endforelse
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="px-3 py-2 font-medium">No. Order</th><th class="px-3 py-2 font-medium">Teknisi</th>
                    <th class="px-3 py-2 font-medium">Klinik</th><th class="px-3 py-2 font-medium">Status</th>
                    <th class="px-3 py-2 font-medium">Ditugaskan</th><th class="px-3 py-2 font-medium">Selesai</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $r->order_number }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->technician_name }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->clinic_name }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->status }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->assigned_at }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->completed_at ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">Belum ada data produksi.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $rows->links() }}</div>
    </div>
</x-settings-shell>
