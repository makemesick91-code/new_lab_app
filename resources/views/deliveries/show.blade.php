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
    <x-ui.page-header :title="$delivery->delivery_number">
        <x-slot:breadcrumb>Lab / Pengiriman / {{ $delivery->delivery_number }}</x-slot:breadcrumb>
        <x-slot:actions>
            <x-lab.status-badge :status="$delivery->status" />
            <x-ui.button variant="secondary" :href="route('deliveries.index')">&larr; Kembali ke antrean</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">
        <x-ui.card>
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-ink-soft">Order</dt><dd class="mt-0.5 font-medium text-navy">{{ $delivery->labOrder?->order_number }}</dd></div>
                <div><dt class="text-ink-soft">Klinik</dt><dd class="mt-0.5 font-medium text-navy">{{ $delivery->labOrder?->clinic?->name }}</dd></div>
                <div><dt class="text-ink-soft">Dokter</dt><dd class="mt-0.5 font-medium text-navy">{{ $delivery->labOrder?->doctor?->name }}</dd></div>
                <div><dt class="text-ink-soft">Pasien</dt><dd class="mt-0.5 font-medium text-navy">{{ $delivery->labOrder?->patient?->name ?? '-' }}</dd></div>
                <div><dt class="text-ink-soft">Prioritas</dt><dd class="mt-0.5 font-medium text-navy">{{ $priorityLabels[$delivery->labOrder?->priority] ?? $delivery->labOrder?->priority }}</dd></div>
                <div><dt class="text-ink-soft">Kurir</dt><dd class="mt-0.5 font-medium text-navy">{{ $delivery->courier?->name ?? '-' }}</dd></div>
            </dl>
        </x-ui.card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-ui.card title="Penugasan Kurir">
                @can('assignCourier', $delivery)
                    <form method="POST" action="{{ route($delivery->courier_id ? 'deliveries.reassign-courier' : 'deliveries.assign-courier', $delivery) }}" class="space-y-3">
                        @csrf
                        <select name="courier_id" class="w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                            @foreach ($couriers as $courier)
                                <option value="{{ $courier->id }}" @selected($delivery->courier_id === $courier->id)>{{ $courier->name }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="notes" placeholder="{{ $delivery->courier_id ? 'Catatan pergantian wajib diisi' : 'Catatan penugasan' }}" class="w-full rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                        <x-ui.button type="submit" size="sm" :variant="$delivery->courier_id ? 'warning' : 'primary'">{{ $delivery->courier_id ? 'Ganti Kurir' : 'Tugaskan Kurir' }}</x-ui.button>
                    </form>
                @else
                    <p class="text-sm text-ink-muted">Tidak ada aksi penugasan tersedia.</p>
                @endcan
            </x-ui.card>

            <x-ui.card title="Lifecycle Pengiriman">
                <div class="flex flex-wrap gap-2">
                    @can('startDelivery', $delivery)
                        <form method="POST" action="{{ route('deliveries.start', $delivery) }}">
                            @csrf
                            <x-ui.button type="submit" size="sm">Mulai Pengiriman</x-ui.button>
                        </form>
                    @endcan
                    @can('completeDelivery', $delivery)
                        <form method="POST" action="{{ route('deliveries.complete', $delivery) }}">
                            @csrf
                            <x-ui.button type="submit" size="sm" variant="success">Selesaikan Pengiriman</x-ui.button>
                        </form>
                    @endcan
                </div>
                <p class="mt-3 text-sm text-ink-soft">Dimulai: {{ format_datetime_id($delivery->started_at) }}</p>
                <p class="text-sm text-ink-soft">Diselesaikan: {{ format_datetime_id($delivery->completed_at) }}</p>
            </x-ui.card>
        </div>

        <x-ui.card title="Panel POD" description="Bukti penerimaan dengan tanda tangan manual penerima.">
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
                <div><dt class="text-ink-soft">Penerima</dt><dd class="mt-0.5 font-medium text-navy">{{ $delivery->receiver_name ?? '-' }}</dd></div>
                <div><dt class="text-ink-soft">Diterima Pada</dt><dd class="mt-0.5 font-medium text-navy">{{ format_datetime_id($delivery->received_at) }}</dd></div>
                <div><dt class="text-ink-soft">Catatan</dt><dd class="mt-0.5 font-medium text-navy">{{ $delivery->delivery_notes ?? '-' }}</dd></div>
            </dl>

            @if ($delivery->hasSignature())
                <div class="mt-4 rounded-lg border border-hairline bg-navy-50 p-4">
                    <p class="text-sm font-medium text-ink">Tanda Tangan Penerima</p>
                    <div class="mt-2 inline-block rounded-lg border border-hairline bg-surface p-2">
                        @if ($delivery->receiver_signature_data)
                            <img src="{{ $delivery->receiver_signature_data }}" alt="Tanda tangan penerima" class="max-h-32 max-w-full">
                        @elseif ($delivery->receiver_signature_path)
                            <img src="{{ route('deliveries.receiver-signature', $delivery) }}" alt="Tanda tangan penerima (legacy)" class="max-h-32 max-w-full">
                        @endif
                    </div>
                </div>
            @endif

            @can('uploadPod', $delivery)
                <form method="POST" action="{{ route('deliveries.pod', $delivery) }}" class="mt-4">
                    @csrf
                    @include('deliveries._pod-form', [
                        'delivery' => $delivery,
                        'submitLabel' => 'Unggah POD',
                        'buttonVariant' => 'primary',
                    ])
                </form>
            @endcan

            @can('markDelivered', $delivery)
                <form method="POST" action="{{ route('deliveries.mark-delivered', $delivery) }}" class="mt-4">
                    @csrf
                    @include('deliveries._pod-form', [
                        'delivery' => $delivery,
                        'submitLabel' => 'Tandai Terkirim',
                        'buttonVariant' => 'success',
                    ])
                </form>
            @endcan
        </x-ui.card>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-ui.card title="Panel Bukti">
                <ul class="space-y-2 text-sm">
                    @forelse ($delivery->attachments as $attachment)
                        <li class="flex items-center justify-between border-b border-hairline pb-2">
                            <span class="text-navy">{{ $attachment->file_name }} <span class="text-xs text-ink-muted">({{ $attachment->category }})</span></span>
                            <a href="{{ route('attachments.download', $attachment) }}" target="_blank" class="font-medium text-brand-700 hover:text-brand-800">Buka</a>
                        </li>
                    @empty
                        <li class="text-ink-muted">Belum ada bukti yang diunggah.</li>
                    @endforelse
                </ul>
            </x-ui.card>
            <x-ui.card title="Riwayat Status">
                <ul class="space-y-2 text-sm">
                    @forelse ($delivery->labOrder?->statusLogs->sortByDesc('changed_at') ?? [] as $log)
                        <li class="border-b border-hairline pb-2">
                            <p class="font-medium text-navy">{{ $statusLabels[$log->old_status] ?? $log->old_status }} -> {{ $statusLabels[$log->new_status] ?? $log->new_status }}</p>
                            <p class="text-ink-soft">{{ format_datetime_id($log->changed_at) }} oleh {{ $log->changedBy?->name ?? 'Sistem' }}</p>
                        </li>
                    @empty
                        <li class="text-ink-muted">Belum ada riwayat status.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>

        <x-ui.card title="Riwayat Audit">
            <ul class="space-y-2 text-sm">
                @forelse ($delivery->auditLogs->sortByDesc('performed_at') as $log)
                    <li class="border-b border-hairline pb-2">
                        <p class="font-medium text-navy">{{ $log->action }}</p>
                        <p class="text-ink-soft">{{ format_datetime_id($log->performed_at) }} oleh {{ $log->performer?->name ?? 'Sistem' }}</p>
                    </li>
                @empty
                    <li class="text-ink-muted">Belum ada catatan audit.</li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>
</x-settings-shell>
