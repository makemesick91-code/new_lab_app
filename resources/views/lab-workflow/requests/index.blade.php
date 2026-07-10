<x-settings-shell title="Permintaan Lab Cabang">
    <x-ui.page-header title="Permintaan Lab Cabang" subtitle="Permintaan pengiriman model ke laboratorium (Workflow V2).">
        <x-slot:breadcrumb>Lab / Permintaan Cabang</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button :href="route('lab-workflow-requests.create')">+ Buat Permintaan</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('lab-workflow-requests.index')">
        <x-ui.input name="search" label="Cari" placeholder="No. order / nama pasien" :value="$filters['search'] ?? ''" />
        <x-ui.select name="status" label="Status" :value="$filters['status'] ?? ''">
            <option value="">Semua Status</option>
            @foreach (['DRAFT' => 'Draft', 'WAITING_PICKUP' => 'Menunggu Pickup', 'PICKUP_ACCEPTED' => 'Pickup Diterima', 'PICKED_UP' => 'Model Diambil', 'IN_TRANSIT_TO_LAB' => 'Menuju Lab', 'RECEIVED_AT_LAB' => 'Diterima Lab'] as $value => $label)
                <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
            @endforeach
        </x-ui.select>
        <x-slot:actions>
            <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
            <x-ui.button :href="route('lab-workflow-requests.index')" variant="secondary" size="sm">Atur Ulang</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <x-ui.card class="mt-4">
        @if ($orders->isEmpty())
            <x-ui.empty-state title="Belum ada permintaan" description="Buat permintaan pengiriman model ke lab untuk cabang ini.">
                <x-ui.button :href="route('lab-workflow-requests.create')">+ Buat Permintaan</x-ui.button>
            </x-ui.empty-state>
        @else
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-3 text-left">No. Order</th>
                        <th class="px-4 py-3 text-left">Pasien</th>
                        <th class="px-4 py-3 text-left">Dokter</th>
                        <th class="px-4 py-3 text-left">Tenggat</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Kurir</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </x-slot:head>
                @foreach ($orders as $order)
                    <tr class="border-t border-hairline">
                        <td class="px-4 py-3 font-medium text-ink">{{ $order->order_number }}</td>
                        <td class="px-4 py-3 text-ink-soft">{{ $order->patient?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-soft">{{ $order->doctor?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-soft">{{ optional($order->due_date)->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @include('lab-workflow.partials.v2-status-badge', ['status' => $order->status])
                        </td>
                        <td class="px-4 py-3 text-ink-soft">{{ $order->pickupTask?->courier?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <x-ui.button :href="route('lab-workflow-requests.show', $order)" variant="secondary" size="sm">Detail</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
            <div class="mt-4">{{ $orders->links() }}</div>
        @endif
    </x-ui.card>
</x-settings-shell>
