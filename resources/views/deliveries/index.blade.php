@php
    $statusLabels = [
        'READY_FOR_DELIVERY' => 'Siap Dikirim',
        'IN_DELIVERY' => 'Dalam Pengiriman',
        'DELIVERED' => 'Terkirim',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
    ];
    $priorityLabels = [
        'NORMAL' => 'Normal',
        'URGENT' => 'Mendesak',
        'SUPER_URGENT' => 'Sangat Mendesak',
    ];
@endphp

<x-settings-shell title="Antrean Pengiriman">
    <x-ui.page-header title="Antrean Pengiriman" subtitle="Kelola pembuatan dan pemantauan pengiriman order lab." />

    <x-ui.filter-bar :action="route('deliveries.index')">
        <x-ui.input name="search" :value="$filters['search']" placeholder="No. order, no. pengiriman, klinik, dokter, pasien" label="Cari" class="md:w-80" />
        <x-ui.select name="status" label="Status Pengiriman">
            <option value="">Semua status pengiriman</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select name="clinic_id" label="Klinik">
            <option value="">Semua klinik</option>
            @foreach ($clinics as $clinic)
                <option value="{{ $clinic->id }}" @selected($filters['clinic_id'] === $clinic->id)>{{ $clinic->name }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select name="courier_id" label="Kurir">
            <option value="">Semua kurir</option>
            @foreach ($couriers as $courier)
                <option value="{{ $courier->id }}" @selected($filters['courier_id'] === $courier->id)>{{ $courier->name }}</option>
            @endforeach
        </x-ui.select>
        <x-slot:actions>
            <x-ui.button type="submit">Terapkan</x-ui.button>
            <x-ui.button variant="secondary" :href="route('deliveries.index')">Atur Ulang</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <div class="space-y-6">
        @canany(['create_delivery', 'manage_delivery'])
            <x-ui.card title="Siap Diproses">
                <x-ui.table class="mt-1">
                    <thead class="bg-navy-50 text-ink"><tr>
                        <th class="px-3 py-2 text-left font-medium">No. Order</th>
                        <th class="px-3 py-2 text-left font-medium">Klinik</th>
                        <th class="px-3 py-2 text-left font-medium">Pasien</th>
                        <th class="px-3 py-2 text-left font-medium">Prioritas</th>
                        <th class="px-3 py-2 text-left font-medium">Kurir</th>
                        <th class="px-3 py-2 text-right font-medium">Aksi</th>
                    </tr></thead>
                    <tbody class="divide-y divide-hairline">
                        @forelse ($readyOrders as $order)
                            <tr class="transition-colors hover:bg-navy-50">
                                <td class="px-3 py-2 font-medium text-navy">{{ $order->order_number }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ $order->clinic?->name }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ $order->patient?->name ?? '-' }}</td>
                                <td class="px-3 py-2"><x-lab.status-badge :status="$order->priority" /></td>
                                <td class="px-3 py-2">
                                    <form id="create-delivery-{{ $order->id }}" method="POST" action="{{ route('deliveries.store') }}" class="flex items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="lab_order_id" value="{{ $order->id }}">
                                        <select name="courier_id" class="rounded-lg border-hairline text-xs focus:border-brand-500 focus:ring-brand-500">
                                            <option value="">Tugaskan nanti</option>
                                            @foreach ($couriers as $courier)
                                                <option value="{{ $courier->id }}">{{ $courier->name }}</option>
                                            @endforeach
                                        </select>
                                        <input type="text" name="delivery_notes" placeholder="Catatan" class="rounded-lg border-hairline text-xs focus:border-brand-500 focus:ring-brand-500">
                                    </form>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <x-ui.button form="create-delivery-{{ $order->id }}" type="submit" size="sm">Buat Pengiriman</x-ui.button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-3 py-6">
                                    <x-ui.empty-state title="Belum ada order siap dikirim" description="Order yang lulus QC dan menunggu pengiriman akan tampil di sini." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </x-ui.table>
            </x-ui.card>
        @endcanany

        <x-ui.card title="Pengiriman Aktif">
            <x-ui.table class="mt-1">
                <thead class="bg-navy-50 text-ink"><tr>
                    <th class="px-3 py-2 text-left font-medium">No. Pengiriman</th>
                    <th class="px-3 py-2 text-left font-medium">No. Order</th>
                    <th class="px-3 py-2 text-left font-medium">Klinik</th>
                    <th class="px-3 py-2 text-left font-medium">Kurir</th>
                    <th class="px-3 py-2 text-left font-medium">Status</th>
                    <th class="px-3 py-2 text-left font-medium">Tenggat</th>
                    <th class="px-3 py-2 text-right font-medium">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-hairline">
                    @forelse ($deliveries as $delivery)
                        <tr class="transition-colors hover:bg-navy-50">
                            <td class="px-3 py-2 font-medium text-navy">{{ $delivery->delivery_number }}</td>
                            <td class="px-3 py-2 text-ink-soft">{{ $delivery->labOrder?->order_number }}</td>
                            <td class="px-3 py-2 text-ink-soft">{{ $delivery->labOrder?->clinic?->name }}</td>
                            <td class="px-3 py-2 text-ink-soft">{{ $delivery->courier?->name ?? '-' }}</td>
                            <td class="px-3 py-2"><x-lab.status-badge :status="$delivery->status" /></td>
                            <td class="px-3 py-2 text-ink-soft">{{ format_date_id($delivery->labOrder?->due_date) }}</td>
                            <td class="px-3 py-2 text-right">
                                <x-ui.button size="sm" variant="secondary" :href="route('deliveries.show', $delivery)">Lihat Detail</x-ui.button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-3 py-6">
                                <x-ui.empty-state title="Belum ada data pengiriman" description="Pengiriman yang dibuat akan tampil di sini." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>
            <div class="mt-3">{{ $deliveries->links() }}</div>
        </x-ui.card>
    </div>
</x-settings-shell>
