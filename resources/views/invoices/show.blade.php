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

<x-settings-shell title="Invoice {{ $invoice->invoice_number }}">
    <div class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-3">
            <div class="bg-white shadow-sm sm:rounded-lg p-6 lg:col-span-2">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-sm text-gray-500">{{ $invoice->clinic?->name }}</p>
                        <h2 class="text-xl font-semibold text-gray-900">{{ $invoice->invoice_number }}</h2>
                    </div>
                    <span class="inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ $statusLabels[$invoice->status] ?? $invoice->status }}</span>
                </div>

                <dl class="mt-5 grid gap-4 sm:grid-cols-3 text-sm">
                    <div><dt class="text-gray-500">Tanggal Invoice</dt><dd class="font-medium text-gray-900">{{ optional($invoice->invoice_date)->format('Y-m-d') }}</dd></div>
                    <div><dt class="text-gray-500">Tanggal Jatuh Tempo</dt><dd class="font-medium text-gray-900">{{ optional($invoice->due_date)->format('Y-m-d') ?? '-' }}</dd></div>
                    <div><dt class="text-gray-500">Dibuat Oleh</dt><dd class="font-medium text-gray-900">{{ $invoice->creator?->name ?? '-' }}</dd></div>
                </dl>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Subtotal</dt><dd class="font-medium">{{ number_format((float) $invoice->subtotal, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Diskon</dt><dd class="font-medium">{{ number_format((float) $invoice->discount_amount, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Pajak</dt><dd class="font-medium">{{ number_format((float) $invoice->tax_amount, 2) }}</dd></div>
                    <div class="flex justify-between border-t pt-2"><dt class="text-gray-900">Total</dt><dd class="font-semibold">{{ number_format((float) $invoice->total_amount, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Dibayar</dt><dd class="font-medium">{{ number_format((float) $invoice->paid_amount, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-900">Tertunggak</dt><dd class="font-semibold">{{ number_format((float) $invoice->outstanding_amount, 2) }}</dd></div>
                </dl>
            </div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800">Item Invoice</h3>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead><tr class="text-left text-gray-500">
                        <th class="px-3 py-2 font-medium">No. Order</th>
                        <th class="px-3 py-2 font-medium">Pasien</th>
                        <th class="px-3 py-2 font-medium">Deskripsi</th>
                        <th class="px-3 py-2 font-medium text-right">Qty</th>
                        <th class="px-3 py-2 font-medium text-right">Harga Satuan</th>
                        <th class="px-3 py-2 font-medium text-right">Total</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($invoice->items as $item)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $item->labOrder?->order_number }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $item->labOrder?->patient?->name ?? '-' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $item->description }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ $item->quantity }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ number_format((float) $item->unit_price, 2) }}</td>
                                <td class="px-3 py-2 text-right text-gray-700">{{ number_format((float) $item->total_price, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Riwayat Pembayaran</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead><tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">No. Pembayaran</th>
                            <th class="px-3 py-2 font-medium">Tanggal</th>
                            <th class="px-3 py-2 font-medium">Metode</th>
                            <th class="px-3 py-2 font-medium text-right">Jumlah</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($invoice->payments as $payment)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-gray-900">{{ $payment->payment_number }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ optional($payment->payment_date)->format('Y-m-d') }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ $payment->payment_method }}</td>
                                    <td class="px-3 py-2 text-right text-gray-700">{{ number_format((float) $payment->amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-3 py-6 text-center text-gray-400">Belum ada pembayaran tercatat.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="font-semibold text-gray-800">Aksi</h3>

                @can('issue', $invoice)
                    <form method="POST" action="{{ route('invoices.issue', $invoice) }}" class="space-y-2">
                        @csrf
                        <textarea name="notes" rows="2" placeholder="Catatan penerbitan" class="w-full rounded-md border-gray-300 text-sm"></textarea>
                        <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Terbitkan Invoice</button>
                    </form>
                @endcan

                @can('void', $invoice)
                    <form method="POST" action="{{ route('invoices.void', $invoice) }}" class="space-y-2">
                        @csrf
                        <textarea name="notes" rows="2" placeholder="Alasan void" class="w-full rounded-md border-gray-300 text-sm" required></textarea>
                        <button class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-500">Void Invoice</button>
                    </form>
                @endcan

                @canany(['create_payment', 'manage_payment'])
                    @if (in_array($invoice->status, ['ISSUED', 'PARTIALLY_PAID', 'OVERDUE'], true) && (float) $invoice->outstanding_amount > 0)
                        <form method="POST" action="{{ route('invoices.payments.store', $invoice) }}" class="space-y-3 border-t pt-4">
                            @csrf
                            <div class="grid gap-3 sm:grid-cols-2">
                                <input type="date" name="payment_date" value="{{ now()->toDateString() }}" class="rounded-md border-gray-300 text-sm">
                                <select name="payment_method" class="rounded-md border-gray-300 text-sm">
                                    <option value="CASH">CASH</option>
                                    <option value="BANK_TRANSFER">BANK_TRANSFER</option>
                                    <option value="QRIS">QRIS</option>
                                    <option value="CARD">CARD</option>
                                    <option value="OTHER">OTHER</option>
                                </select>
                            </div>
                            <input type="number" step="0.01" min="0.01" max="{{ $invoice->outstanding_amount }}" name="amount" placeholder="Jumlah" class="w-full rounded-md border-gray-300 text-sm">
                            <input type="text" name="reference_number" placeholder="Nomor referensi" class="w-full rounded-md border-gray-300 text-sm">
                            <textarea name="notes" rows="2" placeholder="Catatan pembayaran" class="w-full rounded-md border-gray-300 text-sm"></textarea>
                            <button class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500">Catat Pembayaran</button>
                        </form>
                    @endif
                @endcanany
            </div>
        </div>
    </div>
</x-settings-shell>
