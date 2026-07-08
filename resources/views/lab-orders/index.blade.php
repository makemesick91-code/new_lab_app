@php
    $statusLabels = [
        'DRAFT' => 'Draft',
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
    $priorityLabels = [
        'NORMAL' => 'Normal',
        'URGENT' => 'Mendesak',
        'SUPER_URGENT' => 'Sangat Mendesak',
    ];
@endphp

<x-settings-shell title="Order Lab">
    <x-ui.page-header title="Order Lab" subtitle="Kelola order pekerjaan laboratorium dari klinik.">
        <x-slot:breadcrumb>Lab / Order Lab</x-slot:breadcrumb>
        <x-slot:actions>
            @can('create', App\Modules\LabOrder\Models\LabOrder::class)
                <x-ui.button :href="route('lab-orders.create')">+ Tambah Order Lab</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.filter-bar :action="route('lab-orders.index')">
        <x-ui.input name="search" :value="$filters['search']" placeholder="No. order, RM, klinik, dokter, pasien" label="Cari" class="md:w-72" />
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
        <x-ui.select name="clinic_id" label="Klinik">
            <option value="">Semua klinik</option>
            @foreach ($clinics as $clinic)
                <option value="{{ $clinic->id }}" @selected($filters['clinic_id'] === $clinic->id)>{{ $clinic->name }}</option>
            @endforeach
        </x-ui.select>
        <x-slot:actions>
            <x-ui.button type="submit">Terapkan</x-ui.button>
            <x-ui.button variant="secondary" :href="route('lab-orders.index')">Atur Ulang</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    <x-ui.table>
        <thead class="bg-navy-50 text-ink">
            <tr>
                <th class="px-3 py-2 text-left font-medium">No. Order</th>
                <th class="px-3 py-2 text-left font-medium">Klinik</th>
                <th class="px-3 py-2 text-left font-medium">Dokter</th>
                <th class="px-3 py-2 text-left font-medium">Pasien</th>
                <th class="px-3 py-2 text-left font-medium">Tenggat</th>
                <th class="px-3 py-2 text-left font-medium">Prioritas</th>
                <th class="px-3 py-2 text-left font-medium">Status</th>
                <th class="px-3 py-2 text-right font-medium">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-hairline">
        @forelse ($orders as $order)
            <tr class="transition-colors hover:bg-navy-50">
                <td class="px-3 py-2 font-medium text-navy">{{ $order->order_number }}</td>
                <td class="px-3 py-2 text-ink-soft">{{ $order->clinic?->name }}</td>
                <td class="px-3 py-2 text-ink-soft">{{ $order->doctor?->name }}</td>
                <td class="px-3 py-2 text-ink-soft">
                    <div class="text-ink">{{ $order->patient?->name ?? '—' }}</div>
                    <div class="text-xs text-ink-muted">RM: {{ $order->medical_record_number ?? '-' }}</div>
                </td>
                <td class="px-3 py-2 text-ink-soft">{{ format_date_id($order->due_date, '—') }}</td>
                <td class="px-3 py-2"><x-lab.status-badge :status="$order->priority" /></td>
                <td class="px-3 py-2"><x-lab.status-badge :status="$order->status" /></td>
                <td class="px-3 py-2">
                    <div class="flex items-center justify-end gap-2">
                        <x-ui.button size="sm" variant="secondary" :href="route('lab-orders.show', $order)">Lihat</x-ui.button>
                        @if ($order->isEditable())
                            <x-ui.button size="sm" variant="secondary" :href="route('lab-orders.edit', $order)">Ubah</x-ui.button>
                            <form method="POST" action="{{ route('lab-orders.cancel', $order) }}"
                                  onsubmit="var r=prompt('Alasan pembatalan (minimal 5 karakter):'); if(!r||r.length<5){return false;} this.reason.value=r;">
                                @csrf
                                <input type="hidden" name="reason" />
                                <x-ui.button size="sm" variant="danger" type="submit">Batalkan</x-ui.button>
                            </form>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8" class="px-3 py-6">
                    <x-ui.empty-state title="Belum ada order" description="Order lab yang dibuat atau dikonversi dari RME akan tampil di sini.">
                        @can('create', App\Modules\LabOrder\Models\LabOrder::class)
                            <x-slot:action>
                                <x-ui.button :href="route('lab-orders.create')">+ Tambah Order Lab</x-ui.button>
                            </x-slot:action>
                        @else
                            <x-slot:action>
                                <x-ui.restricted-notice description="Anda tidak memiliki akses untuk menambah order lab. Hubungi administrator jika memerlukan akses." />
                            </x-slot:action>
                        @endcan
                    </x-ui.empty-state>
                </td>
            </tr>
        @endforelse
        </tbody>
    </x-ui.table>

    <div class="mt-4">{{ $orders->links() }}</div>
</x-settings-shell>
