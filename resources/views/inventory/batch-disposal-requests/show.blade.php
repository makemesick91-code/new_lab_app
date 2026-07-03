<x-settings-shell title="Permintaan Disposal #{{ $disposalRequest->id }}">
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Detail Permintaan</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">
                    Batch {{ $disposalRequest->batch?->batch_number ?? '—' }}
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    @include('inventory.batch-disposal-requests._request-type-badge', ['requestType' => $disposalRequest->request_type])
                    · @include('inventory.batch-disposal-requests._status-badge', ['status' => $disposalRequest->status])
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('inventory.batch-disposal-requests.index') }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-50">Daftar</a>
                @if ($disposalRequest->batch)
                    <a href="{{ route('inventory.batches.show', $disposalRequest->batch) }}" class="inline-flex items-center rounded-lg border border-gray-200 px-4 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50">Batch</a>
                @endif
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800" role="status">{{ session('status') }}</div>
        @endif

        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
            Permintaan ini tidak mengubah stok. Stok hanya berkurang setelah finalisasi adjustment resmi (movement ADJUSTMENT_OUT).
        </div>

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">Informasi Permintaan</h3>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500">Produk</dt><dd class="font-medium text-gray-900">{{ $disposalRequest->product?->name ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Lokasi</dt><dd class="font-medium text-gray-900">{{ $disposalRequest->location?->name ?? '—' }}</dd></div>
                <div><dt class="text-gray-500">Jumlah Diminta</dt><dd class="font-medium tabular-nums text-gray-900">{{ format_quantity_id((float) $disposalRequest->quantity_requested) }}</dd></div>
                <div><dt class="text-gray-500">Snapshot Stok Tersedia (audit)</dt><dd class="font-medium tabular-nums text-gray-900">{{ format_quantity_id((float) ($disposalRequest->available_quantity_snapshot ?? 0)) }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-gray-500">Catatan Bukti</dt><dd class="font-medium text-gray-900">{{ $disposalRequest->evidence_note }}</dd></div>
                @if ($disposalRequest->evidence_reference)
                    <div><dt class="text-gray-500">Referensi Bukti</dt><dd class="font-medium text-gray-900">{{ $disposalRequest->evidence_reference }}</dd></div>
                @endif
            </dl>
        </section>

        <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="text-base font-semibold text-gray-900">Timeline Persetujuan</h3>
            <dl class="mt-4 space-y-3 text-sm">
                <div><dt class="text-gray-500">Diajukan</dt><dd class="text-gray-900">{{ $disposalRequest->submitted_at ? format_datetime_id($disposalRequest->submitted_at).' · '.($disposalRequest->submittedBy?->name ?? '—') : '—' }}</dd></div>
                <div><dt class="text-gray-500">Disetujui</dt><dd class="text-gray-900">{{ $disposalRequest->approved_at ? format_datetime_id($disposalRequest->approved_at).' · '.($disposalRequest->approvedBy?->name ?? '—') : '—' }}</dd></div>
                <div><dt class="text-gray-500">Ditolak</dt><dd class="text-gray-900">
                    @if ($disposalRequest->rejected_at)
                        {{ format_datetime_id($disposalRequest->rejected_at) }} · {{ $disposalRequest->rejectedBy?->name ?? '—' }}
                        @if ($disposalRequest->rejection_reason)
                            <span class="block text-rose-700">{{ $disposalRequest->rejection_reason }}</span>
                        @endif
                    @else — @endif
                </dd></div>
                <div><dt class="text-gray-500">Finalisasi</dt><dd class="text-gray-900">{{ $disposalRequest->finalized_at ? format_datetime_id($disposalRequest->finalized_at).' · '.($disposalRequest->finalizedBy?->name ?? '—') : '—' }}</dd></div>
            </dl>
        </section>

        @if ($disposalRequest->actionLog)
            <section class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm">
                <h3 class="text-base font-semibold text-gray-900">Log Tindakan Terkait</h3>
                <p class="mt-2 text-sm text-gray-700">
                    @include('inventory.batches._batch-action-type-badge', ['actionType' => $disposalRequest->actionLog->action_type])
                    · {{ format_datetime_id($disposalRequest->actionLog->acted_at) }}
                </p>
            </section>
        @endif

        @if ($disposalRequest->movement)
            <section class="rounded-lg border border-emerald-200 bg-emerald-50 p-6 shadow-sm">
                <h3 class="text-base font-semibold text-emerald-900">Movement Ledger Terkait</h3>
                <p class="mt-2 text-sm text-emerald-800">
                    ADJUSTMENT_OUT #{{ $disposalRequest->movement->id }}
                    · {{ format_date_id($disposalRequest->movement->movement_date) }}
                    · keluar {{ format_quantity_id((float) $disposalRequest->movement->quantity_out) }}
                </p>
            </section>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            @can('approve', $disposalRequest)
                <form method="POST" action="{{ route('inventory.batch-disposal-requests.approve', $disposalRequest) }}">
                    @csrf
                    <button type="submit" class="inline-flex rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-500">Setujui</button>
                </form>
            @endcan

            @can('reject', $disposalRequest)
                <form method="POST" action="{{ route('inventory.batch-disposal-requests.reject', $disposalRequest) }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="text" name="rejection_reason" placeholder="Alasan penolakan" required minlength="3" class="rounded-lg border-gray-300 text-sm">
                    <button type="submit" class="inline-flex rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500">Tolak</button>
                </form>
            @endcan

            @can('finalizeAdjustment', $disposalRequest)
                <form method="POST" action="{{ route('inventory.batch-disposal-requests.finalize-adjustment', $disposalRequest) }}" onsubmit="return confirm('Finalisasi akan membuat movement ADJUSTMENT_OUT pada ledger untuk batch dan lokasi ini. Tindakan ini tidak boleh dilakukan tanpa bukti dan approval. Lanjutkan?');">
                    @csrf
                    <button type="submit" class="inline-flex rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-500">Finalisasi Adjustment</button>
                </form>
            @endcan

            @can('cancel', $disposalRequest)
                <form method="POST" action="{{ route('inventory.batch-disposal-requests.cancel', $disposalRequest) }}">
                    @csrf
                    <button type="submit" class="inline-flex rounded-lg bg-gray-600 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-500">Batalkan</button>
                </form>
            @endcan
        </div>
    </div>
</x-settings-shell>
