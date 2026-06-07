@php
    $reorderRecommendations = collect($reorderRecommendations ?? []);
@endphp

<section id="section-reorder" class="rounded-lg border border-gray-200 bg-white shadow-sm scroll-mt-24">
    <div class="border-b border-gray-200 px-4 py-3">
        <h2 class="text-base font-semibold text-gray-900">Rekomendasi Reorder</h2>
        <p class="text-sm text-gray-500">Produk di bawah reorder point cabang aktif.</p>
    </div>
    @if ($reorderRecommendations->isEmpty())
        @include('inventory.analytics._empty-state')
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Stok</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Reorder Point</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Qty Saran</th>
                        <th scope="col" class="px-3 py-3 font-medium">Severity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($reorderRecommendations as $row)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <p class="font-medium text-gray-900">{{ $row['product_name'] ?? '—' }}</p>
                                <p class="text-xs text-gray-500">{{ $row['product_code'] ?? '' }}</p>
                            </td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row['current_stock'] ?? 0) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row['reorder_point'] ?? 0) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-gray-700">{{ format_quantity_id($row['suggested_order_qty'] ?? 0) }}</td>
                            <td class="px-3 py-3 text-gray-700">{{ $row['severity'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
