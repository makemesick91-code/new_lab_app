@php
    $statusLabels = [
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
        'DONE' => 'Selesai',
        'REASSIGNED' => 'Dialihkan',
        'PENDING' => 'Menunggu',
        'SKIPPED' => 'Dilewati',
    ];
    $priorityLabels = [
        'NORMAL' => 'Normal',
        'URGENT' => 'Mendesak',
        'SUPER_URGENT' => 'Sangat Mendesak',
    ];
    $workLogLabels = [
        'WORK_STARTED' => 'Pekerjaan dimulai',
        'WORK_PAUSED' => 'Pekerjaan dijeda',
        'WORK_RESUMED' => 'Pekerjaan dilanjutkan',
        'WORK_COMPLETED' => 'Pekerjaan selesai',
        'STATUS_CHANGED' => 'Status berubah',
    ];
@endphp

<x-settings-shell title="Detail Produksi">
    <x-ui.page-header title="Detail Produksi">
        <x-slot:breadcrumb>Lab / Papan Produksi / {{ $order->order_number }}</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('production.board')">&larr; Kembali ke papan</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">
        {{-- Header + summary --}}
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
                <div><dt class="text-ink-soft">Tenggat</dt><dd class="font-medium text-navy">{{ format_date_id($order->due_date, '—') }}</dd></div>
                <div><dt class="text-ink-soft">Teknisi Aktif</dt><dd class="font-medium text-navy">{{ $activeAssignment?->technician?->name ?? 'Belum ditugaskan' }}</dd></div>
            </dl>
        </x-ui.card>

        {{-- Action panel --}}
        <x-ui.card title="Aksi">
            <div class="flex flex-wrap gap-4">
                @can('production.assign', $order)
                    <form method="POST" action="{{ route('production.assign', $order) }}" class="flex flex-wrap items-end gap-2 rounded-lg border border-hairline p-3">
                        @csrf
                        <div>
                            <label class="block text-xs text-ink-soft">Tugaskan Teknisi</label>
                            <select name="technician_id" class="mt-1 rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                                @foreach ($technicians as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                            </select>
                        </div>
                        <input type="text" name="notes" placeholder="Catatan (opsional)" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <x-ui.button type="submit" size="sm">Tugaskan</x-ui.button>
                    </form>
                @endcan

                @can('production.reassign', $order)
                    <form method="POST" action="{{ route('production.reassign', $order) }}" class="flex flex-wrap items-end gap-2 rounded-lg border border-hairline p-3">
                        @csrf
                        <div>
                            <label class="block text-xs text-ink-soft">Ganti ke Teknisi</label>
                            <select name="technician_id" class="mt-1 rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                                @foreach ($technicians as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
                            </select>
                        </div>
                        <input type="text" name="reason" placeholder="Alasan (minimal 5 karakter)" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <x-ui.button type="submit" variant="warning" size="sm">Ganti Teknisi</x-ui.button>
                    </form>
                @endcan

                @can('production.start', $order)
                    <form method="POST" action="{{ route('production.start', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Catatan" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <x-ui.button type="submit" variant="success" size="sm">Mulai Pekerjaan</x-ui.button>
                    </form>
                @endcan

                @can('production.pause', $order)
                    <form method="POST" action="{{ route('production.pause', $order) }}" class="flex flex-wrap items-end gap-2 rounded-lg border border-hairline p-3">
                        @csrf
                        <select name="hold_reason" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="">Alasan jeda</option>
                            @foreach ($holdReasons as $hr)<option value="{{ $hr }}">{{ $hr }}</option>@endforeach
                        </select>
                        <input type="text" name="reason" placeholder="Alasan (minimal 5)" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <x-ui.button type="submit" variant="warning" size="sm">Jeda</x-ui.button>
                    </form>
                @endcan

                @can('production.resume', $order)
                    <form method="POST" action="{{ route('production.resume', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Catatan" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <x-ui.button type="submit" variant="success" size="sm">Lanjutkan</x-ui.button>
                    </form>
                @endcan

                @can('production.complete', $order)
                    <form method="POST" action="{{ route('production.complete', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Catatan" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <x-ui.button type="submit" size="sm">Selesaikan Pekerjaan</x-ui.button>
                    </form>
                @endcan

                @can('production.sendToQc', $order)
                    <form method="POST" action="{{ route('production.send-to-qc', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Catatan" class="rounded-lg border-hairline text-sm focus:border-brand-500 focus:ring-brand-500" />
                        <x-ui.button type="submit" variant="neutral" size="sm">Kirim ke QC</x-ui.button>
                    </form>
                @endcan
            </div>
        </x-ui.card>

        {{-- Production steps --}}
        <x-ui.card title="Tahap Produksi">
            <x-ui.table class="mt-1">
                <thead class="bg-navy-50 text-ink"><tr>
                    <th class="px-3 py-2 text-left font-medium">Tahap</th>
                    <th class="px-3 py-2 text-left font-medium">Status</th>
                    <th class="px-3 py-2 text-left font-medium">Perbarui</th>
                </tr></thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($steps as $step)
                        <tr>
                            <td class="px-3 py-2 font-medium text-navy">{{ $step->step_name }}</td>
                            <td class="px-3 py-2"><x-lab.status-badge :status="$step->status" /></td>
                            <td class="px-3 py-2">
                                @can('production.steps.update', $order)
                                    <form method="POST" action="{{ route('production.steps.update', [$order, $step]) }}" class="flex items-center gap-2">
                                        @csrf @method('PATCH')
                                        <select name="status" class="rounded-lg border-hairline text-xs focus:border-brand-500 focus:ring-brand-500">
                                            @foreach ($stepStatuses as $s)<option value="{{ $s }}" @selected($step->status === $s)>{{ $statusLabels[$s] ?? $s }}</option>@endforeach
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
        </x-ui.card>

        {{-- Assignment history + Work log timeline --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-ui.card title="Riwayat Penugasan">
                <ul class="space-y-2 text-sm">
                    @forelse ($assignmentHistory as $a)
                        <li class="flex items-center justify-between border-b border-hairline pb-2">
                            <span class="font-medium text-navy">{{ $a->technician?->name }}</span>
                            <span class="text-ink-soft">{{ $statusLabels[$a->status] ?? $a->status }} · {{ format_datetime_id($a->assigned_at) }}</span>
                        </li>
                    @empty
                        <li class="text-ink-muted">Belum ada penugasan.</li>
                    @endforelse
                </ul>
            </x-ui.card>

            <x-ui.card title="Timeline Log Pekerjaan">
                <x-slot:actions>
                    <a href="{{ route('production.work-logs.index', $order) }}" class="text-xs text-brand-600 hover:text-brand-700">Lihat semua</a>
                </x-slot:actions>
                <ul class="space-y-2 text-sm">
                    @forelse ($workLogs as $log)
                        <li class="border-b border-hairline pb-2">
                            <p class="font-medium text-navy">{{ $workLogLabels[$log->event_type] ?? $log->event_type }} <span class="text-xs text-ink-muted">({{ $log->duration_minutes }} menit)</span></p>
                            <p class="text-ink-soft">{{ format_datetime_id($log->created_at) }} · {{ $log->performedBy?->name }}</p>
                            @if ($log->notes)<p class="text-ink-soft">{{ $log->notes }}</p>@endif
                        </li>
                    @empty
                        <li class="text-ink-muted">Belum ada log pekerjaan.</li>
                    @endforelse
                </ul>
            </x-ui.card>
        </div>

        {{-- Status timeline + Audit log --}}
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
