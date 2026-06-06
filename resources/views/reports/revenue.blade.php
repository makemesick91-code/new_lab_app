<x-settings-shell title="Laporan Pendapatan">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" action="{{ route('reports.revenue') }}" class="flex flex-wrap items-end gap-2">
                <div><label class="block text-xs text-gray-500">Dari</label><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Sampai</label><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Klinik</label>
                    <select name="clinic_id" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($clinics as $c)<option value="{{ $c->id }}" @selected(($filters['clinic_id'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach
                    </select></div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                <a href="{{ route('reports.revenue') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
            </form>
            @can('reporting.export')
                <a href="{{ route('reports.revenue.export', $filters) }}" class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Ekspor CSV</a>
            @endcan
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-md bg-green-50 px-4 py-3 text-green-800">Pendapatan Invoice (tanpa VOID)<br><strong class="text-2xl">{{ format_currency_id($summary['invoice_revenue']) }}</strong></div>
            <div class="rounded-md bg-blue-50 px-4 py-3 text-blue-800">Pembayaran Diterima<br><strong class="text-2xl">{{ format_currency_id($summary['payment_received']) }}</strong></div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold text-gray-800">Pendapatan per Bulan</h3>
                <table class="mt-2 min-w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($summary['by_month'] as $row)
                            <tr><td class="px-2 py-1 text-gray-700">{{ format_month_id($row->month) }}</td><td class="px-2 py-1 text-right font-medium text-gray-900">{{ format_currency_id($row->amount) }}</td></tr>
                        @empty
                            <tr><td class="px-2 py-3 text-center text-gray-400">Belum ada data pendapatan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-800">Pendapatan per Klinik</h3>
                <table class="mt-2 min-w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($summary['by_clinic'] as $row)
                            <tr><td class="px-2 py-1 text-gray-700">{{ $row->clinic_name ?? '—' }}</td><td class="px-2 py-1 text-right font-medium text-gray-900">{{ format_currency_id($row->amount) }}</td></tr>
                        @empty
                            <tr><td class="px-2 py-3 text-center text-gray-400">Belum ada data pendapatan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-settings-shell>
