<x-settings-shell title="Stok Persediaan">
    <div class="space-y-6">
        <x-ui.page-header
            title="Saldo Stok Berbasis Ledger"
            subtitle="Stok dihitung dari pergerakan persediaan per lokasi dan produk.">
            <x-slot:breadcrumb>Persediaan / Stok</x-slot:breadcrumb>
        </x-ui.page-header>

        <x-ui.filter-bar :action="route('inventory.stock.index')">
            <div class="md:w-64">
                <x-ui.select label="Lokasi" id="inventory_location_id" name="inventory_location_id">
                    <option value="">Semua lokasi</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}" @selected(($filters['inventory_location_id'] ?? null) == $location->id)>{{ $location->name }}</option>
                    @endforeach
                </x-ui.select>
            </div>
            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                <x-ui.button variant="secondary" :href="route('inventory.stock.index')">Atur Ulang</x-ui.button>
            </x-slot:actions>
        </x-ui.filter-bar>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.kpi-card label="Nilai Persediaan" :value="format_currency_id($summary['inventory_value'])" />
            <x-ui.card padding="p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft">Stok Kritis</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-warning-700">{{ format_number_id((int) $alertSummary['critical_stock_count']) }}</p>
            </x-ui.card>
            <x-ui.card padding="p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft">Stok Rendah</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-warning-700">{{ format_number_id((int) $alertSummary['low_stock_count']) }}</p>
            </x-ui.card>
            <x-ui.card padding="p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft">Stok Habis</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-danger-700">{{ format_number_id((int) $alertSummary['out_of_stock_count']) }}</p>
            </x-ui.card>
        </div>

        <x-ui.card padding="">
            <div class="border-b border-hairline px-4 py-3">
                <h3 class="text-base font-semibold text-navy">Saldo per Produk & Lokasi</h3>
                <p class="text-sm text-ink-soft">Nilai persediaan = stok saat ini × biaya rata-rata produk.</p>
            </div>

            <x-ui.table class="!border-0 !shadow-none !rounded-none">
                <thead class="bg-navy-50">
                    <tr class="text-left text-ink-soft">
                        <th scope="col" class="px-4 py-3 font-medium">Produk</th>
                        <th scope="col" class="px-3 py-3 font-medium">Lokasi</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Stok Saat Ini</th>
                        <th scope="col" class="px-3 py-3 text-right font-medium">Nilai Persediaan</th>
                        <th scope="col" class="px-4 py-3 font-medium">Stok</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @forelse ($stockRows as $row)
                        @php($product = $row->product)
                        @php($current = (float) $row->current_stock)
                        <tr class="hover:bg-navy-50">
                            <td class="px-4 py-3 font-medium text-navy">{{ $product?->name ?? '-' }}</td>
                            <td class="px-3 py-3 text-ink-soft">{{ $row->inventoryLocation?->name ?? '-' }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-ink">{{ format_quantity_id($current) }}</td>
                            <td class="px-3 py-3 text-right tabular-nums text-ink">{{ format_currency_id($current * (float) ($product?->average_cost ?? 0)) }}</td>
                            <td class="px-4 py-3">@include('inventory._low-stock-badge', ['current' => $current, 'minimum' => $product?->minimum_stock ?? 0])</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12">
                                <x-ui.empty-state title="Belum ada pergerakan stok."
                                    description="Stok awal atau penerimaan stok akan membuat saldo muncul di sini." class="border-0 bg-transparent shadow-none" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>
        </x-ui.card>
    </div>
</x-settings-shell>
