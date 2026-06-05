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

<x-settings-shell title="Detail Pengiriman">
    <div class="space-y-6">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm text-gray-500">Nomor Pengiriman</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $delivery->delivery_number }}</p>
                    <span class="mt-1 inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">{{ $statusLabels[$delivery->status] ?? $delivery->status }}</span>
                </div>
                <a href="{{ route('deliveries.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Kembali ke antrean</a>
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">Order</dt><dd class="font-medium">{{ $delivery->labOrder?->order_number }}</dd></div>
                <div><dt class="text-gray-500">Klinik</dt><dd class="font-medium">{{ $delivery->labOrder?->clinic?->name }}</dd></div>
                <div><dt class="text-gray-500">Dokter</dt><dd class="font-medium">{{ $delivery->labOrder?->doctor?->name }}</dd></div>
                <div><dt class="text-gray-500">Pasien</dt><dd class="font-medium">{{ $delivery->labOrder?->patient?->name ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Prioritas</dt><dd class="font-medium">{{ $priorityLabels[$delivery->labOrder?->priority] ?? $delivery->labOrder?->priority }}</dd></div>
                <div><dt class="text-gray-500">Kurir</dt><dd class="font-medium">{{ $delivery->courier?->name ?? '-' }}</dd></div>
            </dl>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-semibold text-gray-800">Penugasan Kurir</h3>
                @can('assignCourier', $delivery)
                    <form method="POST" action="{{ route($delivery->courier_id ? 'deliveries.reassign-courier' : 'deliveries.assign-courier', $delivery) }}" class="space-y-3">
                        @csrf
                        <select name="courier_id" class="w-full rounded-md border-gray-300 text-sm">
                            @foreach ($couriers as $courier)
                                <option value="{{ $courier->id }}" @selected($delivery->courier_id === $courier->id)>{{ $courier->name }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="notes" placeholder="{{ $delivery->courier_id ? 'Catatan pergantian wajib diisi' : 'Catatan penugasan' }}" class="w-full rounded-md border-gray-300 text-sm">
                        <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ $delivery->courier_id ? 'Ganti Kurir' : 'Tugaskan Kurir' }}</button>
                    </form>
                @else
                    <p class="text-sm text-gray-400">Tidak ada aksi penugasan tersedia.</p>
                @endcan
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
                <h3 class="font-semibold text-gray-800">Lifecycle Pengiriman</h3>
                <div class="flex flex-wrap gap-2">
                    @can('startDelivery', $delivery)
                        <form method="POST" action="{{ route('deliveries.start', $delivery) }}">
                            @csrf
                            <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Mulai Pengiriman</button>
                        </form>
                    @endcan
                    @can('completeDelivery', $delivery)
                        <form method="POST" action="{{ route('deliveries.complete', $delivery) }}">
                            @csrf
                            <button class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Selesaikan Pengiriman</button>
                        </form>
                    @endcan
                </div>
                <p class="text-sm text-gray-500">Dimulai: {{ optional($delivery->started_at)->format('Y-m-d H:i') ?? '-' }}</p>
                <p class="text-sm text-gray-500">Diselesaikan: {{ optional($delivery->completed_at)->format('Y-m-d H:i') ?? '-' }}</p>
            </div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
            <h3 class="font-semibold text-gray-800">Panel POD</h3>
            <dl class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">Penerima</dt><dd class="font-medium">{{ $delivery->receiver_name ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Diterima Pada</dt><dd class="font-medium">{{ optional($delivery->received_at)->format('Y-m-d H:i') ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Catatan</dt><dd class="font-medium">{{ $delivery->delivery_notes ?? '-' }}</dd></div>
            </dl>

            @can('uploadPod', $delivery)
                <form method="POST" action="{{ route('deliveries.pod', $delivery) }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-3 rounded-md border border-gray-200 p-3 md:grid-cols-2">
                    @csrf
                    <input type="text" name="receiver_name" value="{{ old('receiver_name', $delivery->receiver_name) }}" placeholder="Nama penerima" class="rounded-md border-gray-300 text-sm">
                    <input type="datetime-local" name="received_at" value="{{ old('received_at', optional($delivery->received_at)->format('Y-m-d\TH:i')) }}" class="rounded-md border-gray-300 text-sm">
                    <label class="text-sm text-gray-600">Tanda Tangan <input type="file" name="signature" class="mt-1 block text-sm"></label>
                    <label class="text-sm text-gray-600">Foto Penerima <input type="file" name="receiver_photo" class="mt-1 block text-sm"></label>
                    <textarea name="delivery_notes" placeholder="Catatan pengiriman" class="rounded-md border-gray-300 text-sm md:col-span-2">{{ old('delivery_notes', $delivery->delivery_notes) }}</textarea>
                    <button class="w-fit rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Unggah POD</button>
                </form>
            @endcan

            @can('markDelivered', $delivery)
                <form method="POST" action="{{ route('deliveries.mark-delivered', $delivery) }}" enctype="multipart/form-data" class="grid grid-cols-1 gap-3 rounded-md border border-gray-200 p-3 md:grid-cols-2">
                    @csrf
                    <input type="text" name="receiver_name" value="{{ old('receiver_name', $delivery->receiver_name) }}" placeholder="Nama penerima" class="rounded-md border-gray-300 text-sm">
                    <input type="datetime-local" name="received_at" value="{{ old('received_at', optional($delivery->received_at)->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i')) }}" class="rounded-md border-gray-300 text-sm">
                    <label class="text-sm text-gray-600">Tanda Tangan <input type="file" name="signature" class="mt-1 block text-sm"></label>
                    <label class="text-sm text-gray-600">Foto Penerima <input type="file" name="receiver_photo" class="mt-1 block text-sm"></label>
                    <textarea name="delivery_notes" placeholder="Catatan pengiriman" class="rounded-md border-gray-300 text-sm md:col-span-2">{{ old('delivery_notes', $delivery->delivery_notes) }}</textarea>
                    <button class="w-fit rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Tandai Terkirim</button>
                </form>
            @endcan
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Panel Bukti</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($delivery->attachments as $attachment)
                        <li class="flex items-center justify-between border-b border-gray-100 pb-2">
                            <span>{{ $attachment->file_name }} <span class="text-xs text-gray-400">({{ $attachment->category }})</span></span>
                            <a href="{{ asset('storage/'.$attachment->file_path) }}" target="_blank" class="text-indigo-600 hover:text-indigo-500">Buka</a>
                        </li>
                    @empty
                        <li class="text-gray-400">Belum ada bukti yang diunggah.</li>
                    @endforelse
                </ul>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Riwayat Status</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($delivery->labOrder?->statusLogs->sortByDesc('changed_at') ?? [] as $log)
                        <li class="border-b border-gray-100 pb-2">
                            <p class="font-medium">{{ $statusLabels[$log->old_status] ?? $log->old_status }} -> {{ $statusLabels[$log->new_status] ?? $log->new_status }}</p>
                            <p class="text-gray-500">{{ optional($log->changed_at)->format('Y-m-d H:i') }} oleh {{ $log->changedBy?->name ?? 'Sistem' }}</p>
                        </li>
                    @empty
                        <li class="text-gray-400">Belum ada riwayat status.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800">Riwayat Audit</h3>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($delivery->auditLogs->sortByDesc('performed_at') as $log)
                    <li class="border-b border-gray-100 pb-2">
                        <p class="font-medium">{{ $log->action }}</p>
                        <p class="text-gray-500">{{ optional($log->performed_at)->format('Y-m-d H:i') }} oleh {{ $log->performer?->name ?? 'Sistem' }}</p>
                    </li>
                @empty
                    <li class="text-gray-400">Belum ada catatan audit.</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-settings-shell>
