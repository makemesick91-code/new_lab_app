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

<x-settings-shell title="Invoice">
    <div class="space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg">
            <div class="p-6 space-y-4">
                <div class="flex items-center justify-between gap-3">
                    <form method="GET" action="{{ route('invoices.index') }}" class="flex flex-wrap items-center gap-2">
                        <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="No. invoice atau klinik"
                               class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                        <select name="status" class="rounded-md border-gray-300 text-sm">
                            <option value="">Semua status</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                            @endforeach
                        </select>
                        <select name="clinic_id" class="rounded-md border-gray-300 text-sm">
                            <option value="">Semua klinik</option>
                            @foreach ($clinics as $clinic)
                                <option value="{{ $clinic->id }}" @selected($filters['clinic_id'] === $clinic->id)>{{ $clinic->name }}</option>
                            @endforeach
                        </select>
                        <input type="date" name="invoice_date" value="{{ $filters['invoice_date'] }}" class="rounded-md border-gray-300 text-sm" />
                        <button type="submit" class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                        <a href="{{ route('invoices.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
                    </form>

                    @canany(['create_invoice', 'manage_invoice'])
                        <a href="{{ route('invoices.create') }}" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Buat Invoice</a>
                    @endcanany
                </div>
            </div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">No. Invoice</th>
                            <th class="px-3 py-2 font-medium">Klinik</th>
                            <th class="px-3 py-2 font-medium">Tanggal</th>
                            <th class="px-3 py-2 font-medium">Jatuh Tempo</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium text-right">Total</th>
                            <th class="px-3 py-2 font-medium text-right">Dibayar</th>
                            <th class="px-3 py-2 font-medium text-right">Tertunggak</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($invoices as $invoice)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $invoice->invoice_number }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $invoice->clinic?->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ format_date_id($invoice->invoice_date) }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ format_date_id($invoice->due_date) }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ $statusLabels[$invoice->status] ?? $invoice->status }}</span>
                                </td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ format_currency_id($invoice->total_amount) }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ format_currency_id($invoice->paid_amount) }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ format_currency_id($invoice->outstanding_amount) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <a href="{{ route('invoices.show', $invoice) }}" class="text-indigo-600 hover:text-indigo-500">Lihat</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-3 py-6 text-center text-gray-400">Belum ada invoice.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-3">{{ $invoices->links() }}</div>
        </div>
    </div>
</x-settings-shell>
