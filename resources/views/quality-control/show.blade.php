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
    <div class="space-y-6">
        {{-- Summary --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-gray-500">Nomor Order</p>
                    <p class="text-lg font-semibold text-gray-900">{{ $order->order_number }}</p>
                    <span class="mt-1 inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">{{ $statusLabels[$order->status] ?? $order->status }}</span>
                </div>
                <a href="{{ route('quality-control.queue') }}" class="text-sm text-gray-500 hover:text-gray-700">Kembali ke antrean</a>
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500">Klinik</dt><dd class="font-medium">{{ $order->clinic?->name }}</dd></div>
                <div><dt class="text-gray-500">Dokter</dt><dd class="font-medium">{{ $order->doctor?->name }}</dd></div>
                <div><dt class="text-gray-500">Pasien</dt><dd class="font-medium">{{ $order->patient?->name ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Prioritas</dt><dd class="font-medium">{{ $priorityLabels[$order->priority] ?? $order->priority }}</dd></div>
                <div><dt class="text-gray-500">Teknisi Produksi</dt><dd class="font-medium">{{ $activeAssignment?->technician?->name ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Pemeriksa QC Aktif</dt><dd class="font-medium">{{ $activeReview?->inspector?->name ?? '—' }}</dd></div>
            </dl>
        </div>

        {{-- QC actions --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
            <h3 class="font-semibold text-gray-800">Aksi QC</h3>
            <div class="flex flex-wrap gap-3">
                @can('qc.start', $order)
                    <form method="POST" action="{{ route('quality-control.start', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Catatan" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700">Mulai Review</button>
                    </form>
                @endcan
                @can('qc.pass', $order)
                    <form method="POST" action="{{ route('quality-control.pass', $order) }}" class="flex items-end gap-2">
                        @csrf<input type="text" name="notes" placeholder="Catatan" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-500">Lulus QC</button>
                    </form>
                @endcan
                @can('qc.reject', $order)
                    <form method="POST" action="{{ route('quality-control.reject', $order) }}" class="flex flex-wrap items-end gap-2 rounded-md border border-gray-200 p-3">
                        @csrf
                        <select name="result" class="rounded-md border-gray-300 text-sm">
                            <option value="REJECTED">Ditolak</option>
                            <option value="REVISION">Revisi</option>
                        </select>
                        <select name="reason" class="rounded-md border-gray-300 text-sm">
                            @foreach ($remakeReasons as $reason)<option value="{{ $reason }}">{{ $reason }}</option>@endforeach
                        </select>
                        <input type="text" name="notes" placeholder="Catatan (wajib)" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-500">Tolak QC</button>
                    </form>
                @endcan
                @can('qc.requestRemake', $order)
                    <form method="POST" action="{{ route('quality-control.remake', $order) }}" class="flex flex-wrap items-end gap-2 rounded-md border border-gray-200 p-3">
                        @csrf
                        <select name="reason" class="rounded-md border-gray-300 text-sm">
                            @foreach ($remakeReasons as $reason)<option value="{{ $reason }}">{{ $reason }}</option>@endforeach
                        </select>
                        <input type="text" name="notes" placeholder="Catatan (wajib)" class="rounded-md border-gray-300 text-sm" />
                        <button class="rounded-md bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-500">Minta Perbaikan</button>
                    </form>
                @endcan
            </div>
        </div>

        {{-- Checklist panel --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800">Checklist QC</h3>
            @if ($activeReview)
                <table class="mt-3 min-w-full divide-y divide-gray-200 text-sm">
                    <thead><tr class="text-left text-gray-500">
                        <th class="px-3 py-2 font-medium">Item</th>
                        <th class="px-3 py-2 font-medium">Hasil</th>
                        <th class="px-3 py-2 font-medium">Perbarui</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($checklists as $item)
                            <tr>
                                <td class="px-3 py-2 font-medium text-gray-900">{{ $item->checklist_item }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ $statusLabels[$item->result] ?? $item->result }}</td>
                                <td class="px-3 py-2">
                                    @can('qc.checklists.update', $item)
                                        <form method="POST" action="{{ route('quality-control.checklists.update', $item) }}" class="flex items-center gap-2">
                                            @csrf @method('PATCH')
                                            <select name="result" class="rounded-md border-gray-300 text-xs">
                                                @foreach ($checklistResults as $r)<option value="{{ $r }}" @selected($item->result === $r)>{{ $statusLabels[$r] ?? ($r === 'N_A' ? 'N/A' : $r) }}</option>@endforeach
                                            </select>
                                            <input type="text" name="notes" placeholder="Catatan" class="rounded-md border-gray-300 text-xs" />
                                            <button class="text-indigo-600 hover:text-indigo-500 text-xs">Simpan</button>
                                        </form>
                                    @else
                                        <span class="text-gray-400 text-xs">—</span>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="mt-2 text-sm text-gray-400">Mulai review QC untuk memuat checklist.</p>
            @endif
        </div>

        {{-- Evidence panel --}}
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3">
            <h3 class="font-semibold text-gray-800">Bukti QC</h3>
            @can('qc.uploadEvidence', $order)
                <form method="POST" action="{{ route('quality-control.evidence.store', $order) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-2 rounded-md border border-gray-200 p-3">
                    @csrf
                    <select name="category" class="rounded-md border-gray-300 text-sm">
                        @foreach ($evidenceCategories as $cat)<option value="{{ $cat }}">{{ $cat }}</option>@endforeach
                    </select>
                    <input type="file" name="file" class="text-sm" />
                    <button class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500">Unggah</button>
                </form>
            @endcan
            <ul class="space-y-1 text-sm">
                @forelse ($order->attachments as $attachment)
                    <li class="flex items-center justify-between border-b border-gray-100 pb-1">
                        <span class="text-gray-900">{{ $attachment->file_name }} <span class="text-xs text-gray-400">({{ $attachment->category }})</span></span>
                        <a href="{{ asset('storage/'.$attachment->file_path) }}" target="_blank" class="text-indigo-600 hover:text-indigo-500">Unduh</a>
                    </li>
                @empty
                    <li class="text-gray-400">Belum ada bukti yang diunggah.</li>
                @endforelse
            </ul>
        </div>

        {{-- History + Remake --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Riwayat QC</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($history as $review)
                        <li class="border-b border-gray-100 pb-2">
                            <p class="font-medium text-gray-900">{{ $statusLabels[$review->result] ?? ($review->result ?? 'Sedang Direview') }} <span class="text-xs text-gray-400">oleh {{ $review->inspector?->name }}</span></p>
                            <p class="text-gray-500">{{ optional($review->completed_at ?? $review->started_at)->format('Y-m-d H:i') }}</p>
                        </li>
                    @empty
                        <li class="text-gray-400">Belum ada riwayat QC.</li>
                    @endforelse
                </ul>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Permintaan Perbaikan</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($remakeRequests as $remake)
                        <li class="border-b border-gray-100 pb-2">
                            <p class="font-medium text-gray-900">{{ $remake->reason }} <span class="text-xs text-gray-400">({{ $statusLabels[$remake->status] ?? $remake->status }})</span></p>
                            <p class="text-gray-500">{{ optional($remake->requested_at)->format('Y-m-d H:i') }} · {{ $remake->requestedBy?->name }}</p>
                            @if ($remake->notes)<p class="text-gray-600">{{ $remake->notes }}</p>@endif
                        </li>
                    @empty
                        <li class="text-gray-400">Belum ada permintaan perbaikan.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        {{-- Status + Audit --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Timeline Status</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($order->statusLogs->sortByDesc('changed_at') as $log)
                        <li class="border-b border-gray-100 pb-2">
                            <p class="font-medium text-gray-900">{{ $log->old_status ? ($statusLabels[$log->old_status] ?? $log->old_status).' -> ' : '' }}{{ $statusLabels[$log->new_status] ?? $log->new_status }}</p>
                            <p class="text-gray-500">{{ optional($log->changed_at)->format('Y-m-d H:i') }} · {{ $log->changedBy?->name ?? 'Sistem' }}</p>
                        </li>
                    @empty
                        <li class="text-gray-400">Belum ada riwayat status.</li>
                    @endforelse
                </ul>
            </div>
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <h3 class="font-semibold text-gray-800">Log Audit</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($auditLogs as $log)
                        <li class="border-b border-gray-100 pb-2">
                            <p class="font-medium text-gray-900">{{ $log->action }}</p>
                            <p class="text-gray-500">{{ optional($log->performed_at)->format('Y-m-d H:i') }} · {{ $log->performer?->name ?? 'Sistem' }}</p>
                        </li>
                    @empty
                        <li class="text-gray-400">Belum ada catatan audit.</li>
                    @endforelse
                </ul>
                <div class="mt-3">{{ $auditLogs->links() }}</div>
            </div>
        </div>
    </div>
</x-settings-shell>
