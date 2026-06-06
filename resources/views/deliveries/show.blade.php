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
    $statusBadgeClasses = [
        'READY_FOR_DELIVERY' => 'bg-gray-100 text-gray-700 ring-gray-200',
        'IN_DELIVERY' => 'bg-teal-50 text-teal-800 ring-teal-200',
        'DELIVERED' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
        'COMPLETED' => 'bg-teal-100 text-teal-900 ring-teal-300',
        'CANCELLED' => 'bg-rose-50 text-rose-800 ring-rose-200',
    ];
@endphp

<x-settings-shell title="Detail Pengiriman">
    <div class="space-y-6">
        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Detail Pengiriman</p>
                    <h2 class="mt-1 text-xl font-semibold text-gray-900">{{ $delivery->delivery_number }}</h2>
                    <span class="mt-2 inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusBadgeClasses[$delivery->status] ?? 'bg-gray-100 text-gray-700 ring-gray-200' }}">
                        {{ $statusLabels[$delivery->status] ?? $delivery->status }}
                    </span>
                </div>
                <a href="{{ route('deliveries.index') }}" class="text-sm font-medium text-gray-500 hover:text-teal-700">Kembali ke antrean</a>
            </div>
            <dl class="mt-5 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-gray-500">Order</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $delivery->labOrder?->order_number }}</dd></div>
                <div><dt class="text-gray-500">Klinik</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $delivery->labOrder?->clinic?->name }}</dd></div>
                <div><dt class="text-gray-500">Dokter</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $delivery->labOrder?->doctor?->name }}</dd></div>
                <div><dt class="text-gray-500">Pasien</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $delivery->labOrder?->patient?->name ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Prioritas</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $priorityLabels[$delivery->labOrder?->priority] ?? $delivery->labOrder?->priority }}</dd></div>
                <div><dt class="text-gray-500">Kurir</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $delivery->courier?->name ?? '-' }}</dd></div>
            </dl>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm space-y-3">
                <h3 class="text-sm font-semibold text-gray-900">Penugasan Kurir</h3>
                @can('assignCourier', $delivery)
                    <form method="POST" action="{{ route($delivery->courier_id ? 'deliveries.reassign-courier' : 'deliveries.assign-courier', $delivery) }}" class="space-y-3">
                        @csrf
                        <select name="courier_id" class="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                            @foreach ($couriers as $courier)
                                <option value="{{ $courier->id }}" @selected($delivery->courier_id === $courier->id)>{{ $courier->name }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="notes" placeholder="{{ $delivery->courier_id ? 'Catatan pergantian wajib diisi' : 'Catatan penugasan' }}" class="w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500">
                        <button class="rounded-lg bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ $delivery->courier_id ? 'Ganti Kurir' : 'Tugaskan Kurir' }}</button>
                    </form>
                @else
                    <p class="text-sm text-gray-400">Tidak ada aksi penugasan tersedia.</p>
                @endcan
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm space-y-3">
                <h3 class="text-sm font-semibold text-gray-900">Lifecycle Pengiriman</h3>
                <div class="flex flex-wrap gap-2">
                    @can('startDelivery', $delivery)
                        <form method="POST" action="{{ route('deliveries.start', $delivery) }}">
                            @csrf
                            <button class="rounded-lg bg-teal-700 px-3 py-2 text-sm font-medium text-white hover:bg-teal-600">Mulai Pengiriman</button>
                        </form>
                    @endcan
                    @can('completeDelivery', $delivery)
                        <form method="POST" action="{{ route('deliveries.complete', $delivery) }}">
                            @csrf
                            <button class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-500">Selesaikan Pengiriman</button>
                        </form>
                    @endcan
                </div>
                <p class="text-sm text-gray-500">Dimulai: {{ format_datetime_id($delivery->started_at) }}</p>
                <p class="text-sm text-gray-500">Diselesaikan: {{ format_datetime_id($delivery->completed_at) }}</p>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm space-y-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-900">Panel POD</h3>
                <p class="mt-1 text-sm text-gray-500">Bukti penerimaan dengan tanda tangan manual penerima.</p>
            </div>

            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">Penerima</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $delivery->receiver_name ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Diterima Pada</dt><dd class="mt-0.5 font-medium text-gray-900">{{ format_datetime_id($delivery->received_at) }}</dd></div>
                <div><dt class="text-gray-500">Catatan</dt><dd class="mt-0.5 font-medium text-gray-900">{{ $delivery->delivery_notes ?? '-' }}</dd></div>
            </dl>

            @if ($delivery->hasSignature())
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                    <p class="text-sm font-medium text-gray-700">Tanda Tangan Penerima</p>
                    <div class="mt-2 inline-block rounded-lg border border-gray-200 bg-white p-2">
                        @if ($delivery->receiver_signature_data)
                            <img src="{{ $delivery->receiver_signature_data }}" alt="Tanda tangan penerima" class="max-h-32 max-w-full">
                        @elseif ($delivery->receiver_signature_path)
                            <img src="{{ asset('storage/'.$delivery->receiver_signature_path) }}" alt="Tanda tangan penerima (legacy)" class="max-h-32 max-w-full">
                        @endif
                    </div>
                </div>
            @endif

            @can('uploadPod', $delivery)
                <form method="POST" action="{{ route('deliveries.pod', $delivery) }}">
                    @csrf
                    @include('deliveries._pod-form', [
                        'delivery' => $delivery,
                        'submitLabel' => 'Unggah POD',
                        'buttonClass' => 'bg-teal-700 hover:bg-teal-600 focus:ring-teal-500',
                    ])
                </form>
            @endcan

            @can('markDelivered', $delivery)
                <form method="POST" action="{{ route('deliveries.mark-delivered', $delivery) }}">
                    @csrf
                    @include('deliveries._pod-form', [
                        'delivery' => $delivery,
                        'submitLabel' => 'Tandai Terkirim',
                        'buttonClass' => 'bg-emerald-600 hover:bg-emerald-500 focus:ring-emerald-500',
                    ])
                </form>
            @endcan
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-semibold text-gray-900">Panel Bukti</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($delivery->attachments as $attachment)
                        <li class="flex items-center justify-between border-b border-gray-100 pb-2">
                            <span>{{ $attachment->file_name }} <span class="text-xs text-gray-400">({{ $attachment->category }})</span></span>
                            <a href="{{ asset('storage/'.$attachment->file_path) }}" target="_blank" class="font-medium text-teal-700 hover:text-teal-600">Buka</a>
                        </li>
                    @empty
                        <li class="text-gray-400">Belum ada bukti yang diunggah.</li>
                    @endforelse
                </ul>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h3 class="text-sm font-semibold text-gray-900">Riwayat Status</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($delivery->labOrder?->statusLogs->sortByDesc('changed_at') ?? [] as $log)
                        <li class="border-b border-gray-100 pb-2">
                            <p class="font-medium">{{ $statusLabels[$log->old_status] ?? $log->old_status }} -> {{ $statusLabels[$log->new_status] ?? $log->new_status }}</p>
                            <p class="text-gray-500">{{ format_datetime_id($log->changed_at) }} oleh {{ $log->changedBy?->name ?? 'Sistem' }}</p>
                        </li>
                    @empty
                        <li class="text-gray-400">Belum ada riwayat status.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-semibold text-gray-900">Riwayat Audit</h3>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($delivery->auditLogs->sortByDesc('performed_at') as $log)
                    <li class="border-b border-gray-100 pb-2">
                        <p class="font-medium">{{ $log->action }}</p>
                        <p class="text-gray-500">{{ format_datetime_id($log->performed_at) }} oleh {{ $log->performer?->name ?? 'Sistem' }}</p>
                    </li>
                @empty
                    <li class="text-gray-400">Belum ada catatan audit.</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-settings-shell>
