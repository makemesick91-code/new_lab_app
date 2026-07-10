<x-settings-shell title="Pengiriman Lab V2">
    <x-ui.page-header title="Pengiriman Lab V2" subtitle="Antrian pengantaran model dari laboratorium kembali ke cabang.">
        <x-slot:breadcrumb>Lab / Pengiriman V2</x-slot:breadcrumb>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['active' => 'Aktif', 'mine' => 'Tugas Saya', 'all' => 'Semua'] as $value => $label)
            <x-ui.button :href="route('lab-delivery-tasks.index', ['scope' => $value])"
                :variant="$scope === $value ? 'primary' : 'secondary'" size="sm">{{ $label }}</x-ui.button>
        @endforeach
    </div>

    <x-ui.card>
        @if ($tasks->isEmpty())
            <x-ui.empty-state title="Tidak ada tugas pengiriman" description="Tugas pengiriman muncul setelah model selesai (MODEL_DONE)." />
        @else
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-3 text-left">No. Order</th>
                        <th class="px-4 py-3 text-left">Cabang Tujuan</th>
                        <th class="px-4 py-3 text-left">Status Tugas</th>
                        <th class="px-4 py-3 text-left">Kurir</th>
                        <th class="px-4 py-3 text-left">Penerima</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </x-slot:head>
                @foreach ($tasks as $task)
                    <tr class="border-t border-hairline">
                        <td class="px-4 py-3 font-medium text-ink">{{ $task->labOrder?->order_number ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-soft">{{ $task->branch?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @php
                                $deliveryTones = ['PENDING' => 'warning', 'ACCEPTED' => 'info', 'READY_FOR_TRANSIT' => 'info', 'IN_TRANSIT' => 'info', 'ARRIVED' => 'info', 'DELIVERED' => 'success', 'CANCELLED' => 'danger'];
                            @endphp
                            <x-ui.badge :tone="$deliveryTones[$task->status] ?? 'neutral'">{{ $task->status }}</x-ui.badge>
                        </td>
                        <td class="px-4 py-3 text-ink-soft">{{ $task->courier?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-soft">{{ $task->recipient_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-right">
                            <x-ui.button :href="route('lab-delivery-tasks.show', $task)" variant="secondary" size="sm">Detail</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
            <div class="mt-4">{{ $tasks->links() }}</div>
        @endif
    </x-ui.card>
</x-settings-shell>
