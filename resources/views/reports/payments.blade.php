@php
    $methodLabels = [
        'CASH' => 'Tunai',
        'BANK_TRANSFER' => 'Transfer Bank',
        'QRIS' => 'QRIS',
        'CARD' => 'Kartu',
        'OTHER' => 'Lainnya',
    ];
@endphp

<x-settings-shell title="Laporan Pembayaran">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" action="{{ route('reports.payments') }}" class="flex flex-wrap items-end gap-2">
                <div><label class="block text-xs text-gray-500">Dari</label><input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Sampai</label><input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" /></div>
                <div><label class="block text-xs text-gray-500">Klinik</label>
                    <select name="clinic_id" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($clinics as $c)<option value="{{ $c->id }}" @selected(($filters['clinic_id'] ?? null) == $c->id)>{{ $c->name }}</option>@endforeach
                    </select></div>
                <div><label class="block text-xs text-gray-500">Metode</label>
                    <select name="payment_method" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($methods as $m)<option value="{{ $m }}" @selected(($filters['payment_method'] ?? null) === $m)>{{ $methodLabels[$m] ?? $m }}</option>@endforeach
                    </select></div>
                <div><label class="block text-xs text-gray-500">Diterima Oleh</label>
                    <select name="received_by" class="mt-1 rounded-md border-gray-300 text-sm"><option value="">Semua</option>
                        @foreach ($users as $u)<option value="{{ $u->id }}" @selected(($filters['received_by'] ?? null) == $u->id)>{{ $u->name }}</option>@endforeach
                    </select></div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                <a href="{{ route('reports.payments') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
            </form>
            @can('reporting.export')
                <a href="{{ route('reports.payments.export', $filters) }}" class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Ekspor CSV</a>
            @endcan
        </div>

        <div class="flex flex-wrap gap-3 text-sm">
            <span class="rounded-md bg-green-50 px-3 py-1 text-green-700">Total Diterima: <strong>{{ format_currency_id($summary['total']) }}</strong></span>
            @foreach ($summary['by_method'] as $row)
                <span class="rounded-md bg-gray-50 px-3 py-1">{{ $methodLabels[$row->payment_method] ?? $row->payment_method }}: <strong>{{ format_currency_id($row->amount) }}</strong></span>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="px-3 py-2 font-medium">No. Pembayaran</th><th class="px-3 py-2 font-medium">No. Invoice</th>
                    <th class="px-3 py-2 font-medium">Klinik</th><th class="px-3 py-2 font-medium">Tanggal</th>
                    <th class="px-3 py-2 font-medium">Metode</th><th class="px-3 py-2 font-medium text-right">Jumlah</th>
                    <th class="px-3 py-2 font-medium">Diterima Oleh</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $r->payment_number }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->invoice_number }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->clinic_name }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ format_date_id($r->payment_date) }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $methodLabels[$r->payment_method] ?? $r->payment_method }}</td>
                            <td class="px-3 py-2 text-right text-gray-600">{{ format_currency_id($r->amount) }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $r->received_by_name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-3 py-6 text-center text-gray-400">Belum ada pembayaran.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div>{{ $rows->links() }}</div>
    </div>
</x-settings-shell>
