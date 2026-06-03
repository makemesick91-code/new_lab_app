<x-settings-shell title="Revenue Report">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" action="{{ route('reports.revenue') }}" class="flex flex-wrap items-end gap-2">
                <div><label class="block text-xs text-gray-500">From</label><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">To</label><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Clinic</label>
                    <select name="clinic_id" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">All</option>
                        @foreach ($clinics as $c)<option value="{{ $c->id }}" @selected(($filters['clinic_id'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach
                    </select></div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Filter</button>
                <a href="{{ route('reports.revenue') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            </form>
            @can('reporting.export')
                <a href="{{ route('reports.revenue.export', $filters) }}" class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Export CSV</a>
            @endcan
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-md bg-green-50 px-4 py-3 text-green-800">Invoiced Revenue (excl. VOID)<br><strong class="text-2xl">{{ number_format($summary['invoice_revenue'], 2) }}</strong></div>
            <div class="rounded-md bg-blue-50 px-4 py-3 text-blue-800">Payments Received<br><strong class="text-2xl">{{ number_format($summary['payment_received'], 2) }}</strong></div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div>
                <h3 class="text-sm font-semibold text-gray-800">Revenue by Month</h3>
                <table class="mt-2 min-w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($summary['by_month'] as $row)
                            <tr><td class="px-2 py-1 text-gray-700">{{ $row->month }}</td><td class="px-2 py-1 text-right font-medium text-gray-900">{{ number_format($row->amount, 2) }}</td></tr>
                        @empty
                            <tr><td class="px-2 py-3 text-center text-gray-400">No revenue data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>
                <h3 class="text-sm font-semibold text-gray-800">Revenue by Clinic</h3>
                <table class="mt-2 min-w-full text-sm">
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($summary['by_clinic'] as $row)
                            <tr><td class="px-2 py-1 text-gray-700">{{ $row->clinic_name ?? '—' }}</td><td class="px-2 py-1 text-right font-medium text-gray-900">{{ number_format((float) $row->amount, 2) }}</td></tr>
                        @empty
                            <tr><td class="px-2 py-3 text-center text-gray-400">No revenue data.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-settings-shell>
