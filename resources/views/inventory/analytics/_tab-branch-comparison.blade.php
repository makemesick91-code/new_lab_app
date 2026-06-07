@php
    $rows = collect($branchComparison ?? []);
@endphp

<section id="section-branch-comparison" class="rounded-lg border border-gray-200 bg-white shadow-sm scroll-mt-24">
    <div class="border-b border-gray-200 px-4 py-3">
        <h2 class="text-base font-semibold text-gray-900">Perbandingan Cabang</h2>
        <p class="text-sm text-gray-500">Snapshot KPI per cabang. Data summary mengikuti mode analytics aktif.</p>
    </div>

    @if ($rows->isEmpty())
        @include('inventory.analytics._empty-state')
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">Cabang</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Nilai Persediaan</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">SKU Aktif</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Stok Rendah</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Stok Mati</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Stok Habis</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Outstanding PO</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Akurasi</th>
                        <th scope="col" class="px-4 py-3 font-medium">Terakhir Refresh</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($rows as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $row['branch_name'] }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row['inventory_value'] ?? 0) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_number_id((int) ($row['active_sku_count'] ?? 0)) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_number_id((int) ($row['low_stock_count'] ?? 0)) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_number_id((int) ($row['dead_stock_count'] ?? 0)) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_number_id((int) ($row['out_of_stock_count'] ?? 0)) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_currency_id($row['open_po_outstanding_value'] ?? 0) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">
                                {{ ($row['inventory_accuracy_pct'] ?? null) !== null ? number_format((float) $row['inventory_accuracy_pct'], 1, ',', '.').'%' : '—' }}
                            </td>
                            <td class="px-4 py-3 text-gray-600">
                                {{ ! empty($row['refreshed_at']) ? format_datetime_id($row['refreshed_at']) : 'Belum ada data ringkasan' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
