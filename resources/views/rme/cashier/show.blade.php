<x-settings-shell title="Detail Tagihan RME">
    @php
        $statusBadge = [
            'DRAFT'  => 'bg-gray-100 text-gray-700',
            'UNPAID' => 'bg-amber-50 text-amber-700',
            'PAID'   => 'bg-green-50 text-green-700',
            'VOID'   => 'bg-red-50 text-red-700',
        ];
    @endphp

    <div class="space-y-6">

        {{-- Invoice Header --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 font-mono">{{ $invoice->invoice_number }}</h3>
                    <p class="text-sm text-gray-500 mt-1">Dibuat oleh {{ $invoice->cashier?->name }} &mdash; {{ $invoice->created_at?->format('d/m/Y H:i') }}</p>
                </div>
                <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-semibold {{ $statusBadge[$invoice->status] ?? 'bg-gray-100 text-gray-600' }}">
                    {{ $invoice->status }}
                </span>
            </div>
        </div>

        {{-- Patient & Visit --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h4 class="text-sm font-semibold text-gray-700 mb-3">Informasi Kunjungan</h4>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-gray-500">No. Kunjungan</dt>
                    <dd class="font-mono font-medium text-gray-900">{{ $visit->visit_number }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Tanggal</dt>
                    <dd class="text-gray-900">{{ $visit->visit_date?->format('d/m/Y') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Pasien</dt>
                    <dd class="text-gray-900 font-medium">{{ $visit->patient?->name }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Dokter</dt>
                    <dd class="text-gray-900">{{ $visit->doctor?->name }}</dd>
                </div>
                @if ($invoice->medicalRecord)
                    <div>
                        <dt class="text-gray-500">Status RME</dt>
                        <dd>
                            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium bg-green-50 text-green-700">
                                {{ strtoupper($invoice->medicalRecord->status) }}
                            </span>
                        </dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Invoice Items --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h4 class="text-sm font-semibold text-gray-700 mb-3">Item Tagihan</h4>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Deskripsi</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-500">Treatment</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Qty</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Harga Satuan</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Diskon</th>
                            <th class="px-4 py-3 text-right font-medium text-gray-500">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($invoice->items as $item)
                            <tr>
                                <td class="px-4 py-3 text-gray-900">{{ $item->description }}</td>
                                <td class="px-4 py-3 text-gray-500 text-xs">{{ $item->treatment?->name ?? '-' }}</td>
                                <td class="px-4 py-3 text-right text-gray-700">{{ $item->qty }}</td>
                                <td class="px-4 py-3 text-right text-gray-700">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                                <td class="px-4 py-3 text-right text-red-600">{{ $item->discount > 0 ? 'Rp '.number_format($item->discount, 0, ',', '.') : '-' }}</td>
                                <td class="px-4 py-3 text-right font-medium text-gray-900">Rp {{ number_format($item->subtotal, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="5" class="px-4 py-2 text-right text-sm text-gray-600">Subtotal</td>
                            <td class="px-4 py-2 text-right font-medium text-gray-900">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td>
                        </tr>
                        <tr>
                            <td colspan="5" class="px-4 py-2 text-right text-sm text-red-600">Total Diskon</td>
                            <td class="px-4 py-2 text-right font-medium text-red-600">Rp {{ number_format($invoice->discount_total, 0, ',', '.') }}</td>
                        </tr>
                        <tr class="border-t-2 border-gray-300">
                            <td colspan="5" class="px-4 py-3 text-right text-base font-semibold text-gray-900">Grand Total</td>
                            <td class="px-4 py-3 text-right text-base font-bold text-indigo-700">Rp {{ number_format($invoice->grand_total, 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if ($invoice->notes)
                <div class="mt-4 text-sm text-gray-600">
                    <span class="font-medium">Catatan:</span> {{ $invoice->notes }}
                </div>
            @endif
        </div>

        {{-- Actions --}}
        <div class="flex items-center gap-3">
            <a href="{{ route('rme.cashier.index') }}"
                class="inline-flex items-center px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-md hover:bg-gray-200">
                &larr; Kembali ke Daftar
            </a>
            @if ($invoice->status === 'UNPAID')
                <a href="{{ route('rme.cashier.payment.create', [$visit, $invoice]) }}"
                    class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-md hover:bg-green-700">
                    Bayar Sekarang
                </a>
            @endif
            @if ($invoice->status === 'PAID')
                <a href="{{ route('rme.cashier.receipt.show', [$visit, $invoice]) }}"
                    class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700">
                    Lihat Kwitansi
                </a>
            @endif
        </div>
    </div>
</x-settings-shell>
