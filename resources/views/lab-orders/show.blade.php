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
    $noteLabels = [
        'Order created' => 'Order dibuat',
    ];
@endphp

@php
    /** LAB-WORKFLOW-V2 Phase 5 — legacy labeling (server gate lives in LabOrderService). */
    $labV2Active = app(App\Modules\LabOrder\Services\LabWorkflowResolver::class)->isV2Active();
@endphp
<x-settings-shell title="Detail Order Lab">
    <x-ui.page-header title="Detail Order Lab">
        <x-slot:breadcrumb>Lab / Order Lab / {{ $order->order_number }}</x-slot:breadcrumb>
        <x-slot:actions>
            @if ($order->isV2Workflow())
                <x-ui.badge tone="primary">Lab Workflow V2</x-ui.badge>
            @elseif ($labV2Active)
                <x-ui.badge tone="warning">Legacy Workflow</x-ui.badge>
            @endif
            @can('update', $order)
                <x-ui.button variant="secondary" :href="route('lab-orders.edit', $order)">Ubah</x-ui.button>
            @endcan
            @can('cancel', $order)
                <form method="POST" action="{{ route('lab-orders.cancel', $order) }}"
                      onsubmit="var r=prompt('Alasan pembatalan (minimal 5 karakter):'); if(!r||r.length<5){return false;} this.reason.value=r;">
                    @csrf
                    <input type="hidden" name="reason" />
                    <x-ui.button variant="danger" type="submit">Batalkan Order</x-ui.button>
                </form>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="ui-card" x-data="{ tab: 'overview' }">
        {{-- Header --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline p-6">
            <div>
                <p class="text-sm text-ink-soft">Nomor Order</p>
                <p class="text-lg font-semibold text-navy">{{ $order->order_number }}</p>
                <div class="mt-1"><x-lab.status-badge :status="$order->status" /></div>
            </div>
        </div>

        {{-- Tabs --}}
        @php($tabs = ['overview' => 'Ringkasan', 'items' => 'Item', 'attachments' => 'Lampiran', 'timeline' => 'Timeline', 'audit' => 'Log Audit'])
        @php($placeholderTabs = ['Penugasan', 'Produksi', 'QC', 'Pengiriman', 'Invoice'])
        <div class="flex flex-wrap gap-1 border-b border-hairline px-4">
            @foreach ($tabs as $key => $label)
                <button type="button" @click="tab = '{{ $key }}'"
                        :class="tab === '{{ $key }}' ? 'border-brand-600 text-brand-600' : 'border-transparent text-ink-soft hover:text-ink'"
                        class="border-b-2 px-3 py-2 text-sm font-medium">{{ $label }}</button>
            @endforeach
            @foreach ($placeholderTabs as $label)
                <button type="button" disabled class="cursor-not-allowed border-b-2 border-transparent px-3 py-2 text-sm font-medium text-ink-muted/60" title="Akan tersedia pada sprint berikutnya">{{ $label }}</button>
            @endforeach
        </div>

        <div class="p-6">
            {{-- Overview --}}
            <div x-show="tab === 'overview'" class="space-y-6">
            @if ($rmeSourceCandidate)
                <div class="rounded-lg border border-brand-200 bg-brand-50/50 p-4">
                    <h3 class="text-sm font-semibold text-brand-800">Sumber RME</h3>
                    <p class="mt-1 text-xs text-brand-700">Lab order ini dibuat dari kandidat pekerjaan lab RME.</p>
                    <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-brand-700">Kandidat</dt>
                            <dd class="font-medium text-navy">
                                @can('view', $rmeSourceCandidate)
                                    <a href="{{ route('lab-case-candidates.show', $rmeSourceCandidate) }}"
                                       class="text-brand-700 hover:text-brand-800 hover:underline">
                                        Kandidat #{{ $rmeSourceCandidate->id }}
                                    </a>
                                @else
                                    Kandidat #{{ $rmeSourceCandidate->id }}
                                @endcan
                            </dd>
                        </div>
                        <div>
                            <dt class="text-brand-700">Invoice RME</dt>
                            <dd class="font-mono font-medium text-navy">
                                @if ($rmeSourceCandidate->rmeInvoice && $rmeSourceCandidate->clinicVisit)
                                    @can('view', $rmeSourceCandidate->rmeInvoice)
                                        <a href="{{ route('rme.cashier.show', [$rmeSourceCandidate->clinicVisit, $rmeSourceCandidate->rmeInvoice]) }}"
                                           class="text-brand-700 hover:text-brand-800 hover:underline">
                                            {{ $rmeSourceCandidate->rmeInvoice->invoice_number }}
                                        </a>
                                    @else
                                        {{ $rmeSourceCandidate->rmeInvoice->invoice_number }}
                                    @endcan
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-brand-700">Pasien</dt>
                            <dd class="font-medium text-navy">{{ $rmeSourceCandidate->patient?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-brand-700">Dokter</dt>
                            <dd class="font-medium text-navy">{{ $rmeSourceCandidate->doctor?->name ?? '—' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-brand-700">Tindakan Sumber</dt>
                            <dd class="font-medium text-navy">{{ $rmeSourceCandidate->source_description ?? $rmeSourceCandidate->treatment?->name ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>
            @endif
            <div class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-ink-soft">Klinik</dt><dd class="font-medium">{{ $order->clinic?->name }}</dd></div>
                <div><dt class="text-ink-soft">Dokter</dt><dd class="font-medium">{{ $order->doctor?->name }}</dd></div>
                <div><dt class="text-ink-soft">Pasien</dt><dd class="font-medium">{{ $order->patient?->name ?? '—' }}</dd></div>
                <div><dt class="text-ink-soft">Nomor RM</dt><dd class="font-medium">{{ $order->medical_record_number ?? '-' }}</dd></div>
                <div><dt class="text-ink-soft">Prioritas</dt><dd class="font-medium">{{ $priorityLabels[$order->priority] ?? $order->priority }}</dd></div>
                <div><dt class="text-ink-soft">Tanggal Order</dt><dd class="font-medium">{{ format_date_id($order->order_date) }}</dd></div>
                <div><dt class="text-ink-soft">Tenggat</dt><dd class="font-medium">{{ format_date_id($order->due_date, '—') }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-ink-soft">Catatan</dt><dd class="font-medium">{{ $order->notes ?? '—' }}</dd></div>
                <div><dt class="text-ink-soft">Dibuat Oleh</dt><dd class="font-medium">{{ $order->creator?->name ?? '—' }}</dd></div>
            </div>
            </div>

            {{-- Items --}}
            <div x-show="tab === 'items'" style="display:none;" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-hairline text-sm">
                    <thead><tr class="text-left text-ink-soft">
                        <th class="px-3 py-2 font-medium">Layanan</th>
                        <th class="px-3 py-2 font-medium">Gigi</th>
                        <th class="px-3 py-2 font-medium">Warna Gigi</th>
                        <th class="px-3 py-2 font-medium">Material</th>
                        <th class="px-3 py-2 font-medium text-right">Jumlah</th>
                        <th class="px-3 py-2 font-medium text-right">Harga Satuan</th>
                        <th class="px-3 py-2 font-medium text-right">Subtotal</th>
                    </tr></thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="px-3 py-2 font-medium text-navy">{{ $item->labService?->name }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ $item->tooth_number ?? '—' }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ $item->shade_color_text ?? '—' }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ $item->material_text ?? '—' }}</td>
                                <td class="px-3 py-2 text-right text-ink-soft">{{ format_quantity_id($item->quantity) }}</td>
                                <td class="px-3 py-2 text-right text-ink-soft">{{ format_currency_id($item->unit_price) }}</td>
                                <td class="px-3 py-2 text-right text-ink-soft">{{ format_currency_id($item->subtotal) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Attachments --}}
            <div x-show="tab === 'attachments'" style="display:none;" class="space-y-4">
                @can('uploadAttachment', $order)
                    <form method="POST" action="{{ route('lab-orders.attachments.upload', $order) }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3 rounded-md border border-hairline p-3">
                        @csrf
                        <div>
                            <label class="block text-xs text-ink-soft">Kategori</label>
                            <select name="category" class="mt-1 rounded-md border-hairline text-sm">
                                @foreach (App\Modules\LabOrder\Models\Attachment::CATEGORIES as $cat)
                                    <option value="{{ $cat }}">{{ $cat }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-ink-soft">File (jpg, jpeg, png, pdf, stl - maksimal 10MB)</label>
                            <input type="file" name="file" class="mt-1 block text-sm" />
                        </div>
                        <x-ui.button type="submit" size="sm">Unggah</x-ui.button>
                    </form>
                @endcan

                <table class="min-w-full divide-y divide-hairline text-sm">
                    <thead><tr class="text-left text-ink-soft">
                        <th class="px-3 py-2 font-medium">File</th>
                        <th class="px-3 py-2 font-medium">Kategori</th>
                        <th class="px-3 py-2 font-medium">Diunggah Oleh</th>
                        <th class="px-3 py-2 font-medium text-right">Aksi</th>
                    </tr></thead>
                    <tbody class="divide-y divide-hairline">
                        @forelse ($order->attachments as $attachment)
                            <tr>
                                <td class="px-3 py-2 text-navy">{{ $attachment->file_name }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ $attachment->category }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ $attachment->uploader?->name ?? '—' }}</td>
                                <td class="px-3 py-2">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('attachments.download', $attachment) }}" target="_blank" class="text-brand-600 hover:text-brand-700">Unduh</a>
                                        @can('deleteAttachment', $order)
                                            <form method="POST" action="{{ route('lab-orders.attachments.destroy', [$order, $attachment]) }}" onsubmit="return confirm('Hapus lampiran ini?');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-danger hover:text-danger-700">Hapus</button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-ink-muted">Belum ada lampiran.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Timeline --}}
            <div x-show="tab === 'timeline'" style="display:none;" class="space-y-3">
                @forelse ($order->statusLogs->sortByDesc('changed_at') as $log)
                    <div class="flex items-start gap-3 text-sm">
                        <div class="mt-1 h-2 w-2 rounded-full bg-brand-500"></div>
                        <div>
                            <p class="font-medium text-navy">{{ $log->old_status ? ($statusLabels[$log->old_status] ?? $log->old_status).' -> ' : '' }}{{ $statusLabels[$log->new_status] ?? $log->new_status }}</p>
                            <p class="text-ink-soft">{{ format_datetime_id($log->changed_at) }} · {{ $log->changedBy?->name ?? 'Sistem' }}</p>
                            @if ($log->notes)<p class="text-ink-soft">{{ $noteLabels[$log->notes] ?? $log->notes }}</p>@endif
                        </div>
                    </div>
                @empty
                    <p class="text-ink-muted">Belum ada riwayat status.</p>
                @endforelse
            </div>

            {{-- Audit Log --}}
            <div x-show="tab === 'audit'" style="display:none;" class="space-y-3">
                <table class="min-w-full divide-y divide-hairline text-sm">
                    <thead><tr class="text-left text-ink-soft">
                        <th class="px-3 py-2 font-medium">Aksi</th>
                        <th class="px-3 py-2 font-medium">Oleh</th>
                        <th class="px-3 py-2 font-medium">Waktu</th>
                    </tr></thead>
                    <tbody class="divide-y divide-hairline">
                        @forelse ($auditLogs as $log)
                            <tr>
                                <td class="px-3 py-2 font-medium text-navy">{{ $log->action }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ $log->performer?->name ?? 'Sistem' }}</td>
                                <td class="px-3 py-2 text-ink-soft">{{ format_datetime_id($log->performed_at) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-6 text-center text-ink-muted">Belum ada catatan audit.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div>{{ $auditLogs->links() }}</div>
            </div>
        </div>
    </div>
</x-settings-shell>
