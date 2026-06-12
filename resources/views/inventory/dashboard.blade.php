<x-settings-shell title="Dashboard Inventory">
    <div class="space-y-6">
        <x-ui.card padding="p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Persediaan Inti</p>
                    <h1 class="mt-1 text-2xl font-semibold text-gray-900">Visibilitas stok untuk cabang aktif</h1>
                    <p class="mt-2 max-w-3xl text-sm text-gray-600">
                        Stok dihitung dari ledger pergerakan persediaan berdasarkan cabang, lokasi, dan produk. Gunakan dasbor ini untuk melihat stok menipis, memeriksa riwayat pergerakan, dan masuk ke operasi stok dengan aman.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button variant="neutral" :href="route('inventory.stock.index')">Buka Stok</x-ui.button>
                    <x-ui.button variant="secondary" :href="route('inventory.products.index')">Produk</x-ui.button>
                    @can('viewAny', \App\Modules\Inventory\Models\InventoryMovement::class)
                        <x-ui.button variant="primary" :href="route('inventory.analytics.index')">Analitik Persediaan</x-ui.button>
                    @endcan
                </div>
            </div>
        </x-ui.card>

        <section aria-labelledby="inventory-kpis">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 id="inventory-kpis" class="text-base font-semibold text-gray-900">Kartu KPI Persediaan</h3>
                <p class="text-xs text-gray-500">Ringkasan cabang berbasis ledger</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <x-inventory.kpi-card
                    label="Total Nilai Persediaan"
                    :value="format_currency_id($summary['inventory_value'])"
                    hint="Nilai stok cabang saat ini"
                    tone="primary"
                    :href="route('inventory.stock.index')"
                />
                <x-inventory.kpi-card
                    label="Stok Kritis"
                    :value="format_number_id((int) $alertSummary['critical_stock_count'])"
                    hint="Di bawah stok minimum, di atas nol"
                    tone="warning"
                    :href="route('inventory.alerts.index', ['severity' => 'critical'])"
                />
                <x-inventory.kpi-card
                    label="Stok Habis"
                    :value="format_number_id((int) $alertSummary['out_of_stock_count'])"
                    hint="Stok saat ini nol atau kurang"
                    tone="danger"
                    :href="route('inventory.alerts.index', ['severity' => 'out_of_stock'])"
                />
                <x-inventory.kpi-card
                    label="Stok Rendah"
                    :value="format_number_id((int) $alertSummary['low_stock_count'])"
                    hint="Pada atau di bawah titik pesan ulang"
                    tone="warning"
                    :href="route('inventory.alerts.index', ['severity' => 'low'])"
                />
                <x-inventory.kpi-card
                    label="Batch Kedaluwarsa"
                    :value="format_number_id((int) $alertSummary['batch_expired_count'])"
                    hint="Batch kedaluwarsa dengan stok tersisa"
                    tone="danger"
                    :href="route('inventory.alerts.index', ['severity' => 'batch_expired'])"
                />
                <x-inventory.kpi-card
                    label="Segera Kedaluwarsa"
                    :value="format_number_id((int) $alertSummary['batch_expiring_soon_count'])"
                    hint="Kedaluwarsa dalam 30 hari"
                    tone="warning"
                    :href="route('inventory.alerts.index', ['severity' => 'batch_expiring_soon'])"
                />
            </div>
        </section>

        <x-inventory.quick-actions-panel />

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <x-inventory.dashboard-section
                title="Stok per Lokasi"
                description="Nilai dan jumlah persediaan dikelompokkan berdasarkan lokasi persediaan fisik."
                :action-href="route('inventory.stock.index')"
                action-label="Lihat stok"
            >
                @if ($stockByLocation->isEmpty())
                    <div class="rounded-lg border border-dashed border-gray-200 px-4 py-10 text-center">
                        <p class="text-sm font-medium text-gray-900">Belum ada pergerakan stok.</p>
                        <p class="mt-1 text-sm text-gray-500">Stok awal atau penerimaan stok akan membuat ringkasan lokasi.</p>
                        @if ($locations->isNotEmpty())
                            <p class="mt-2 text-xs text-gray-500">{{ format_number_id($locations->count()) }} lokasi aktif siap untuk operasi stok.</p>
                        @endif
                    </div>
                @else
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($stockByLocation as $location)
                            <x-inventory.location-card :location="$location" />
                        @endforeach
                    </div>
                @endif
            </x-inventory.dashboard-section>

            <x-inventory.stock-alert-widget
                :items="$stockAlerts"
                :href="route('inventory.alerts.index')"
            />
        </div>

        <x-inventory.batch-alert-widget
            :items="$batchAlerts"
            :href="route('inventory.alerts.index', ['type' => 'batch'])"
        />

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <x-inventory.movement-timeline
                :movements="$recentMovements"
                :href="route('inventory.stock.index')"
            />

            <x-inventory.dashboard-section title="Material Paling Banyak Dipakai" description="Insight penggunaan produksi untuk sprint berikutnya." density="compact">
                <div class="rounded-lg border border-dashed border-gray-200 px-4 py-10 text-center">
                    <p class="text-sm font-medium text-gray-900">Akan tersedia pada sprint berikutnya.</p>
                    <p class="mt-1 text-sm text-gray-500">Penggunaan produksi berada di luar scope Persediaan Inti, jadi widget ini sengaja dikosongkan.</p>
                </div>
            </x-inventory.dashboard-section>
        </div>
    </div>
</x-settings-shell>
