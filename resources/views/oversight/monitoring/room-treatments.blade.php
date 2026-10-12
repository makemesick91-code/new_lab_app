@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
@endphp
<x-settings-shell title="Jenis Perawatan per Dental">
    <x-ui.page-header title="Jenis Perawatan Ditagihkan per Ruangan Dental" :subtitle="'Tanggal klinik '.$day->date.' (WITA).'">
        <x-slot:breadcrumb>Finance &amp; Data Analyst · Monitoring Dental · Jenis Perawatan per Dental</x-slot:breadcrumb>
        @if ($canExport)
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.monitoring.room-treatments.export', request()->query())">Export CSV</x-ui.button>
                <x-ui.button variant="primary" size="sm" :href="route('oversight.monitoring.room-treatments.print', request()->query())" target="_blank">Cetak / PDF</x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.alert variant="info" title="JENIS PERAWATAN DITAGIHKAN">
        Sumber data: item invoice RME yang sudah diterbitkan (UNPAID / PARTIAL / PAID). Ini adalah bukti penagihan,
        bukan verifikasi bahwa tindakan klinis selesai dilakukan. Invoice DRAFT dan VOID tidak dihitung.
        Atribusi ruangan = ruangan yang tersimpan pada kunjungan saat ini.
    </x-ui.alert>

    @include('oversight.monitoring.partials.filters', ['action' => route('oversight.monitoring.room-treatments'), 'withRoom' => true, 'withTreatment' => true])

    <x-ui.card title="Ringkasan per Ruangan" padding="p-5">
        @if ($summary->isEmpty())
            <p class="text-sm text-ink-soft">Tidak ada item ditagihkan.</p>
        @else
            <x-ui.table>
                <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr><th class="px-3 py-2">Cabang</th><th class="px-3 py-2">Ruangan</th><th class="px-3 py-2">Jenis Perawatan Ditagihkan</th><th class="px-3 py-2 text-right">Item</th><th class="px-3 py-2 text-right">Qty</th><th class="px-3 py-2 text-right">Nilai Ditagihkan</th></tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($summary as $row)
                        <tr>
                            <td class="px-3 py-2">{{ $row->branch_name }}</td>
                            <td class="px-3 py-2">{{ $row->room_name }}</td>
                            <td class="px-3 py-2">{{ $row->treatment_name ?? $unclassifiedLabel }}</td>
                            <td class="px-3 py-2 text-right">{{ (int) $row->item_count }}</td>
                            <td class="px-3 py-2 text-right">{{ (int) $row->total_qty }}</td>
                            <td class="px-3 py-2 text-right">{{ OversightFormat::rupiah($row->total_amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.card title="Rincian Item" padding="p-5">
        @if ($items->isEmpty())
            <x-ui.empty-state title="Tidak ada item" description="Tidak ada item invoice ditagihkan untuk filter ini." />
        @else
            <x-ui.table>
                <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr>
                        <th class="px-3 py-2">Cabang</th><th class="px-3 py-2">Ruangan</th><th class="px-3 py-2">Pasien</th><th class="px-3 py-2">Dokter</th>
                        <th class="px-3 py-2">Jenis Perawatan Ditagihkan</th><th class="px-3 py-2 text-right">Qty</th><th class="px-3 py-2 text-right">Nilai</th><th class="px-3 py-2">Invoice</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($items as $item)
                        <tr>
                            <td class="px-3 py-2">{{ $item->branch_name }}</td>
                            <td class="px-3 py-2">{{ $item->room_name }}</td>
                            <td class="px-3 py-2"><span class="text-navy">{{ $item->patient_name }}</span> <span class="font-mono text-xs text-ink-soft">{{ $item->medical_record_number }}</span></td>
                            <td class="px-3 py-2">{{ $item->doctor_name ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $item->treatment_name ?? $unclassifiedLabel }}@if ($item->treatment_name === null && filled($item->description)) <span class="text-xs text-ink-soft">— {{ $item->description }}</span>@endif</td>
                            <td class="px-3 py-2 text-right">{{ $item->qty }}</td>
                            <td class="px-3 py-2 text-right">{{ OversightFormat::rupiah($item->subtotal) }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $item->invoice_number }} <x-ui.badge :status="strtolower($item->invoice_status)">{{ $item->invoice_status }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            <div class="mt-4">{{ $items->links() }}</div>
        @endif
    </x-ui.card>
</x-settings-shell>
