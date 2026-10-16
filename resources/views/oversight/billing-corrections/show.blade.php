@php
    use App\Modules\ClinicOversight\Services\OversightBillingCorrectionService;
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
    use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
    $c = $correction;
@endphp
<x-settings-shell title="Detail Koreksi Tagihan">
    <x-ui.page-header :title="'Koreksi '.$c->correction_number" :subtitle="RmeInvoiceCorrectionType::label($c->correction_type)">
        <x-slot:breadcrumb>Billing Correction · Riwayat &amp; Detail Koreksi</x-slot:breadcrumb>
        <x-slot:actions>
            <x-ui.button variant="secondary" size="sm" :href="route('oversight.billing-corrections.index')">&larr; Kembali</x-ui.button>
            @if ($canExport)
                <x-ui.button variant="secondary" size="sm" :href="route('oversight.billing-corrections.show.print', $c->id)" target="_blank">Cetak</x-ui.button>
                <x-ui.button variant="primary" size="sm" :href="route('oversight.billing-corrections.show.pdf', $c->id)">PDF</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @include('oversight.partials.readonly-notice')

    <div class="space-y-6">
        <x-ui.card title="A. Pasien &amp; Kunjungan">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-ink-soft">Pasien</dt><dd class="text-navy">{{ $c->patient?->name }}</dd></div>
                <div><dt class="text-ink-soft">No. RM</dt><dd class="font-mono text-navy">{{ $c->patient?->medical_record_number }}</dd></div>
                <div><dt class="text-ink-soft">Cabang (cabang invoice)</dt><dd class="text-navy">{{ $c->branch?->name }}</dd></div>
                <div><dt class="text-ink-soft">No. Kunjungan</dt><dd class="font-mono text-navy">{{ $c->clinicVisit?->visit_number }}</dd></div>
                <div><dt class="text-ink-soft">No. Invoice</dt><dd class="font-mono text-navy">{{ $c->invoice?->invoice_number }}</dd></div>
                <div><dt class="text-ink-soft">Status</dt><dd><x-ui.badge :tone="RmeInvoiceCorrectionStatus::tone($c->status)">{{ RmeInvoiceCorrectionStatus::label($c->status) }}</x-ui.badge></dd></div>
            </dl>
        </x-ui.card>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
            <x-ui.card><p class="text-xs text-ink-soft">B. Total Asli</p><p class="text-lg font-semibold text-navy">{{ OversightFormat::rupiah($c->original_grand_total) }}</p></x-ui.card>
            <x-ui.card><p class="text-xs text-ink-soft">C. Total Usulan</p><p class="text-lg font-semibold text-navy">{{ OversightFormat::rupiah($c->proposed_grand_total) }}</p></x-ui.card>
            <x-ui.card><p class="text-xs text-ink-soft">D. Total Diterapkan</p><p class="text-lg font-semibold text-navy">{{ $c->isApplied() ? OversightFormat::rupiah($c->proposed_grand_total) : 'Belum diterapkan' }}</p></x-ui.card>
            <x-ui.card><p class="text-xs text-ink-soft">Selisih Usulan</p><p class="text-lg font-semibold text-navy">{{ OversightFormat::rupiah($c->difference_amount) }}</p></x-ui.card>
        </div>

        <x-ui.card title="Status Persetujuan &amp; Penerapan">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div><dt class="text-ink-soft">Persetujuan</dt><dd class="text-navy">{{ OversightBillingCorrectionService::approvalStatus($c) }}</dd></div>
                <div><dt class="text-ink-soft">Penerapan</dt><dd class="text-navy">{{ OversightBillingCorrectionService::applicationStatus($c) }}</dd></div>
                <div><dt class="text-ink-soft">Pengaju</dt><dd class="text-navy">{{ $c->requester?->name }} · {{ OversightFormat::clinicalTime($c->requested_at, 'd/m/Y H:i') }} WITA</dd></div>
                <div><dt class="text-ink-soft">Pemutus</dt><dd class="text-navy">{{ $c->decider?->name ?? '—' }}@if ($c->decided_at) · {{ OversightFormat::clinicalTime($c->decided_at, 'd/m/Y H:i') }} WITA @endif</dd></div>
                <div><dt class="text-ink-soft">Penerap</dt><dd class="text-navy">{{ $c->applier?->name ?? '—' }}@if ($c->applied_at) · {{ OversightFormat::clinicalTime($c->applied_at, 'd/m/Y H:i') }} WITA @endif</dd></div>
                <div><dt class="text-ink-soft">Refund</dt><dd class="text-ink-soft">Tidak tersedia — refund belum diimplementasikan.</dd></div>
            </dl>
        </x-ui.card>

        <x-ui.card title="Rincian Baris" padding="">
            <x-ui.table>
                <thead class="bg-navy-50">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium text-ink-soft">#</th>
                        <th class="px-4 py-2 text-left font-medium text-ink-soft">Operasi</th>
                        <th class="px-4 py-2 text-left font-medium text-ink-soft">Asli</th>
                        <th class="px-4 py-2 text-right font-medium text-ink-soft">Subtotal Asli</th>
                        <th class="px-4 py-2 text-left font-medium text-ink-soft">Usulan</th>
                        <th class="px-4 py-2 text-right font-medium text-ink-soft">Subtotal Usulan</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @foreach ($c->lines as $line)
                        <tr>
                            <td class="px-4 py-2">{{ $line->line_no }}</td>
                            <td class="px-4 py-2">{{ $line->operationLabel() }}</td>
                            <td class="px-4 py-2">{{ $line->original_description ?? '—' }}@if ($line->original_qty) · {{ $line->original_qty }} × {{ OversightFormat::rupiah($line->original_unit_price) }}@endif
                                @if ($line->original_item_id)<span class="block text-xs text-ink-muted">Tindakan: {{ $line->originalTreatment?->name ?? '—' }} · Dokter: {{ $line->originalDoctor?->name ?? '—' }}</span>@endif</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $line->original_subtotal !== null ? OversightFormat::rupiah($line->original_subtotal) : '—' }}</td>
                            <td class="px-4 py-2">{{ $line->proposed_description ?? '(dihapus)' }}@if ($line->proposed_qty) · {{ $line->proposed_qty }} × {{ OversightFormat::rupiah($line->proposed_unit_price) }}@endif
                                @if ($line->proposed_description !== null)<span class="block text-xs text-ink-muted">Tindakan: {{ $line->proposedTreatment?->name ?? '—' }} · Dokter: {{ $line->proposedDoctor?->name ?? '—' }}@if ($line->treatmentChanged()) · <strong>tindakan berubah</strong>@endif</span>@endif</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ $line->proposed_subtotal !== null ? OversightFormat::rupiah($line->proposed_subtotal) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        </x-ui.card>

        <x-ui.card title="Alasan">
            <p class="whitespace-pre-line text-sm text-ink">{{ $c->reason }}</p>
            @if ($c->decision_note)<p class="mt-2 text-sm text-ink-soft">Catatan keputusan: {{ $c->decision_note }}</p>@endif
            @if ($c->cancel_reason)<p class="mt-2 text-sm text-ink-soft">Alasan pembatalan: {{ $c->cancel_reason }}</p>@endif
        </x-ui.card>

        <x-ui.card title="Riwayat Status">
            <ol class="space-y-1 text-sm">
                @foreach ($c->events as $event)
                    <li>{{ OversightFormat::clinicalTime($event->occurred_at, 'd/m/Y H:i') }} WITA — {{ $event->from_status ? RmeInvoiceCorrectionStatus::label($event->from_status).' → ' : '' }}{{ RmeInvoiceCorrectionStatus::label($event->to_status) }} · {{ $event->actor?->name ?? '—' }}</li>
                @endforeach
            </ol>
        </x-ui.card>

        <x-ui.card title="Referensi Dokumen (asli &amp; revisi)">
            @if ($versions->isEmpty())
                <p class="text-sm text-ink-soft">Belum ada versi dokumen beku — tagihan ini belum pernah dikoreksi.</p>
            @else
                <ul class="space-y-1 text-sm">
                    @foreach ($versions as $version)
                        <li>{{ $version->label() }} · <span class="font-mono">{{ $version->document_reference }}</span> · dibekukan {{ OversightFormat::clinicalTime($version->issued_at, 'd/m/Y H:i') }} WITA</li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </div>
</x-settings-shell>
