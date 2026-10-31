@php
    use App\Modules\ClinicOversight\Services\OversightMonitoringService;
    use App\Modules\ClinicOversight\Support\OversightFormat;
    $patient = $visit->patient;
@endphp
<x-settings-shell title="Detail RME">
    <x-ui.page-header :title="'Detail RME — '.($patient?->name ?? '-')" :subtitle="'Kunjungan '.$visit->visit_number.' · '.($visit->branch?->name ?? '-')">
        <x-slot:breadcrumb>Finance &amp; Data Analyst · Rekam Medis Elektronik · Detail RME</x-slot:breadcrumb>
        <x-slot:actions>
            @if ($patient)
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.rme.patients.show', $patient->id)">Riwayat RME Pasien</x-ui.button>
            @endif
            @if ($canExport)
                <x-ui.button variant="primary" size="sm" :href="route('oversight.rme.visits.print', $visit->id)" target="_blank">Cetak RME</x-ui.button>
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.rme.visits.pdf', $visit->id)">Unduh PDF</x-ui.button>
                @if ($odontogram)
                    <x-ui.button variant="secondary" size="sm" :href="route('oversight.rme.visits.odontogram.print', $visit->id)" target="_blank">Cetak Odontogram</x-ui.button>
                @endif
                @if ($prescription)
                    <x-ui.button variant="secondary" size="sm" :href="route('oversight.rme.visits.prescription.print', $visit->id)" target="_blank">Cetak Resep</x-ui.button>
                @endif
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <x-ui.card title="Informasi Kunjungan" padding="p-5">
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3 lg:grid-cols-4">
            <div><dt class="text-xs text-ink-soft">Pasien</dt><dd class="text-navy">{{ $patient?->name ?? '-' }}</dd></div>
            <div><dt class="text-xs text-ink-soft">No. Rekam Medis</dt><dd class="font-mono text-navy">{{ $patient?->medical_record_number ?? '-' }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Telepon / WhatsApp</dt><dd class="text-navy">{{ $patient?->phone ?: ($patient?->whatsapp_number ?: '-') }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Tanggal Kunjungan</dt><dd class="text-navy">{{ $visit->visit_date?->format('d-m-Y') }}</dd></div>
            <div><dt class="text-xs text-ink-soft">No. Kunjungan</dt><dd class="font-mono text-navy">{{ $visit->visit_number }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Antrian</dt><dd class="text-navy">{{ $visit->queue_number ?? '-' }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Cabang</dt><dd class="text-navy">{{ $visit->branch?->name ?? '-' }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Ruangan</dt><dd class="text-navy">{{ $visit->clinicRoom?->name ?? '-' }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Dokter</dt><dd class="text-navy">{{ $visit->doctor?->name ?? '-' }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Status Kunjungan</dt><dd><x-ui.badge :status="$visit->status">{{ OversightMonitoringService::statusLabel($visit->status) }}</x-ui.badge></dd></div>
            <div><dt class="text-xs text-ink-soft">Check-in</dt><dd class="text-navy">{{ OversightFormat::clinicalTime($visit->check_in_at, 'd-m-Y H:i') ?? '-' }}</dd></div>
            <div><dt class="text-xs text-ink-soft">Selesai</dt><dd class="text-navy">{{ OversightFormat::clinicalTime($visit->completed_at, 'd-m-Y H:i') ?? '-' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-xs text-ink-soft">Tindakan Awal</dt><dd class="text-navy">{{ $visit->initialTreatment?->name ?? '-' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-xs text-ink-soft">Keluhan Utama</dt><dd class="text-navy">{{ $visit->chief_complaint ?: '-' }}</dd></div>
        </dl>
    </x-ui.card>

    <x-ui.card title="Rekam Medis (Lembar Kunjungan Ini)" padding="p-5">
        @if (! $medicalRecord)
            <p class="text-sm text-ink-soft">Kunjungan ini belum memiliki lembar RM. Buku RM tulisan tangan pasien tersimpan per pasien — lihat Riwayat RME Pasien.</p>
        @else
            <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
                <x-ui.badge :tone="$medicalRecord->status === 'final' ? 'success' : 'neutral'">{{ $medicalRecord->status === 'final' ? 'Final' : 'Draft' }}</x-ui.badge>
                @if ($medicalRecord->finalized_at)
                    <span class="text-ink-soft">difinalkan {{ OversightFormat::clinicalTime($medicalRecord->finalized_at, 'd-m-Y H:i') }} WITA oleh {{ $medicalRecord->finalizedBy?->name ?? '-' }}</span>
                @endif
            </div>
            @if ($medicalRecord->diagnoses->isNotEmpty())
                <div class="mb-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft">Diagnosis Terstruktur</p>
                    <ul class="mt-1 list-inside list-disc text-sm text-navy">
                        @foreach ($medicalRecord->diagnoses as $diagnosis)
                            <li>{{ $diagnosis->clinicalDiagnosis?->code }} — {{ $diagnosis->clinicalDiagnosis?->display ?? '-' }} ({{ $diagnosis->diagnosis_role }})</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if (filled($medicalRecord->notes))
                <div class="mb-3">
                    <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft">Catatan Rekam Medis</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-navy">{{ $medicalRecord->notes }}</p>
                </div>
            @endif
            @if ($handwritingBook->isNotEmpty())
                <p class="text-xs font-semibold uppercase tracking-wide text-ink-soft">Halaman Tulisan Tangan</p>
                <div class="mt-2 grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach ($handwritingBook as $page)
                        <img loading="lazy" class="w-full rounded border border-hairline bg-white"
                             alt="Halaman RM {{ $page['position'] }}"
                             src="{{ $page['kind'] === 'handwriting'
                                ? route('oversight.rme.handwritings.image', $page['id'])
                                : route('oversight.rme.handwriting-pages.image', $page['id']) }}">
                    @endforeach
                </div>
            @else
                <p class="text-sm text-ink-soft">Halaman tulisan tangan untuk kunjungan ini tersimpan pada buku RM pasien (lihat Riwayat RME Pasien).</p>
            @endif
        @endif
    </x-ui.card>

    <x-ui.card title="Odontogram" padding="p-5">
        @if (! $structuredOdontogram)
            <p class="text-sm text-ink-soft">Belum ada odontogram tersimpan untuk kunjungan ini.</p>
        @else
            @php $dmft = $structuredOdontogram['dmft'] ?? []; @endphp
            <p class="mb-2 text-sm text-navy">
                D = {{ $dmft['D'] ?? 0 }} · M = {{ $dmft['M'] ?? 0 }} · F = {{ $dmft['F'] ?? 0 }} · DMF-T = {{ $dmft['DMFT'] ?? 0 }}
            </p>
            @if (! empty($structuredOdontogram['table_rows']))
                <x-ui.table>
                    <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                        <tr><th class="px-3 py-2">Gigi</th><th class="px-3 py-2">Diagnosa</th><th class="px-3 py-2">Perawatan</th><th class="px-3 py-2">Dokter</th></tr>
                    </thead>
                    <tbody class="divide-y divide-hairline">
                        @foreach ($structuredOdontogram['table_rows'] as $row)
                            <tr>
                                <td class="px-3 py-2">{{ $row['gigi'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ $row['diagnosa'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ $row['perawatan'] ?? '' }}</td>
                                <td class="px-3 py-2">{{ $row['dokter'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @else
                <p class="text-sm text-ink-soft">Tidak ada gigi yang ditandai.</p>
            @endif
        @endif
    </x-ui.card>

    <x-ui.card title="Resep" padding="p-5">
        @if (! $prescription)
            <p class="text-sm text-ink-soft">Tidak ada resep untuk kunjungan ini.</p>
        @else
            <dl class="mb-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-ink-soft">Tanggal Resep</dt><dd class="text-navy">{{ $prescription->prescription_date?->format('d-m-Y') ?? '-' }}</dd></div>
                <div><dt class="text-xs text-ink-soft">Dokter</dt><dd class="text-navy">{{ $prescription->doctor?->name ?? $prescription->prescribed_by_name ?? '-' }}</dd></div>
                <div><dt class="text-xs text-ink-soft">Alergi</dt><dd class="text-navy">{{ $prescription->allergy_note ?: '-' }}</dd></div>
            </dl>
            @php $canvas = $prescription->prescriptionCanvasDataUri(); @endphp
            @if ($canvas)
                <img class="max-w-full rounded border border-hairline bg-white" alt="Resep" src="{{ $canvas }}">
            @endif
            @if (filled($prescription->notes))
                <p class="mt-2 whitespace-pre-line text-sm text-navy">{{ $prescription->notes }}</p>
            @endif
        @endif
    </x-ui.card>

    <x-ui.card title="Tagihan RME" padding="p-5">
        @if (! $invoice)
            <p class="text-sm text-ink-soft">Belum ada invoice RME untuk kunjungan ini.</p>
        @else
            @php
                $paid = (float) $invoice->payments->sum('amount');
                // RFS-1: the balance is measured against NET received (a settled refund is money given back).
                $remaining = $invoice->remainingAmount();
            @endphp
            <dl class="mb-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-4">
                <div><dt class="text-xs text-ink-soft">No. Invoice</dt><dd class="font-mono text-navy">{{ $invoice->invoice_number }}</dd></div>
                <div><dt class="text-xs text-ink-soft">Status</dt><dd><x-ui.badge :status="strtolower($invoice->status)">{{ $invoice->status }}</x-ui.badge></dd></div>
                <div><dt class="text-xs text-ink-soft">Total</dt><dd class="text-navy">{{ OversightFormat::rupiah($invoice->grand_total) }}</dd></div>
                <div><dt class="text-xs text-ink-soft">Sisa Tagihan</dt><dd class="text-navy">{{ OversightFormat::rupiah($remaining) }}</dd></div>
            </dl>
            <x-ui.table>
                <thead class="bg-canvas text-left text-xs uppercase tracking-wide text-ink-soft">
                    <tr><th class="px-3 py-2">Item</th><th class="px-3 py-2 text-right">Qty</th><th class="px-3 py-2 text-right">Subtotal</th></tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($invoice->items as $item)
                        <tr>
                            <td class="px-3 py-2">{{ $item->treatment?->name ?? $item->description }}</td>
                            <td class="px-3 py-2 text-right">{{ $item->qty }}</td>
                            <td class="px-3 py-2 text-right">{{ OversightFormat::rupiah($item->subtotal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
            @if ($invoice->payments->isNotEmpty())
                <p class="mt-3 text-xs font-semibold uppercase tracking-wide text-ink-soft">Pembayaran</p>
                <ul class="mt-1 text-sm text-navy">
                    @foreach ($invoice->payments as $payment)
                        <li>{{ OversightFormat::clinicalTime($payment->paid_at, 'd-m-Y H:i') }} WITA · {{ OversightFormat::rupiah($payment->amount) }} · {{ $payment->paymentMethod?->name ?? '-' }}</li>
                    @endforeach
                </ul>
            @endif
        @endif
    </x-ui.card>
</x-settings-shell>
