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
                    <a href="{{ route('lab-orders.show', $candidate->converted_lab_order_id) }}"
                       class="font-mono font-medium text-teal-700 hover:text-teal-900">
                        #{{ $candidate->converted_lab_order_id }}
                    </a>.
                </p>
            </x-ui.card>
        @endif

        {{-- Conversion form (Phase 21.4) --}}
        @can('convert', $candidate)
            <x-ui.card title="Konversi ke Lab Order">
                <p class="mb-4 text-sm text-gray-600">
                    Pilih layanan lab secara eksplisit. Tindakan RME tidak dipetakan otomatis ke layanan lab.
                </p>
                <form method="POST" action="{{ route('lab-case-candidates.convert', $candidate) }}" class="space-y-4">
                    @csrf
                    <div>
                        <label for="lab_service_id" class="block text-sm font-medium text-gray-700">Layanan Lab</label>
                        <select id="lab_service_id" name="lab_service_id" required
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                            <option value="">— Pilih layanan —</option>
                            @foreach ($labServices as $service)
                                <option value="{{ $service->id }}" @selected(old('lab_service_id') == $service->id)>
                                    {{ $service->name }} (Rp {{ number_format((float) $service->price, 0, ',', '.') }})
                                </option>
                            @endforeach
                        </select>
                        @error('lab_service_id')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="due_date" class="block text-sm font-medium text-gray-700">Tenggat</label>
                        <input type="date" id="due_date" name="due_date" required
                               value="{{ old('due_date', now()->addDays(7)->toDateString()) }}"
                               min="{{ now()->toDateString() }}"
                               class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">
                        @error('due_date')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700">Catatan (opsional)</label>
                        <textarea id="notes" name="notes" rows="3"
                                  class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-500 focus:ring-teal-500">{{ old('notes') }}</textarea>
                        @error('notes')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex justify-end">
                        <x-ui.button type="submit" variant="primary">Konversi ke Lab Order</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endcan

        {{-- Notes --}}
        @if ($candidate->notes)
            <x-ui.card title="Catatan">
                <p class="text-sm text-gray-700">{{ $candidate->notes }}</p>
            </x-ui.card>
        @endif

    </div>
</x-settings-shell>
