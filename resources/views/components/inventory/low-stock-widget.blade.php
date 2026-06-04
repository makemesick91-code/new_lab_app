@props([
    'items' => [],
    'href' => null,
    'limit' => 8,
])

@php($items = collect($items)->take($limit))

<x-inventory.dashboard-section title="Low Stock Products" description="Products at or below minimum stock for this branch." :action-href="$href" action-label="Open stock">
    @if ($items->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-10 text-center">
            <p class="text-sm font-medium text-gray-900">No low stock products.</p>
            <p class="mt-1 text-sm text-gray-500">Low and out-of-stock materials will appear here when they need attention.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-3 py-2 font-medium">Code</th>
                        <th scope="col" class="px-3 py-2 font-medium">Product</th>
                        <th scope="col" class="px-3 py-2 font-medium text-right">Current</th>
                        <th scope="col" class="px-3 py-2 font-medium text-right">Minimum</th>
                        <th scope="col" class="px-3 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @foreach ($items as $product)
                        @php($current = (float) $product->current_stock)
                        @php($minimum = (float) $product->minimum_stock)
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 text-gray-600">{{ $product->code }}</td>
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $product->name }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ number_format($current, 2) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums text-gray-700">{{ number_format($minimum, 2) }}</td>
                            <td class="px-3 py-2">@include('inventory._low-stock-badge', ['current' => $current, 'minimum' => $minimum])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-inventory.dashboard-section>
