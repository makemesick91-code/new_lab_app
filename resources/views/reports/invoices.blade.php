<x-settings-shell title="Laporan Invoice">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" action="{{ route('reports.invoices') }}" class="flex flex-wrap items-end gap-2">
                <div><label class="block text-xs text-gray-500">Dari</label><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Sampai</label><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Klinik</label>
                    <select name="clinic_id" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($clinics as $c)<option value="{{ $c->id }}" @selected(($filters['clinic_id'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach
                    </select></div>
                <div><label class="block text-xs text-gray-500">Status</label>
                    <select name="invoice_status" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua (tanpa VOID)</option>
                        @foreach ($invoiceStatuses as $s)<option value="{{ $s }}" @selected(($filters['invoice_status'] ?? null) === $s)>{{ $s }}</option>@endforeach
                    </select></div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filter</button>
                <a href="{{ route('reports.invoices') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            </form>
            @can('reporting.export')
                <a href="{{ route('reports.invoices.export', $filters) }}" class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Export CSV</a>
            @endcan
        </div>

        <div class="flex flex-wrap gap-3 text-sm">
            <span class="rounded-md bg-gray-50 px-3 py-1">Jumlah: <strong>{{ format_number_id($summary['count']) }}</strong></span>
            <span class="rounded-md bg-green-50 px-3 py-1 text-green-700">Total: <strong>{{ format_currency_id($summary['total_amount']) }}</strong></span>
            @foreach ($summary['by_status'] as $row)
                <span class="rounded-md bg-gray-50 px-3 py-1">{{ $row->status }}: <strong>{{ format_number_id($row->total) }}</strong></span>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="px-3 py-2 font-medium">No. Invoice</th><th class="px-3 py-2 font-medium">Klinik</th>
                    <th class="px-3 py-2 font-medium">Tanggal</th><th class="px-3 py-2 font-medium">Jatuh Tempo</th>
                    <th class="px-3 py-2 font-medium">Status</th><th class="px-3 py-2 font-medium text-right">Total</th>
                    <th class="px-3 py-2 font-medium text-right">Dibayar</th><th class="px-3 py-2 font-medium text-right">Tertunggak</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $r->invoice_number }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->clinic_name }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->invoice_date }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->due_date ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->status }}</td>
                            <td class="px-3 py-2 text-right text-gray-600">{{ format_currency_id($r->total_amount) }}</td>
                            <td class="px-3 py-2 text-right text-gray-600">{{ format_currency_id($r->paid_amount) }}</td>
                            <td class="px-3 py-2 text-right text-gray-600">{{ format_currency_id($r->outstanding_amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Belum ada invoice.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $rows->links() }}</div>
    </div>
</x-settings-shell>
