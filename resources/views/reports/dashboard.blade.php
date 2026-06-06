@php
    $orderStatusLabels = [
        'DRAFT' => 'Draft',
        'RECEIVED' => 'Diterima',
        'ASSIGNED' => 'Ditugaskan',
        'IN_PRODUCTION' => 'Dalam Produksi',
        'ON_HOLD' => 'Dijeda',
        'QC_PENDING' => 'Menunggu QC',
        'QC_PASSED' => 'QC Lulus',
        'READY_FOR_DELIVERY' => 'Siap Dikirim',
        'IN_DELIVERY' => 'Dalam Pengiriman',
        'DELIVERED' => 'Terkirim',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
        'REMAKE' => 'Perbaikan',
    ];
    $deliveryStatusLabels = [
        'READY_FOR_DELIVERY' => 'Siap Dikirim',
        'IN_DELIVERY' => 'Dalam Pengiriman',
        'DELIVERED' => 'Terkirim',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
    ];
    $qcResultLabels = [
        'PASSED' => 'Lulus',
        'REJECTED' => 'Ditolak',
        'REVISION' => 'Perlu Revisi',
        'IN_REVIEW' => 'Dalam Peninjauan',
    ];
    $paymentMethodLabels = [
        'CASH' => 'Tunai',
        'BANK_TRANSFER' => 'Transfer Bank',
        'QRIS' => 'QRIS',
        'CARD' => 'Kartu',
        'OTHER' => 'Lainnya',
    ];
@endphp

<x-settings-shell title="Dasbor Laporan">
    <div class="space-y-6">
        {{-- Filter --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <form method="GET" action="{{ route('reports.dashboard') }}" class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="block text-xs text-gray-500">Dari</label>
                    <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Sampai</label>
                    <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}" class="mt-1 rounded-md border-gray-300 text-sm" />
                </div>
                <div>
                    <label class="block text-xs text-gray-500">Klinik</label>
                    <select name="clinic_id" class="mt-1 rounded-md border-gray-300 text-sm">
                        <option value="">Semua klinik</option>
                        @foreach ($clinics as $clinic)
                            <option value="{{ $clinic->id }}" @selected(($filters['clinic_id'] ?? null) == $clinic->id)>{{ $clinic->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                <a href="{{ route('reports.dashboard') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
            </form>
        </div>

        {{-- Cards --}}
        @php
            $cardDefs = [
                'total_orders' => 'Total Order',
                'in_progress' => 'Dalam Produksi',
                'completed' => 'Selesai',
                'delivered' => 'Terkirim',
                'pending_qc' => 'Menunggu QC',
                'remake_count' => 'Perbaikan',
            ];
        @endphp
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($cardDefs as $key => $label)
                <div class="bg-white shadow-sm sm:rounded-lg p-4">
                    <p class="text-xs text-gray-500">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold text-gray-900">{{ format_number_id($cards[$key]) }}</p>
                </div>
            @endforeach
        </div>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Pendapatan</p>
                <p class="mt-1 text-2xl font-semibold text-green-700">{{ format_currency_id($cards['revenue']) }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Tertunggak</p>
                <p class="mt-1 text-2xl font-semibold text-amber-700">{{ format_currency_id($cards['outstanding']) }}</p>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <p class="text-xs text-gray-500">Invoice Terlambat</p>
                <p class="mt-1 text-2xl font-semibold text-red-700">{{ format_number_id($cards['overdue_invoices']) }}</p>
            </div>
        </div>

        {{-- Charts (compact tables) --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @php
                $chartDefs = [
                    'orders_by_status' => ['Order per Status', 'status', 'total'],
                    'orders_by_clinic' => ['Order per Klinik', 'clinic_name', 'total'],
                    'qc_summary' => ['Ringkasan QC', 'result', 'total'],
                    'delivery_summary' => ['Ringkasan Pengiriman', 'status', 'total'],
                    'payments_by_method' => ['Pembayaran per Metode', 'payment_method', 'amount'],
                    'revenue_by_month' => ['Pendapatan per Bulan', 'month', 'amount'],
                ];
            @endphp
            @foreach ($chartDefs as $key => $def)
                @php
                    [$title, $labelCol, $valCol] = $def;
                @endphp
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    <h3 class="font-semibold text-gray-800">{{ $title }}</h3>
                    <table class="mt-3 min-w-full text-sm">
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($charts[$key] as $row)
                                @php
                                    $rawLabel = $row->{$labelCol} ?? null;
                                    if ($labelCol === 'month') {
                                        $labelValue = format_month_id($rawLabel);
                                    } elseif ($key === 'orders_by_status') {
                                        $labelValue = $orderStatusLabels[$rawLabel] ?? ($rawLabel ?? '-');
                                    } elseif ($key === 'qc_summary') {
                                        $labelValue = $qcResultLabels[$rawLabel] ?? ($rawLabel ?? '-');
                                    } elseif ($key === 'delivery_summary') {
                                        $labelValue = $deliveryStatusLabels[$rawLabel] ?? ($rawLabel ?? '-');
                                    } elseif ($key === 'payments_by_method') {
                                        $labelValue = $paymentMethodLabels[$rawLabel] ?? ($rawLabel ?? '-');
                                    } else {
                                        $labelValue = $rawLabel ?? '-';
                                    }
                                @endphp
                                <tr>
                                    <td class="px-2 py-1 text-gray-700">{{ $labelValue }}</td>
                                    <td class="px-2 py-1 text-right font-medium text-gray-900">{{ $valCol === 'amount' ? format_currency_id($row->{$valCol}) : format_number_id((int) $row->{$valCol}) }}</td>
                                </tr>
                            @empty
                                <tr><td class="px-2 py-3 text-center text-gray-400">Belum ada data.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    </div>
</x-settings-shell>
