<x-settings-shell title="Reports Dashboard">
    <div class="space-y-6">
        {{-- Filter --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <form method="GET" action="{{ route('reports.dashboard') }}" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-xs text-gray-500">From</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">To</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Clinic</label>
                    <select name="clinic_id" class="mt-1 rounded-md border-gray-300 text-sm">
                        <option value="">All clinics</option>
                        @foreach ($clinics as $clinic)
                            <option value="{{ $clinic->id }}" @selected(($filters['clinic_id'] ?? null) == $clinic->id)>{{ $clinic->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Apply</button>
                <a href="{{ route('reports.dashboard') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            </form>
        </div>

        {{-- Cards --}}
        @php($cardDefs = [
            'total_orders' => 'Total Orders', 'in_progress' => 'In Production', 'completed' => 'Completed',
            'delivered' => 'Delivered', 'pending_qc' => 'Pending QC', 'remake_count' => 'Remakes',
        ])
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($cardDefs as $key => $label)
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <p class="text-xs text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900">{{ number_format($cards[$key]) }}</p>
                </div>
            @endforeach
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Revenue</p>
                <p class="mt-1 text-2xl font-semibold text-green-700">{{ number_format($cards['revenue'], 2) }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Outstanding</p>
                <p class="mt-1 text-2xl font-semibold text-amber-700">{{ number_format($cards['outstanding'], 2) }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Overdue Invoices</p>
                <p class="mt-1 text-2xl font-semibold text-red-700">{{ number_format($cards['overdue_invoices']) }}</p>
            </div>
        </div>

        {{-- Charts (compact tables) --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @php($chartDefs = [
                'orders_by_status' => ['Orders by Status', 'status', 'total'],
                'orders_by_clinic' => ['Orders by Clinic', 'clinic_name', 'total'],
                'qc_summary' => ['QC Summary', 'result', 'total'],
                'delivery_summary' => ['Delivery Summary', 'status', 'total'],
                'payments_by_method' => ['Payments by Method', 'payment_method', 'amount'],
                'revenue_by_month' => ['Revenue by Month', 'month', 'amount'],
            ])
            @foreach ($chartDefs as $key => [$title, $labelCol, $valCol])
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold text-gray-800">{{ $title }}</h3>
                    <table class="mt-3 min-w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($charts[$key] as $row)
                                <tr>
                                    <td class="px-2 py-1 text-gray-700">{{ $row->{$labelCol} ?? '—' }}</td>
                                    <td class="px-2 py-1 text-right font-medium text-gray-900">{{ $valCol === 'amount' ? number_format((float) $row->{$valCol}, 2) : number_format((int) $row->{$valCol}) }}</td>
                                </tr>
                            @empty
                                <tr><td class="px-2 py-3 text-center text-gray-400">No data.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    </div>
</x-settings-shell>
