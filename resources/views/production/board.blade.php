@php
    $priorityLabels = [
        'NORMAL' => 'Normal',
        'URGENT' => 'Mendesak',
        'SUPER_URGENT' => 'Sangat Mendesak',
    ];
    $statusLabels = [
        'RECEIVED' => 'Diterima',
        'ASSIGNED' => 'Ditugaskan',
        'IN_PRODUCTION' => 'Dalam Produksi',
        'ON_HOLD' => 'Dijeda',
        'QC_PENDING' => 'Menunggu QC',
        'QC_PASSED' => 'QC Lulus',
        'READY_FOR_DELIVERY' => 'Siap Dikirim',
        'IN_DELIVERY' => 'Dalam Pengiriman',
        'DELIVERED' => 'Terkirim',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
        'REMAKE' => 'Perbaikan',
    ];
@endphp

<x-settings-shell title="Papan Produksi">
    <x-ui.page-header title="Papan Produksi" subtitle="Pantau dan kelola order lab yang sedang diproduksi." />

    <x-ui.filter-bar :action="route('production.board')">
        <x-ui.input name="search" :value="$filters['search']" placeholder="No. order, klinik, dokter, pasien" label="Cari" class="md:w-72" />
        <x-ui.select name="status" label="Status">
            <option value="">Semua status</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select name="priority" label="Prioritas">
            <option value="">Semua prioritas</option>
            @foreach ($priorities as $priority)
                <option value="{{ $priority }}" @selected($filters['priority'] === $priority)>{{ $priorityLabels[$priority] ?? $priority }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.select name="technician_id" label="Teknisi">
            <option value="">Semua teknisi</option>
            @foreach ($technicians as $technician)
                <option value="{{ $technician->id }}" @selected($filters['technician_id'] === $technician->id)>{{ $technician->name }}</option>
            @endforeach
        </x-ui.select>
        <x-slot:actions>
            <x-ui.button type="submit">Terapkan</x-ui.button>
            <x-ui.button variant="secondary" :href="route('production.board')">Atur Ulang</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <x-ui.table>
        <thead class="bg-navy-50 text-ink">
            <tr>
                <th class="px-3 py-2 text-left font-medium">No. Order</th>
                <th class="px-3 py-2 text-left font-medium">Klinik</th>
                <th class="px-3 py-2 text-left font-medium">Pasien</th>
                <th class="px-3 py-2 text-left font-medium">Teknisi</th>
                <th class="px-3 py-2 text-left font-medium">Prioritas</th>
                <th class="px-3 py-2 text-left font-medium">Tenggat</th>
                <th class="px-3 py-2 text-left font-medium">Status</th>
                <th class="px-3 py-2 text-right font-medium">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-hairline">
            @forelse ($orders as $order)
                <tr class="transition-colors hover:bg-navy-50">
                    <td class="px-3 py-2 font-medium text-navy">{{ $order->order_number }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ $order->clinic?->name }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ $order->patient?->name ?? '—' }}</td>
                    <td class="px-3 py-2 text-ink-soft">{{ $order->activeAssignment?->technician?->name ?? 'Belum ditugaskan' }}</td>
                    <td class="px-3 py-2"><x-lab.status-badge :status="$order->priority" /></td>
                    <td class="px-3 py-2 text-ink-soft">{{ format_date_id($order->due_date, '—') }}</td>
                    <td class="px-3 py-2"><x-lab.status-badge :status="$order->status" /></td>
                    <td class="px-3 py-2 text-right">
                        <x-ui.button size="sm" variant="secondary" :href="route('production.show', $order)">Kelola</x-ui.button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-3 py-6">
                        <x-ui.empty-state title="Belum ada order produksi" description="Order lab yang siap diproduksi akan tampil di papan ini." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $orders->links() }}</div>
</x-settings-shell>
