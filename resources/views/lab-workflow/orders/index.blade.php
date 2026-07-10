<x-settings-shell title="Pipeline Lab V2">
    <x-ui.page-header title="Pipeline Lab V2" subtitle="Seluruh order Lab Workflow V2 lintas cabang — analisa, produksi, QC, lab eksternal.">
        <x-slot:breadcrumb>Lab / Pipeline V2</x-slot:breadcrumb>
        <x-slot:actions>
            @can('manage_lab_orders')
                <x-ui.button :href="route('lab-external-labs.index')" variant="secondary">Lab Eksternal</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4" data-v2-pipeline-kpis>
        @foreach ($kpis as $label => $count)
            <x-ui.kpi-card :label="$label" :value="(string) $count" />
        @endforeach
    </div>

    <x-ui.filter-bar :action="route('lab-v2-orders.index')">
        <x-ui.input name="search" label="Cari No. Order" :value="$search ?? ''" />
        <x-ui.select name="status" label="Status" :value="$status ?? ''">
            <option value="">Semua Status</option>
            @foreach (\App\Modules\LabOrder\Workflow\LabWorkflowState::all() as $state)
                <option value="{{ $state }}" @selected(($status ?? '') === $state)>{{ $state }}</option>
            @endforeach
        </x-ui.select>
        <x-slot:actions>
            <x-ui.button type="submit" size="sm">Terapkan</x-ui.button>
            <x-ui.button :href="route('lab-v2-orders.index')" variant="secondary" size="sm">Atur Ulang</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <x-ui.card class="mt-4">
        @if ($orders->isEmpty())
            <x-ui.empty-state title="Belum ada order V2" description="Order Workflow V2 akan muncul di sini setelah cabang membuat permintaan." />
        @else
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <th class="px-4 py-3 text-left">No. Order</th>
                        <th class="px-4 py-3 text-left">Cabang</th>
                        <th class="px-4 py-3 text-left">Pasien</th>
                        <th class="px-4 py-3 text-left">Tenggat</th>
                        <th class="px-4 py-3 text-left">Status</th>
                        <th class="px-4 py-3 text-left">Jalur</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </x-slot:head>
                @foreach ($orders as $order)
                    <tr class="border-t border-hairline">
                        <td class="px-4 py-3 font-medium text-ink">{{ $order->order_number }}</td>
                        <td class="px-4 py-3 text-ink-soft">{{ $order->branch?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-soft">{{ $order->patient?->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-soft">{{ optional($order->due_date)->format('d M Y') ?? '—' }}</td>
                        <td class="px-4 py-3">@include('lab-workflow.partials.v2-status-badge', ['status' => $order->status])</td>
                        <td class="px-4 py-3 text-ink-soft">
                            @if ($order->latestModelAnalysis)
                                {{ $order->latestModelAnalysis->decision === 'INTERNAL' ? 'Internal' : 'Eksternal' }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <x-ui.button :href="route('lab-v2-orders.show', $order)" variant="secondary" size="sm">Kelola</x-ui.button>
                        </td>
                    </tr>
                @endforeach
            </x-ui.table>
            <div class="mt-4">{{ $orders->links() }}</div>
        @endif
    </x-ui.card>
</x-settings-shell>
