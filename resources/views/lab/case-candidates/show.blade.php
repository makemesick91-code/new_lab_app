<x-settings-shell title="Detail Kandidat Lab">
    @php
        $statusLabels = [
            'pending_review'          => 'Menunggu Review',
            'converted_to_lab_order'  => 'Sudah Dikonversi',
            'rejected'                => 'Ditolak',
            'cancelled'               => 'Dibatalkan',
        ];
        $statusTones = [
            'pending_review'          => 'warning',
            'converted_to_lab_order'  => 'success',
            'rejected'                => 'danger',
            'cancelled'               => 'neutral',
        ];
    @endphp

    <div class="space-y-6">

        {{-- Page header --}}
        <div class="flex items-start justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">RME → Lab</p>
                <h2 class="mt-1 text-xl font-semibold text-gray-900">Detail Kandidat Pekerjaan Lab</h2>
                <p class="mt-1 text-sm text-gray-500">Kandidat dihasilkan otomatis dari item invoice RME yang lunas.</p>
            </div>
            <x-ui.button variant="neutral" :href="route('lab-case-candidates.index')">&larr; Kembali</x-ui.button>
        </div>

        {{-- ID + Status --}}
        <x-ui.card>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-gray-500">ID Kandidat</p>
                    <p class="font-mono text-lg font-semibold text-gray-900">#{{ $candidate->id }}</p>
                    <p class="mt-1 text-xs text-gray-500">Dibuat: {{ $candidate->created_at?->format('d/m/Y H:i') }}</p>
                </div>
                <x-ui.badge :tone="$statusTones[$candidate->status] ?? 'neutral'" class="text-sm px-3 py-1">
                    {{ $statusLabels[$candidate->status] ?? $candidate->status }}
                </x-ui.badge>
            </div>
        </x-ui.card>

        {{-- Patient & Visit --}}
        <x-ui.card title="Pasien &amp; Kunjungan">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-gray-500">Nama Pasien</dt>
                    <dd class="font-medium text-gray-900">{{ $candidate->patient?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">No. Rekam Medis</dt>
                    <dd class="font-mono font-medium text-gray-900">{{ $candidate->patient?->medical_record_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Dokter</dt>
                    <dd class="text-gray-900">{{ $candidate->doctor?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Cabang</dt>
                    <dd class="text-gray-900">{{ $candidate->branch?->name ?? '—' }}</dd>
                </div>
                @if ($candidate->clinicVisit)
                    <div>
                        <dt class="text-gray-500">No. Kunjungan</dt>
                        <dd class="font-mono font-medium text-gray-900">{{ $candidate->clinicVisit->visit_number ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Tanggal Kunjungan</dt>
                        <dd class="text-gray-900">{{ $candidate->clinicVisit->created_at?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        {{-- RME Invoice --}}
        <x-ui.card title="Invoice RME">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-gray-500">No. Invoice</dt>
                    <dd class="font-mono font-medium text-gray-900">
                        {{ $candidate->rmeInvoice?->invoice_number ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500">Status Invoice</dt>
                    <dd class="text-gray-900">{{ $candidate->rmeInvoice?->status ?? '—' }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-gray-500">Deskripsi Item</dt>
                    <dd class="text-gray-900">{{ $candidate->source_description ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        {{-- Tindakan & Estimasi --}}
        <x-ui.card title="Tindakan &amp; Estimasi">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-gray-500">Tindakan</dt>
                    <dd class="text-gray-900">{{ $candidate->treatment?->name ?? $candidate->source_description ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Qty</dt>
                    <dd class="text-gray-900">{{ $candidate->quantity }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Estimasi Harga</dt>
                    <dd class="font-semibold text-gray-900">
                        Rp {{ number_format((float) $candidate->estimated_price, 0, ',', '.') }}
                    </dd>
                </div>
            </dl>
        </x-ui.card>

        {{-- Converted Lab Order (if applicable) --}}
        @if ($candidate->converted_lab_order_id)
            <x-ui.card title="Lab Order Terkait">
                <p class="text-sm text-gray-600">
                    Kandidat ini telah dikonversi ke Lab Order
                    <span class="font-mono font-medium text-gray-900">#{{ $candidate->converted_lab_order_id }}</span>.
                </p>
            </x-ui.card>
        @endif

        {{-- Notes --}}
        @if ($candidate->notes)
            <x-ui.card title="Catatan">
                <p class="text-sm text-gray-700">{{ $candidate->notes }}</p>
            </x-ui.card>
        @endif

    </div>
</x-settings-shell>
