@props([
    'items' => [],
    'href' => null,
    'limit' => 5,
])

@php($items = collect($items)->take($limit))

<x-inventory.dashboard-section title="Peringatan Batch" description="Batch kedaluwarsa atau segera kedaluwarsa dengan stok tersisa." :action-href="$href" action-label="Lihat peringatan">
    @if ($items->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-10 text-center">
            <p class="text-sm font-medium text-gray-900">Tidak ada peringatan batch aktif.</p>
            <p class="mt-1 text-sm text-gray-500">Batch kedaluwarsa dan segera kedaluwarsa akan muncul di sini.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-3 py-2 font-medium">Batch</th>
                        <th scope="col" class="px-3 py-2 font-medium">Produk</th>
                        <th scope="col" class="px-3 py-2 font-medium">Kedaluwarsa</th>
                        <th scope="col" class="px-3 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @foreach ($items as $alert)
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 font-medium text-gray-900">{{ $alert['batch_number'] }}</td>
                            <td class="px-3 py-2 text-gray-700">{{ $alert['product_name'] }}</td>
                            <td class="px-3 py-2 tabular-nums text-gray-700">{{ $alert['expiry_date'] }}</td>
                            <td class="px-3 py-2">@include('inventory.alerts._stock-severity-badge', ['severity' => $alert['severity']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-inventory.dashboard-section>
