@php
    $supplierPerformance = collect($supplierPerformance ?? []);
@endphp

<section id="section-supplier" class="rounded-lg border border-gray-200 bg-white shadow-sm scroll-mt-24">
    <div class="border-b border-gray-200 px-4 py-3">
        <h2 class="text-base font-semibold text-gray-900">Kinerja Supplier</h2>
        <p class="text-sm text-gray-500">PO, penerimaan, fulfillment, dan ketepatan waktu supplier cabang aktif.</p>
    </div>
    @if ($supplierPerformance->isEmpty())
        @include('inventory.analytics._empty-state')
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">Supplier</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">PO</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Nilai PO</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Diterima</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Fulfillment</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">On-Time</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Lead Time</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($supplierPerformance as $supplier)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $supplier['supplier_name'] ?? '—' }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_number_id((int) ($supplier['order_count'] ?? $supplier['po_count'] ?? 0)) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id((float) ($supplier['order_value'] ?? 0)) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id((float) ($supplier['received_value'] ?? 0)) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">
                                {{ isset($supplier['fulfillment_rate']) ? number_format((float) $supplier['fulfillment_rate'], 1, ',', '.').'%' : '—' }}
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">
                                {{ isset($supplier['on_time_delivery_rate']) ? number_format((float) $supplier['on_time_delivery_rate'], 1, ',', '.').'%' : (isset($supplier['on_time_rate']) ? number_format((float) $supplier['on_time_rate'], 1, ',', '.').'%' : '—') }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-gray-700">
                                {{ isset($supplier['avg_lead_time_days']) ? format_number_id((float) $supplier['avg_lead_time_days']).' hari' : '—' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
