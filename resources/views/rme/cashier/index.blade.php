<x-settings-shell title="Kasir RME">
    @php
        // Presentation-only labels/tones for invoice status. No business logic here.
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
        <x-ui.page-header
            title="Kasir RME"
            subtitle="Daftar kunjungan yang sudah selesai pemeriksaan dokter dan menunggu billing kasir.">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.cashier.receivables')">Piutang RME</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        @include('rme.partials.cross-branch-rm-lookup')

        {{-- Filter bar (search). Same GET params as before. --}}
        <x-ui.filter-bar :action="route('rme.cashier.index')" method="GET">
            <div class="w-full md:flex-1 md:min-w-[14rem]">
                <x-ui.input
                    label="Cari kunjungan"
                    id="cashier-search"
                    name="search"
                    :value="$filters['search'] ?? ''"
                    placeholder="Cari nomor kunjungan atau nama pasien" />
            </div>
            <x-slot:actions>
                <x-ui.button type="submit" variant="primary">Terapkan</x-ui.button>
                @if ($hasFilter)
                    <x-ui.button variant="secondary" :href="route('rme.cashier.index')">Atur Ulang</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ui.card padding="">
            <div class="border-b border-hairline px-4 py-3">
                <h3 class="text-base font-semibold text-navy">Daftar Kunjungan Siap Ditagih</h3>
                <p class="text-sm text-ink-soft">{{ $visits->total() }} kunjungan ditemukan.</p>
            </div>

            @if ($visits->isEmpty())
                <div class="px-4 py-8">
                    <x-ui.empty-state
                        title="Belum ada kunjungan menunggu billing."
                        description="Finalisasi RME dokter terlebih dahulu agar kunjungan masuk ke antrean kasir." />
                </div>
            @else
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <thead class="bg-navy-50">
                            <tr class="text-left text-ink-soft">
                                <th scope="col" class="px-4 py-3 font-medium">No. Kunjungan</th>
                                <th scope="col" class="px-3 py-3 font-medium">Pasien</th>
                                <th scope="col" class="px-3 py-3 font-medium">Klinik/Cabang</th>
                                <th scope="col" class="px-3 py-3 font-medium">Dokter</th>
                                <th scope="col" class="px-3 py-3 font-medium">Layanan Awal</th>
                                <th scope="col" class="px-3 py-3 font-medium">Status Tagihan</th>
                                <th scope="col" class="px-4 py-3 text-right font-medium">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-hairline">
                            @foreach ($visits as $visit)
                                @php
                                    $invoice = $visit->rmeInvoice ?? null;
                                    $hasInvoice = $invoice !== null;
                                @endphp
                                <tr class="transition-colors hover:bg-navy-50">
                                    <td class="px-4 py-3 font-mono text-xs text-ink">{{ $visit->visit_number }}</td>
                                    <td class="px-3 py-3 font-medium text-navy">
                                        {{ $visit->patient?->name ?? '—' }}
                                        @if ($visit->patient?->medical_record_number)
                                            <span class="block font-mono text-xs font-normal text-ink-muted">{{ $visit->patient->medical_record_number }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3 text-ink-soft">{{ $visit->branch ? $visit->branch->code.' — '.$visit->branch->name : '—' }}</td>
                                    <td class="px-3 py-3 text-ink-soft">{{ $visit->doctor?->name ?? '—' }}</td>
                                    <td class="px-3 py-3 text-ink-soft">{{ $visit->initialTreatment?->name ?? '—' }}</td>
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
                                                <x-ui.button variant="secondary" size="sm" :href="route('rme.cashier.show', [$visit, $invoice])">Lihat Tagihan</x-ui.button>
                                            @else
                                                <x-ui.button variant="primary" size="sm" :href="route('rme.cashier.create', $visit)">Buat Tagihan</x-ui.button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                </div>

                @if ($visits->hasPages())
                    <div class="border-t border-hairline px-4 py-3">
                        {{ $visits->links() }}
                    </div>
                @endif
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
