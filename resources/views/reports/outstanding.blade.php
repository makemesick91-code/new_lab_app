@php
    $statusLabels = [
        'DRAFT' => 'Draft',
        'ISSUED' => 'Diterbitkan',
        'PARTIALLY_PAID' => 'Dibayar Sebagian',
        'PAID' => 'Lunas',
        'OVERDUE' => 'Terlambat',
        'VOID' => 'Void',
    ];
@endphp

<x-settings-shell title="Laporan Tertunggak">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" action="{{ route('reports.outstanding') }}" class="flex flex-wrap items-end gap-2">
                <div><label class="block text-xs text-gray-500">Dari</label><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Sampai</label><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Klinik</label>
                    <select name="clinic_id" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($clinics as $c)<option value="{{ $c->id }}" @selected(($filters['clinic_id'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach
                    </select></div>
                <button class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Terapkan</button>
                <a href="{{ route('reports.outstanding') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
            </form>
            @can('reporting.export')
                <a href="{{ route('reports.outstanding.export', $filters) }}" class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Ekspor CSV</a>
            @endcan
        </div>

        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-6">
            <div class="rounded-md bg-amber-50 px-3 py-2 text-amber-800">Total Tertunggak<br><strong class="text-lg">{{ format_currency_id($summary['total_outstanding']) }}</strong></div>
            @foreach (['current' => 'Saat Ini', '1_30' => '1-30 hari', '31_60' => '31-60 hari', '61_90' => '61-90 hari', 'over_90' => '90+ hari'] as $key => $label)
                <div class="rounded-md bg-gray-50 px-3 py-2">{{ $label }}<br><strong class="text-lg text-gray-900">{{ format_currency_id($summary['aging'][$key]) }}</strong></div>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="px-3 py-2 font-medium">No. Invoice</th><th class="px-3 py-2 font-medium">Klinik</th>
                    <th class="px-3 py-2 font-medium">Tanggal Invoice</th><th class="px-3 py-2 font-medium">Jatuh Tempo</th>
                    <th class="px-3 py-2 font-medium">Status</th><th class="px-3 py-2 font-medium text-right">Total</th>
                    <th class="px-3 py-2 font-medium text-right">Tertunggak</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $r->invoice_number }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->clinic_name }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ format_date_id($r->invoice_date) }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->due_date ? format_date_id($r->due_date) : '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $statusLabels[$r->status] ?? $r->status }}</td>
                            <td class="px-3 py-2 text-right text-gray-600">{{ format_currency_id($r->total_amount) }}</td>
                            <td class="px-3 py-2 text-right font-medium text-amber-700">{{ format_currency_id($r->outstanding_amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Tidak ada invoice tertunggak.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $rows->links() }}</div>
    </div>
</x-settings-shell>
