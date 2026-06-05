@props([
    'items' => [],
    'href' => null,
])

@php($items = collect($items))

<x-branch-dashboard.dashboard-section title="Peringatan Persediaan" description="Item stok menipis dan habis di cabang aktif." :action-href="$href" action-label="Buka stok" density="compact">
    @if ($items->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-200 px-4 py-8 text-center">
            <p class="text-sm font-medium text-gray-900">Tidak ada item stok menipis</p>
            <p class="mt-1 text-sm text-gray-500">Peringatan persediaan akan tampil di sini saat data stok tersedia.</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach ($items as $item)
                <div class="flex items-start justify-between gap-3 rounded-lg border border-gray-200 p-3">
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ data_get($item, 'product', 'Produk') }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ data_get($item, 'location', 'Lokasi') }}</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-semibold tabular-nums text-amber-700">{{ format_quantity_id(data_get($item, 'current', 0)) }}</p>
                        <p class="text-xs text-gray-500">min {{ format_quantity_id(data_get($item, 'minimum', 0)) }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-branch-dashboard.dashboard-section>
