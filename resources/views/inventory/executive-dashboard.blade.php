@php
    $cards = $dashboard['cards'] ?? [];
    $sections = $dashboard['sections'] ?? [];
    $meta = $dashboard['meta'] ?? [];
    $purchaseTrend = $sections['trends']['purchase_trend'] ?? [];
    $consumptionTrend = $sections['trends']['consumption_trend'] ?? [];
    $fastMoving = collect($sections['movement']['fast_moving'] ?? []);
    $slowMoving = collect($sections['movement']['slow_moving'] ?? []);
    $deadStock = collect($sections['movement']['dead_stock'] ?? []);
    $stockAging = $sections['valuation']['stock_aging'] ?? [];
    $agingBuckets = $stockAging['buckets'] ?? [];
    $suppliers = collect($sections['supplier']['supplier_performance'] ?? []);
    $reorders = collect($sections['reorder']['reorder_recommendations'] ?? []);
@endphp

<x-settings-shell title="Dasbor Eksekutif Persediaan">
    <div class="space-y-6">
        <x-ui.page-header
            title="Inventory Executive Dashboard"
            subtitle="Ringkasan eksekutif persediaan dan procurement untuk cabang aktif. Semua angka dihitung dari ledger pergerakan dan data procurement."
        >
            <x-slot:breadcrumb>Persediaan / Dasbor Eksekutif</x-slot:breadcrumb>
            <x-slot:actions>
                @if (Route::has('inventory.analytics.index'))
                    <x-ui.button :href="route('inventory.analytics.index')" variant="ghost">Analitik Lengkap</x-ui.button>
                @endif
                @if (Route::has('inventory.dashboard'))
                    <x-ui.button :href="route('inventory.dashboard')" variant="secondary">Dasbor Operasional</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>

        @if (! empty($meta['generated_at']))
            <p class="-mt-3 text-xs text-ink-soft">Dihasilkan: {{ $meta['generated_at']->timezone(config('app.timezone'))->format('d M Y H:i') }}</p>
        @endif

        <x-ui.alert variant="info">
            <div class="space-y-2">
                <p><span class="font-semibold">Operational valuation:</span> Operational inventory value based on current stock × average cost. Not accounting valuation.</p>
                <p><span class="font-semibold">Consumption:</span> Consumption includes all outbound inventory movements.</p>
                <p><span class="font-semibold">Supplier on-time:</span> On-time delivery is calculated only from purchase orders with expected delivery dates.</p>
            </div>
        </x-ui.alert>

        <section aria-labelledby="executive-kpis">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 id="executive-kpis" class="text-base font-semibold text-gray-900">KPI Eksekutif</h2>
                <p class="text-xs text-gray-500">9 indikator utama cabang aktif</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($cards as $card)
                    <x-inventory.kpi-card
                        :label="$card['label']"
                        :value="$card['display_value']"
                        :hint="$card['note'] ?? $card['empty_state']"
                        :tone="$card['tone']"
                        :href="$card['href'] ?? null"
                    />
                @endforeach
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-inventory.dashboard-section
                title="Purchase Trend"
                description="Tren pembelian bulanan — PO, GR, dan ledger PURCHASE."
            >
                @if (empty($purchaseTrend))
                    <p class="text-sm text-gray-500">Belum ada data tren pembelian untuk periode ini.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-600">Bulan</th>
                                    <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">PO</th>
                                    <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Nilai PO</th>
                                    <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">GR</th>
                                    <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Nilai GR</th>
                                    <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Ledger</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @foreach ($purchaseTrend as $row)
                                    <tr>
                                        <td class="px-3 py-2 text-gray-900">{{ $row['period'] }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ format_number_id((int) ($row['po_count'] ?? 0)) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ format_currency_id((float) ($row['po_value'] ?? 0)) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ format_number_id((int) ($row['gr_count'] ?? 0)) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ format_currency_id((float) ($row['gr_received_value'] ?? 0)) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ format_currency_id((float) ($row['ledger_purchase_value'] ?? 0)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-inventory.dashboard-section>

            <x-inventory.dashboard-section
                title="Consumption Trend"
                description="Tren konsumsi bulanan — seluruh pergerakan keluar (quantity_out)."
            >
                @if (empty($consumptionTrend))
                    <p class="text-sm text-gray-500">Belum ada data tren konsumsi untuk periode ini.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-600">Bulan</th>
                                    <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Qty Keluar</th>
                                    <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Nilai Keluar</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                @foreach ($consumptionTrend as $row)
                                    <tr>
                                        <td class="px-3 py-2 text-gray-900">{{ $row['period'] }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ format_number_id((float) ($row['outbound_qty'] ?? 0)) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ format_currency_id((float) ($row['outbound_value'] ?? 0)) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-inventory.dashboard-section>
        </div>

        <section aria-labelledby="movement-intel" class="space-y-4">
            <h2 id="movement-intel" class="text-base font-semibold text-gray-900">Movement Intelligence</h2>
            <div class="grid gap-6 lg:grid-cols-3">
                <x-inventory.dashboard-section title="Fast Moving Items" description="Produk dengan keluaran tertinggi." density="compact">
                    @if ($fastMoving->isEmpty())
                        <p class="text-sm text-gray-500">Belum ada data pergerakan cepat.</p>
                    @else
                        <ul class="space-y-2 text-sm">
                            @foreach ($fastMoving as $item)
                                <li class="rounded-md border border-gray-100 px-3 py-2">
                                    <p class="font-medium text-gray-900">{{ $item['product_name'] }}</p>
                                    <p class="mt-1 text-xs text-gray-500 tabular-nums">
                                        Keluar: {{ format_number_id((float) ($item['outbound_qty_period'] ?? 0)) }}
                                        · Stok: {{ format_number_id((float) ($item['current_stock'] ?? 0)) }}
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-inventory.dashboard-section>

                <x-inventory.dashboard-section title="Slow Moving Items" description="Stok positif dengan keluaran rendah." density="compact">
                    @if ($slowMoving->isEmpty())
                        <p class="text-sm text-gray-500">Belum ada data pergerakan lambat.</p>
                    @else
                        <ul class="space-y-2 text-sm">
                            @foreach ($slowMoving as $item)
                                <li class="rounded-md border border-gray-100 px-3 py-2">
                                    <p class="font-medium text-gray-900">{{ $item['product_name'] }}</p>
                                    <p class="mt-1 text-xs text-gray-500 tabular-nums">
                                        Keluar: {{ format_number_id((float) ($item['outbound_qty_period'] ?? 0)) }}
                                        · Stok: {{ format_number_id((float) ($item['current_stock'] ?? 0)) }}
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-inventory.dashboard-section>

                <x-inventory.dashboard-section title="Dead Stock Items" description="Stok tanpa keluaran dalam periode dead stock." density="compact">
                    @if ($deadStock->isEmpty())
                        <p class="text-sm text-gray-500">Tidak ada stok mati terdeteksi.</p>
                    @else
                        <ul class="space-y-2 text-sm">
                            @foreach ($deadStock as $item)
                                <li class="rounded-md border border-gray-100 px-3 py-2">
                                    <p class="font-medium text-gray-900">{{ $item['product_name'] }}</p>
                                    <p class="mt-1 text-xs text-gray-500 tabular-nums">
                                        Stok: {{ format_number_id((float) ($item['current_stock'] ?? 0)) }}
                                        · Nilai: {{ format_currency_id((float) ($item['stock_value'] ?? 0)) }}
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-inventory.dashboard-section>
            </div>
        </section>

        <x-inventory.dashboard-section
            title="Stock Aging"
            description="Distribusi umur persediaan berdasarkan bucket aging."
        >
            @if (empty($agingBuckets))
                <p class="text-sm text-gray-500">Belum ada data penuaan persediaan.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-600">Bucket</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">SKU</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Qty</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Nilai</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($agingBuckets as $bucket)
                                <tr>
                                    <td class="px-3 py-2 text-gray-900">{{ $bucket['label'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ format_number_id((int) ($bucket['product_count'] ?? 0)) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ format_number_id((float) ($bucket['total_qty'] ?? 0)) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ format_currency_id((float) ($bucket['total_value'] ?? 0)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-inventory.dashboard-section>

        <x-inventory.dashboard-section
            title="Supplier Performance"
            description="Kinerja supplier berdasarkan PO, penerimaan, dan ketepatan waktu."
        >
            @if ($suppliers->isEmpty())
                <p class="text-sm text-gray-500">Belum ada data supplier.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-600">Supplier</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">PO</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Diterima</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Fulfillment</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">On-Time</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Coverage</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Lead Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($suppliers as $supplier)
                                <tr>
                                    <td class="px-3 py-2 text-gray-900">{{ $supplier['supplier_name'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ format_number_id((int) ($supplier['order_count'] ?? $supplier['po_count'] ?? 0)) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ format_currency_id((float) ($supplier['received_value'] ?? 0)) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">
                                        {{ isset($supplier['fulfillment_rate']) ? number_format((float) $supplier['fulfillment_rate'], 1, ',', '.').'%' : '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">
                                        {{ isset($supplier['on_time_delivery_rate']) ? number_format((float) $supplier['on_time_delivery_rate'], 1, ',', '.').'%' : (isset($supplier['on_time_rate']) ? number_format((float) $supplier['on_time_rate'], 1, ',', '.').'%' : '—') }}
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ number_format((float) ($supplier['coverage_percentage'] ?? 0), 1, ',', '.') }}%</td>
                                    <td class="px-3 py-2 text-right tabular-nums">
                                        {{ isset($supplier['avg_lead_time_days']) ? format_number_id((float) $supplier['avg_lead_time_days']).' hari' : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-inventory.dashboard-section>

        <x-inventory.dashboard-section
            title="Reorder Recommendations"
            description="Produk yang perlu segera dipesan ulang berdasarkan reorder point."
        >
            @if ($reorders->isEmpty())
                <p class="text-sm text-gray-500">Tidak ada rekomendasi reorder saat ini.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-600">Produk</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Stok</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Reorder Point</th>
                                <th scope="col" class="px-3 py-2 text-right font-semibold text-gray-600">Qty Saran</th>
                                <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-600">Severity</th>
                                <th scope="col" class="px-3 py-2 text-left font-semibold text-gray-600">Supplier</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 bg-white">
                            @foreach ($reorders as $reorder)
                                <tr>
                                    <td class="px-3 py-2 text-gray-900">{{ $reorder['product_name'] }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ format_number_id((float) ($reorder['current_stock'] ?? 0)) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ format_number_id((float) ($reorder['reorder_point'] ?? 0)) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ format_number_id((float) ($reorder['suggested_order_qty'] ?? 0)) }}</td>
                                    <td class="px-3 py-2">
                                        @php $sev = $reorder['severity'] ?? 'watch'; @endphp
                                        <x-ui.badge :tone="$sev === 'critical' ? 'danger' : ($sev === 'low' ? 'warning' : 'info')">{{ ucfirst($sev) }}</x-ui.badge>
                                    </td>
                                    <td class="px-3 py-2 text-gray-600">
                                        @if (! empty($reorder['preferred_supplier_name']))
                                            {{ $reorder['preferred_supplier_name'] }}
                                        @elseif (! empty($reorder['preferred_supplier_id']))
                                            #{{ $reorder['preferred_supplier_id'] }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-inventory.dashboard-section>
    </div>
</x-settings-shell>
