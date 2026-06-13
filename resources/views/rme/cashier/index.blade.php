<x-settings-shell title="Kasir RME">
    @php
        $invoiceStatusLabels = [
            'DRAFT'  => 'Draft',
            'UNPAID' => 'Belum Dibayar',
            'PAID'   => 'Lunas',
            'VOID'   => 'Dibatalkan',
        ];
        $invoiceStatusTone = [
            'DRAFT'  => 'info',
            'UNPAID' => 'warning',
            'PAID'   => 'success',
            'VOID'   => 'danger',
        ];
        $hasFilter = !empty($filters['search']);
    @endphp

    <div class="space-y-6">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-teal-700">Rekam Medis Elektronik</p>
            <h2 class="mt-1 text-xl font-semibold text-gray-900">Kasir RME</h2>
            <p class="mt-1 text-sm text-gray-500">Daftar kunjungan yang sudah selesai pemeriksaan dokter dan menunggu billing kasir.</p>
        </div>

        <x-ui.card padding="p-4">
            <form method="GET" action="{{ route('rme.cashier.index') }}">
                <div class="flex flex-wrap items-end gap-2">
                    <div class="min-w-[12rem] flex-1">
                        <label for="cashier-search" class="text-sm font-medium text-gray-700">Cari kunjungan</label>
                        <input id="cashier-search" type="text" name="search" value="{{ $filters['search'] ?? '' }}"
                               placeholder="Cari nomor kunjungan atau nama pasien"
                               class="mt-1 block w-full rounded-lg border-gray-300 text-sm focus:border-teal-500 focus:ring-teal-500" />
                    </div>
                    <x-ui.button type="submit" variant="neutral">Terapkan</x-ui.button>
                    @if ($hasFilter)
                        <x-ui.button variant="secondary" :href="route('rme.cashier.index')">Atur Ulang</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>

        <x-ui.card padding="">
            <div class="border-b border-gray-200 px-4 py-3">
                <h3 class="text-base font-semibold text-gray-900">Daftar Kunjungan Siap Ditagih</h3>
                <p class="text-sm text-gray-500">{{ $visits->total() }} kunjungan ditemukan.</p>
            </div>

            <x-ui.table>
                <thead class="bg-gray-50">
                    <tr class="text-left text-gray-500">
                        <th scope="col" class="px-4 py-3 font-medium">No. Kunjungan</th>
                        <th scope="col" class="px-3 py-3 font-medium">Pasien</th>
                        <th scope="col" class="px-3 py-3 font-medium">Klinik/Cabang</th>
                        <th scope="col" class="px-3 py-3 font-medium">Dokter</th>
                        <th scope="col" class="px-3 py-3 font-medium">Layanan Awal</th>
                        <th scope="col" class="px-3 py-3 font-medium">Status Tagihan</th>
                        <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($visits as $visit)
                        @php
                            $invoice = $visit->rmeInvoice ?? null;
                            $hasInvoice = $invoice !== null;
                        @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $visit->visit_number }}</td>
                            <td class="px-3 py-3 font-medium text-gray-900">
                                {{ $visit->patient?->name ?? '—' }}
                                @if ($visit->patient?->medical_record_number)
                                    <span class="block font-mono text-xs font-normal text-gray-400">{{ $visit->patient->medical_record_number }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-gray-600">{{ $visit->branch ? $visit->branch->code.' — '.$visit->branch->name : '—' }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $visit->doctor?->name ?? '—' }}</td>
                            <td class="px-3 py-3 text-gray-600">{{ $visit->initialTreatment?->name ?? '—' }}</td>
                            <td class="px-3 py-3">
                                @if ($hasInvoice)
                                    <x-ui.badge :tone="$invoiceStatusTone[$invoice->status] ?? 'neutral'">
                                        {{ $invoiceStatusLabels[$invoice->status] ?? $invoice->status }}
                                    </x-ui.badge>
                                @else
                                    <x-ui.badge tone="warning">Belum Ditagih</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    @if ($hasInvoice)
                                        <x-ui.button variant="secondary" :href="route('rme.cashier.show', [$visit, $invoice])" class="!px-3 !py-1.5 !text-xs">Lihat Tagihan</x-ui.button>
                                    @else
                                        <x-ui.button variant="primary" :href="route('rme.cashier.create', $visit)" class="!px-3 !py-1.5 !text-xs">Buat Tagihan</x-ui.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center">
                                <p class="text-sm font-medium text-gray-900">Belum ada kunjungan menunggu billing.</p>
                                <p class="mt-1 text-sm text-gray-500">Finalisasi RME dokter terlebih dahulu agar kunjungan masuk ke antrean kasir.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </x-ui.table>

            @if ($visits->hasPages())
                <div class="border-t border-gray-200 px-4 py-3">
                    {{ $visits->links() }}
                </div>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
