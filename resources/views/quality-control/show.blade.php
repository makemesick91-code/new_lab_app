@php
    $statusLabels = [
        'QC_PENDING' => 'Menunggu QC',
        'QC_PASSED' => 'QC Lulus',
        'REMAKE' => 'Perbaikan',
        'PASSED' => 'Lulus',
        'REJECTED' => 'Ditolak',
        'REVISION' => 'Revisi',
        'PASS' => 'Lulus',
        'FAIL' => 'Gagal',
        'N_A' => 'N/A',
        'OPEN' => 'Terbuka',
        'IN_PROGRESS' => 'Dalam Proses',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
    ];
    $priorityLabels = [
        'NORMAL' => 'Normal',
        'URGENT' => 'Mendesak',
        'SUPER_URGENT' => 'Sangat Mendesak',
    ];
@endphp

<x-settings-shell title="Detail QC">
    <x-ui.page-header title="Detail QC">
        <x-slot:breadcrumb>Lab / Antrean QC / {{ $order->order_number }}</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('quality-control.queue')">&larr; Kembali ke antrean</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">
        {{-- Summary --}}
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-ink-soft">Nomor Order</p>
                    <p class="text-lg font-semibold text-navy">{{ $order->order_number }}</p>
                    <div class="mt-1"><x-lab.status-badge :status="$order->status" /></div>
                </div>
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-ink-soft">Klinik</dt><dd class="font-medium text-navy">{{ $order->clinic?->name }}</dd></div>
                <div><dt class="text-ink-soft">Dokter</dt><dd class="font-medium text-navy">{{ $order->doctor?->name }}</dd></div>
                <div><dt class="text-ink-soft">Pasien</dt><dd class="font-medium text-navy">{{ $order->patient?->name ?? '—' }}</dd></div>
                <div><dt class="text-ink-soft">Prioritas</dt><dd class="font-medium text-navy">{{ $priorityLabels[$order->priority] ?? $order->priority }}</dd></div>
                <div><dt class="text-ink-soft">Teknisi Produksi</dt><dd class="font-medium text-navy">{{ $activeAssignment?->technician?->name ?? '—' }}</dd></div>
                <div><dt class="text-ink-soft">Pemeriksa QC Aktif</dt><dd class="font-medium text-navy">{{ $activeReview?->inspector?->name ?? '—' }}</dd></div>
            </dl>
        </x-ui.card>

        {{-- QC actions --}}
        <x-ui.card title="Aksi QC">
            <div class="flex flex-wrap gap-3">
                @can('qc.start', $order)
                    <form method="POST" action="{{ route('quality-control.start', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Catatan" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <x-ui.button type="submit" variant="neutral" size="sm">Mulai Peninjauan</x-ui.button>
                    </form>
                @endcan
                @can('qc.pass', $order)
                    <form method="POST" action="{{ route('quality-control.pass', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Catatan" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <x-ui.button type="submit" variant="success" size="sm">Lulus QC</x-ui.button>
                    </form>
                @endcan
                @can('qc.reject', $order)
                    <form method="POST" action="{{ route('quality-control.reject', $order) }}" class="flex flex-wrap items-end gap-2 rounded-lg border border-hairline p-3">
                        @csrf
                        <select name="result" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="REJECTED">Ditolak</option>
                            <option value="REVISION">Revisi</option>
                        </select>
                        <select name="reason" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                            @foreach ($remakeReasons as $reason)<option value="{{ $reason }}">{{ $reason }}</option>@endforeach
                        </select>
                        <input type="text" name="notes" placeholder="Catatan (wajib)" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <x-ui.button type="submit" variant="danger" size="sm">Tolak QC</x-ui.button>
                    </form>
                @endcan
                @can('qc.requestRemake', $order)
                    <form method="POST" action="{{ route('quality-control.remake', $order) }}" class="flex flex-wrap items-end gap-2 rounded-lg border border-hairline p-3">
                        @csrf
                        <select name="reason" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                            @foreach ($remakeReasons as $reason)<option value="{{ $reason }}">{{ $reason }}</option>@endforeach
                        </select>
                        <input type="text" name="notes" placeholder="Catatan (wajib)" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <x-ui.button type="submit" variant="warning" size="sm">Minta Perbaikan</x-ui.button>
                    </form>
                @endcan
            </div>
        </x-ui.card>

        {{-- Checklist panel --}}
        <x-ui.card title="Checklist QC">
            @if ($activeReview)
                <x-ui.table class="mt-1">
                    <thead class="bg-navy-50 text-ink"><tr>
                        <th class="px-3 py-2 text-left font-medium">Item</th>
                        <th class="px-3 py-2 text-left font-medium">Hasil</th>
                        <th class="px-3 py-2 text-left font-medium">Perbarui</th>
                    </tr></thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($checklists as $item)
                            <tr>
                                <td class="px-3 py-2 font-medium text-navy">{{ $item->checklist_item }}</td>
                                <td class="px-3 py-2"><x-lab.status-badge :status="$item->result" /></td>
                                <td class="px-3 py-2">
                                    @can('qc.checklists.update', $item)
                                        <form method="POST" action="{{ route('quality-control.checklists.update', $item) }}" class="flex items-center gap-2">
                                            @csrf @method('PATCH')
                                            <select name="result" class="rounded-lg border-hairline text-xs focus:border-brand-500 focus:ring-brand-500">
                                                @foreach ($checklistResults as $r)<option value="{{ $r }}" @selected($item->result === $r)>{{ $statusLabels[$r] ?? ($r === 'N_A' ? 'N/A' : $r) }}</option>@endforeach
                                            </select>
                                            <input type="text" name="notes" placeholder="Catatan" class="rounded-lg border-hairline text-xs focus:border-brand-500 focus:ring-brand-500" />
                                            <x-ui.button type="submit" size="sm" variant="ghost">Simpan</x-ui.button>
                                        </form>
                                    @else
                                        <span class="text-xs text-ink-muted">—</span>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @else
                <p class="text-sm text-ink-muted">Mulai review QC untuk memuat checklist.</p>
            @endif
        </x-ui.card>

        {{-- Evidence panel --}}
        <x-ui.card title="Bukti QC">
            @can('qc.uploadEvidence', $order)
                <form method="POST" action="{{ route('quality-control.evidence.store', $order) }}" enctype="multipart/form-data" class="mb-3 flex flex-wrap items-end gap-2 rounded-lg border border-hairline p-3">
                    @csrf
                    <select name="category" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                        @foreach ($evidenceCategories as $cat)<option value="{{ $cat }}">{{ $cat }}</option>@endforeach
                    </select>
                    <input type="file" name="file" class="text-sm" />
                    <x-ui.button type="submit" size="sm">Unggah</x-ui.button>
                </form>
            @endcan
            <ul class="space-y-1 text-sm">
                @forelse ($order->attachments as $attachment)
                    <li class="flex items-center justify-between border-b border-hairline pb-1">
                        <span class="text-navy">{{ $attachment->file_name }} <span class="text-xs text-ink-muted">({{ $attachment->category }})</span></span>
                        <a href="{{ asset('storage/'.$attachment->file_path) }}" target="_blank" class="text-brand-600 hover:text-brand-700">Unduh</a>
                    </li>
                @empty
                    <li class="text-ink-muted">Belum ada bukti yang diunggah.</li>
                @endforelse
            </ul>
        </x-ui.card>

        {{-- History + Remake --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-ui.card title="Riwayat QC">
                <ul class="space-y-2 text-sm">
                    @forelse ($history as $review)
                        <li class="border-b border-hairline pb-2">
                            <p class="font-medium text-navy">{{ $statusLabels[$review->result] ?? ($review->result ?? 'Sedang Direview') }} <span class="text-xs text-ink-muted">oleh {{ $review->inspector?->name }}</span></p>
                            <p class="text-ink-soft">{{ format_datetime_id($review->completed_at ?? $review->started_at) }}</p>
                        </li>
                    @empty
                        <li class="text-ink-muted">Belum ada riwayat QC.</li>
                    @endforelse
                </ul>
            </x-ui.card>
            <x-ui.card title="Permintaan Perbaikan">
                <ul class="space-y-2 text-sm">
                    @forelse ($remakeRequests as $remake)
                        <li class="border-b border-hairline pb-2">
                            <p class="font-medium text-navy">{{ $remake->reason }} <span class="text-xs text-ink-muted">({{ $statusLabels[$remake->status] ?? $remake->status }})</span></p>
                            <p class="text-ink-soft">{{ format_datetime_id($remake->requested_at) }} · {{ $remake->requestedBy?->name }}</p>
                            @if ($remake->notes)<p class="text-ink-soft">{{ $remake->notes }}</p>@endif
                        </li>
                    @empty
                        <li class="text-ink-muted">Belum ada permintaan perbaikan.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>

        {{-- Status + Audit --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-ui.card title="Timeline Status">
                <ul class="space-y-2 text-sm">
                    @forelse ($order->statusLogs->sortByDesc('changed_at') as $log)
                        <li class="border-b border-hairline pb-2">
                            <p class="font-medium text-navy">{{ $log->old_status ? ($statusLabels[$log->old_status] ?? $log->old_status).' -> ' : '' }}{{ $statusLabels[$log->new_status] ?? $log->new_status }}</p>
                            <p class="text-ink-soft">{{ format_datetime_id($log->changed_at) }} · {{ $log->changedBy?->name ?? 'Sistem' }}</p>
                        </li>
                    @empty
                        <li class="text-ink-muted">Belum ada riwayat status.</li>
                    @endforelse
                </ul>
            </x-ui.card>
            <x-ui.card title="Log Audit">
                <ul class="space-y-2 text-sm">
                    @forelse ($auditLogs as $log)
                        <li class="border-b border-hairline pb-2">
                            <p class="font-medium text-navy">{{ $log->action }}</p>
                            <p class="text-ink-soft">{{ format_datetime_id($log->performed_at) }} · {{ $log->performer?->name ?? 'Sistem' }}</p>
                        </li>
                    @empty
                        <li class="text-ink-muted">Belum ada catatan audit.</li>
                    @endforelse
                </ul>
                <div class="mt-3">{{ $auditLogs->links() }}</div>
            </x-ui.card>
        </div>
    </div>
</x-settings-shell>
