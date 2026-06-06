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
    <div class="bg-white shadow-sm sm:rounded-lg">
        <div class="p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <form method="GET" action="{{ route('lab-orders.index') }}" class="flex flex-wrap items-center gap-2">
                    <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="No. order, RM, klinik, dokter, pasien"
                           class="rounded-md border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <select name="status" class="rounded-md border-gray-300 text-sm">
                        <option value="">Semua status</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
                        @endforeach
                    </select>
                    <select name="priority" class="rounded-md border-gray-300 text-sm">
                        <option value="">Semua prioritas</option>
                        @foreach ($priorities as $priority)
                            <option value="{{ $priority }}" @selected($filters['priority'] === $priority)>{{ $priorityLabels[$priority] ?? $priority }}</option>
                        @endforeach
                    </select>
                    <select name="clinic_id" class="rounded-md border-gray-300 text-sm">
                        <option value="">Semua klinik</option>
                        @foreach ($clinics as $clinic)
                            <option value="{{ $clinic->id }}" @selected($filters['clinic_id'] === $clinic->id)>{{ $clinic->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Terapkan</button>
                    <a href="{{ route('lab-orders.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Atur Ulang</a>
                </form>
                @can('create', App\Modules\LabOrder\Models\LabOrder::class)
                    <a href="{{ route('lab-orders.create') }}" class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">+ Tambah Order Lab</a>
                @endcan
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">No. Order</th>
                            <th class="px-3 py-2 font-medium">Klinik</th>
                            <th class="px-3 py-2 font-medium">Dokter</th>
                            <th class="px-3 py-2 font-medium">Pasien</th>
                            <th class="px-3 py-2 font-medium">Tenggat</th>
                            <th class="px-3 py-2 font-medium">Prioritas</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($orders as $order)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $order->order_number }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->clinic?->name }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $order->doctor?->name }}</td>
                                <td class="px-3 py-2 text-gray-600">
                                    <div>{{ $order->patient?->name ?? '—' }}</div>
                                    <div class="text-xs text-gray-400">RM: {{ $order->medical_record_number ?? '-' }}</div>
                                </td>
                                <td class="px-3 py-2 text-gray-600">{{ format_date_id($order->due_date, '—') }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $priorityLabels[$order->priority] ?? $order->priority }}</td>
                                <td class="px-3 py-2">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $order->status === 'CANCELLED' ? 'bg-gray-100 text-gray-600' : 'bg-blue-50 text-blue-700' }}">{{ $statusLabels[$order->status] ?? $order->status }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('lab-orders.show', $order) }}" class="text-indigo-600 hover:text-indigo-500">Lihat</a>
                                        @if ($order->isEditable())
                                            <a href="{{ route('lab-orders.edit', $order) }}" class="text-gray-600 hover:text-gray-900">Ubah</a>
                                            <form method="POST" action="{{ route('lab-orders.cancel', $order) }}"
                                                  onsubmit="var r=prompt('Alasan pembatalan (minimal 5 karakter):'); if(!r||r.length<5){return false;} this.reason.value=r;">
                                                @csrf
                                                <input type="hidden" name="reason" />
                                                <button type="submit" class="text-red-600 hover:text-red-500">Batalkan</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-3 py-6 text-center text-gray-400">Belum ada order.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div>{{ $orders->links() }}</div>
        </div>
    </div>
</x-settings-shell>
