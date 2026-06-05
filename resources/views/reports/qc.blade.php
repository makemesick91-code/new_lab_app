<x-settings-shell title="Laporan Quality Control">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" action="{{ route('reports.qc') }}" class="flex flex-wrap items-end gap-2">
                <div><label class="block text-xs text-gray-500">Dari</label><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Sampai</label><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Klinik</label>
                    <select name="clinic_id" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($clinics as $c)<option value="{{ $c->id }}" @selected(($filters['clinic_id'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach
                    </select></div>
                <div><label class="block text-xs text-gray-500">Hasil QC</label>
                    <select name="qc_status" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($qcStatuses as $s)<option value="{{ $s }}" @selected(($filters['qc_status'] ?? null) === $s)>{{ $s }}</option>@endforeach
                    </select></div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filter</button>
                <a href="{{ route('reports.qc') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            </form>
            @can('reporting.export')
                <a href="{{ route('reports.qc.export', $filters) }}" class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Export CSV</a>
            @endcan
        </div>

        <div class="flex flex-wrap gap-3 text-sm">
            <span class="rounded-md bg-gray-50 px-3 py-1">Total: <strong>{{ number_format($summary['total']) }}</strong></span>
            @foreach ($summary['by_result'] as $row)
                <span class="rounded-md bg-gray-50 px-3 py-1">{{ $row->result }}: <strong>{{ number_format($row->total) }}</strong></span>
            @endforeach
            <span class="rounded-md bg-amber-50 px-3 py-1 text-amber-700">Remake: <strong>{{ number_format($summary['remake_count']) }}</strong></span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="px-3 py-2 font-medium">No. Order</th><th class="px-3 py-2 font-medium">Klinik</th>
                    <th class="px-3 py-2 font-medium">Pemeriksa</th><th class="px-3 py-2 font-medium">Hasil</th>
                    <th class="px-3 py-2 font-medium">Selesai</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $r->order_number }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->clinic_name }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->inspector_name }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->result ?? 'IN_REVIEW' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->completed_at ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Belum ada data QC.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $rows->links() }}</div>
    </div>
</x-settings-shell>
