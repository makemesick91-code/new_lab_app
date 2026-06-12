<x-settings-shell title="Dashboard RME">
    <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-900">Laporan Pembayaran RME</h2>
            <form method="GET" action="{{ route('rme.reports.payments') }}" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-xs text-gray-500">Cabang</label>
                    <select name="branch_id" class="mt-1 rounded-md border-gray-300 text-sm">
                        <option value="">Semua cabang RME</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected($selectedBranchId == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                <a href="{{ route('rme.reports.payments') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
            </form>
        </div>

        <div class="flex flex-wrap gap-3 text-sm">
            <span class="rounded-md bg-green-50 px-3 py-1 text-green-700">Total Diterima: <strong>{{ format_currency_id($totalAmount) }}</strong></span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead><tr class="text-left text-gray-500">
                    <th class="px-3 py-2 font-medium">No. Invoice</th>
                    <th class="px-3 py-2 font-medium">Pasien</th>
                    <th class="px-3 py-2 font-medium">Cabang</th>
                    <th class="px-3 py-2 font-medium">Tanggal</th>
                    <th class="px-3 py-2 font-medium text-right">Jumlah</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($payments as $p)
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $p->rmeInvoice?->invoice_number ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $p->patient?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $p->branch?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600">{{ $p->paid_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="px-3 py-2 text-right text-gray-600">{{ format_currency_id($p->amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">Belum ada pembayaran RME.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-settings-shell>
