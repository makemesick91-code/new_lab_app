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

    <x-ui.page-header title="Detail Kandidat Pekerjaan Lab" subtitle="Kandidat dihasilkan otomatis dari item invoice RME yang lunas.">
        <x-slot:breadcrumb>RME → Lab</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('lab-case-candidates.index')">&larr; Kembali</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="space-y-6">

        {{-- ID + Status --}}
        <x-ui.card>
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-ink-soft">ID Kandidat</p>
                    <p class="font-mono text-lg font-semibold text-navy">#{{ $candidate->id }}</p>
                    <p class="mt-1 text-xs text-ink-soft">Dibuat: {{ $candidate->created_at?->format('d/m/Y H:i') }}</p>
                </div>
                <x-ui.badge :tone="$statusTones[$candidate->status] ?? 'neutral'" class="px-3 py-1 text-sm">
                    {{ $statusLabels[$candidate->status] ?? $candidate->status }}
                </x-ui.badge>
            </div>
        </x-ui.card>

        {{-- Patient & Visit --}}
        <x-ui.card title="Pasien &amp; Kunjungan">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-ink-soft">Nama Pasien</dt>
                    <dd class="font-medium text-navy">{{ $candidate->patient?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">No. Rekam Medis</dt>
                    <dd class="font-mono font-medium text-navy">{{ $candidate->patient?->medical_record_number ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Dokter</dt>
                    <dd class="text-navy">{{ $candidate->doctor?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Cabang</dt>
                    <dd class="text-navy">{{ $candidate->branch?->name ?? '—' }}</dd>
                </div>
                @if ($candidate->clinicVisit)
                    <div>
                        <dt class="text-ink-soft">No. Kunjungan</dt>
                        <dd class="font-mono font-medium text-navy">{{ $candidate->clinicVisit->visit_number ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-ink-soft">Tanggal Kunjungan</dt>
                        <dd class="text-navy">{{ $candidate->clinicVisit->created_at?->format('d/m/Y') ?? '—' }}</dd>
                    </div>
                @endif
            </dl>
        </x-ui.card>

        {{-- RME Invoice --}}
        <x-ui.card title="Invoice RME">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-ink-soft">No. Invoice</dt>
                    <dd class="font-mono font-medium text-navy">
                        @if ($candidate->rmeInvoice && $candidate->clinicVisit)
                            @can('view', $candidate->rmeInvoice)
                                <a href="{{ route('rme.cashier.show', [$candidate->clinicVisit, $candidate->rmeInvoice]) }}"
                                   class="text-brand-700 hover:text-brand-800 hover:underline">
                                    {{ $candidate->rmeInvoice->invoice_number }}
                                </a>
                            @else
                                {{ $candidate->rmeInvoice->invoice_number }}
                            @endcan
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Status Invoice</dt>
                    <dd class="text-navy">{{ $candidate->rmeInvoice?->status ?? '—' }}</dd>
                </div>
                @if ($candidate->clinicVisit)
                    <div>
                        <dt class="text-ink-soft">No. Kunjungan</dt>
                        <dd class="font-mono font-medium text-navy">{{ $candidate->clinicVisit->visit_number }}</dd>
                    </div>
                @endif
                <div class="col-span-2">
                    <dt class="text-ink-soft">Deskripsi Item</dt>
                    <dd class="text-navy">{{ $candidate->source_description ?? '—' }}</dd>
                </div>
            </dl>
        </x-ui.card>

        {{-- Tindakan & Estimasi --}}
        <x-ui.card title="Tindakan &amp; Estimasi">
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-ink-soft">Tindakan</dt>
                    <dd class="text-navy">{{ $candidate->treatment?->name ?? $candidate->source_description ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Qty</dt>
                    <dd class="text-navy">{{ $candidate->quantity }}</dd>
                </div>
                <div>
                    <dt class="text-ink-soft">Estimasi Harga</dt>
                    <dd class="font-semibold text-navy">
                        Rp {{ number_format((float) $candidate->estimated_price, 0, ',', '.') }}
                    </dd>
                </div>
            </dl>
        </x-ui.card>

        {{-- Conversion status --}}
        <x-ui.card title="Status Konversi">
            @if ($candidate->isConverted() && $candidate->convertedLabOrder)
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-ink-soft">Lab Order</dt>
                        <dd>
                            @can('view', $candidate->convertedLabOrder)
                                <a href="{{ route('lab-orders.show', $candidate->convertedLabOrder) }}"
                                   class="font-mono font-medium text-brand-700 hover:text-brand-800 hover:underline">
                                    {{ $candidate->convertedLabOrder->order_number }}
                                </a>
                            @else
                                <span class="font-mono font-medium text-navy">{{ $candidate->convertedLabOrder->order_number }}</span>
                            @endcan
                        </dd>
                    </div>
                    @if ($candidate->reviewed_at)
                        <div>
                            <dt class="text-ink-soft">Dikonversi Pada</dt>
                            <dd class="text-navy">{{ $candidate->reviewed_at->format('d/m/Y H:i') }}</dd>
                        </div>
                    @endif
                    @if ($candidate->reviewedBy)
                        <div>
                            <dt class="text-ink-soft">Direview Oleh</dt>
                            <dd class="text-navy">{{ $candidate->reviewedBy->name }}</dd>
                        </div>
                    @endif
                </dl>
            @else
                <x-ui.alert variant="warning">
                    Belum dikonversi — kandidat masih menunggu review dan pemilihan layanan lab.
                </x-ui.alert>
            @endif
        </x-ui.card>

        {{-- Conversion form (Phase 21.4) --}}
        @can('convert', $candidate)
            <x-ui.card title="Konversi ke Lab Order">
                <p class="mb-4 text-sm text-ink-soft">
                    Pilih layanan lab secara eksplisit. Tindakan RME tidak dipetakan otomatis ke layanan lab.
                </p>
                <form method="POST" action="{{ route('lab-case-candidates.convert', $candidate) }}" class="space-y-4">
                    @csrf
                    <x-ui.select id="lab_service_id" name="lab_service_id" label="Layanan Lab" required>
                        <option value="">— Pilih layanan —</option>
                        @foreach ($labServices as $service)
                            <option value="{{ $service->id }}" @selected(old('lab_service_id') == $service->id)>
                                {{ $service->name }} (Rp {{ number_format((float) $service->price, 0, ',', '.') }})
                            </option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input type="date" id="due_date" name="due_date" label="Tenggat" required
                                :value="old('due_date', now()->addDays(7)->toDateString())" min="{{ now()->toDateString() }}" />
                    <x-ui.textarea id="notes" name="notes" label="Catatan (opsional)" rows="3">{{ old('notes') }}</x-ui.textarea>
                    <div class="flex justify-end">
                        <x-ui.button type="submit" variant="primary">Konversi ke Lab Order</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endcan

        {{-- Notes --}}
        @if ($candidate->notes)
            <x-ui.card title="Catatan">
                <p class="text-sm text-ink">{{ $candidate->notes }}</p>
            </x-ui.card>
        @endif

    </div>
</x-settings-shell>
