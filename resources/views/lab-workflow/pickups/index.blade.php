<x-settings-shell title="Tugas Pickup Lab">
    <x-ui.page-header title="Tugas Pickup Lab" subtitle="Antrian penjemputan model dari cabang ke laboratorium.">
        <x-slot:breadcrumb>Lab / Pickup</x-slot:breadcrumb>
    </x-ui.page-header>

    @if (session('success'))
        <x-ui.alert variant="success" class="mb-4">{{ session('success') }}</x-ui.alert>
    @endif

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['active' => 'Aktif', 'mine' => 'Tugas Saya', 'all' => 'Semua'] as $value => $label)
            <x-ui.button :href="route('lab-pickup-tasks.index', ['scope' => $value])"
                :variant="$scope === $value ? 'primary' : 'secondary'" size="sm">{{ $label }}</x-ui.button>
        @endforeach
    </div>

    <x-ui.card>
        @if ($tasks->isEmpty())
            <x-ui.empty-state title="Tidak ada tugas pickup" description="Tugas pickup baru akan muncul di sini saat cabang mengirim permintaan." />
        @else
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-3 text-left">No. Order</th>
                        <th class="px-4 py-3 text-left">Cabang Pickup</th>
                        <th class="px-4 py-3 text-left">Prioritas</th>
                        <th class="px-4 py-3 text-left">Status Tugas</th>
                        <th class="px-4 py-3 text-left">Kurir</th>
                        <th class="px-4 py-3 text-left">Dibuat</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </x-slot:head>
                @foreach ($tasks as $task)
                    <tr class="border-t border-hairline">
                        <td class="px-4 py-3 font-medium text-ink">{{ $task->labOrder?->order_number ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-soft">{{ $task->branch?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-soft">{{ $task->labOrder?->priority ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @php
                                $taskTones = ['PENDING' => 'warning', 'ACCEPTED' => 'info', 'PICKED_UP' => 'info', 'IN_TRANSIT' => 'info', 'RECEIVED' => 'success', 'CANCELLED' => 'danger'];
                            @endphp
                            <x-ui.badge :tone="$taskTones[$task->status] ?? 'neutral'">{{ $task->status }}</x-ui.badge>
                        </td>
                        <td class="px-4 py-3 text-ink-soft">{{ $task->courier?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-soft">{{ optional($task->created_at)->format('d M H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            <x-ui.button :href="route('lab-pickup-tasks.show', $task)" variant="secondary" size="sm">Detail</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
            <div class="mt-4">{{ $tasks->links() }}</div>
        @endif
    </x-ui.card>
</x-settings-shell>
