@php
    $purchaseTrend = $purchaseTrend ?? [];
@endphp

<section id="section-procurement" class="rounded-lg border border-gray-200 bg-white shadow-sm scroll-mt-24">
    <div class="border-b border-gray-200 px-4 py-3">
        <h2 class="text-base font-semibold text-gray-900">Tren Procurement</h2>
        <p class="text-sm text-gray-500">PO dibuat, GR diposting, dan nilai pembelian ledger per bulan.</p>
    </div>
    @if (empty($purchaseTrend))
        @include('inventory.analytics._empty-state')
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">Periode</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">PO</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Nilai PO</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">GR</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Ledger Purchase</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($purchaseTrend as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ format_month_id($row['period'] ?? $row['month'] ?? '') }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_number_id((int) ($row['po_count'] ?? 0)) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row['po_value'] ?? 0) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_number_id((int) ($row['gr_count'] ?? 0)) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row['ledger_purchase_value'] ?? 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
