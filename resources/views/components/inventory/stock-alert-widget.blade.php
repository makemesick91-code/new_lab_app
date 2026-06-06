@props([
    'items' => [],
    'href' => null,
    'limit' => 8,
])

@php($items = collect($items)->take($limit))

<x-inventory.dashboard-section title="Peringatan Stok" description="Produk dengan stok habis, kritis, atau rendah untuk cabang ini." :action-href="$href" action-label="Lihat peringatan">
    @if ($items->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-10 text-center">
            <p class="text-sm font-medium text-gray-900">Tidak ada peringatan stok aktif.</p>
            <p class="mt-1 text-sm text-gray-500">Stok habis, kritis, dan rendah akan muncul di sini saat perlu perhatian.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-3 py-2 font-medium">Produk</th>
                        <th scope="col" class="px-3 py-2 font-medium text-right">Saat Ini</th>
                        <th scope="col" class="px-3 py-2 font-medium text-right">Titik Pesan</th>
                        <th scope="col" class="px-3 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @foreach ($items as $alert)
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2">
                                <div class="font-medium text-gray-900">{{ $alert['product_name'] }}</div>
                                <div class="text-xs text-gray-500">{{ $alert['product_code'] }}</div>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ format_quantity_id((float) $alert['current_stock']) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ format_quantity_id((float) $alert['effective_reorder_point']) }}</td>
                            <td class="px-3 py-2">@include('inventory.alerts._stock-severity-badge', ['severity' => $alert['severity']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-inventory.dashboard-section>
