@php
    use App\Modules\ClinicOversight\Services\OversightBillingCorrectionService;
    use App\Modules\ClinicOversight\Support\OversightFormat;
    use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
    use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
    $c = $correction;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Koreksi {{ $c->correction_number }}</title>
    @include('oversight.partials.print-styles')
</head>
<body>
    @unless ($forPdf)
        <div class="no-print">
            <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
            <a href="{{ route('oversight.billing-corrections.show', $c->id) }}">&larr; Kembali</a>
        </div>
    @endunless

    <h1>Koreksi Tagihan {{ $c->correction_number }}</h1>
    <p class="meta">{{ RmeInvoiceCorrectionType::label($c->correction_type) }} · dibuat {{ $generatedAt }} WITA · dokumen hanya baca · refund belum diimplementasikan</p>

    <table>
        <tr><th>Pasien</th><td>{{ $c->patient?->name }} ({{ $c->patient?->medical_record_number }})</td></tr>
        <tr><th>Cabang</th><td>{{ $c->branch?->name }}</td></tr>
        <tr><th>Invoice / Kunjungan</th><td>{{ $c->invoice?->invoice_number }} / {{ $c->clinicVisit?->visit_number }}</td></tr>
        <tr><th>Status</th><td>{{ RmeInvoiceCorrectionStatus::label($c->status) }} · persetujuan: {{ OversightBillingCorrectionService::approvalStatus($c) }} · penerapan: {{ OversightBillingCorrectionService::applicationStatus($c) }}</td></tr>
        <tr><th>Total asli → usulan</th><td>{{ OversightFormat::rupiah($c->original_grand_total) }} → {{ OversightFormat::rupiah($c->proposed_grand_total) }} (selisih {{ OversightFormat::rupiah($c->difference_amount) }})</td></tr>
        <tr><th>Total diterapkan</th><td>{{ $c->isApplied() ? OversightFormat::rupiah($c->proposed_grand_total) : 'Belum diterapkan' }}</td></tr>
        <tr><th>Pengaju</th><td>{{ $c->requester?->name }} · {{ OversightFormat::clinicalTime($c->requested_at, 'd/m/Y H:i') }} WITA</td></tr>
        <tr><th>Pemutus</th><td>{{ $c->decider?->name ?? '—' }} {{ $c->decided_at ? '· '.OversightFormat::clinicalTime($c->decided_at, 'd/m/Y H:i').' WITA' : '' }}</td></tr>
        <tr><th>Penerap</th><td>{{ $c->applier?->name ?? '—' }} {{ $c->applied_at ? '· '.OversightFormat::clinicalTime($c->applied_at, 'd/m/Y H:i').' WITA' : '' }}</td></tr>
        <tr><th>Alasan</th><td>{{ $c->reason }}</td></tr>
    </table>

    <table>
        <thead><tr><th>#</th><th>Operasi</th><th>Asli</th><th class="num">Subtotal Asli</th><th>Usulan</th><th class="num">Subtotal Usulan</th></tr></thead>
        <tbody>
            @foreach ($c->lines as $line)
                <tr>
                    <td>{{ $line->line_no }}</td>
                    <td>{{ $line->operationLabel() }}</td>
                    <td>{{ $line->original_description ?? '—' }}@if ($line->original_item_id) — Tindakan: {{ $line->originalTreatment?->name ?? '—' }}; Dokter: {{ $line->originalDoctor?->name ?? '—' }}@endif</td>
                    <td class="num">{{ $line->original_subtotal !== null ? OversightFormat::rupiah($line->original_subtotal) : '—' }}</td>
                    <td>{{ $line->proposed_description ?? '(dihapus)' }}@if ($line->proposed_description !== null) — Tindakan: {{ $line->proposedTreatment?->name ?? '—' }}; Dokter: {{ $line->proposedDoctor?->name ?? '—' }}@if ($line->treatmentChanged()) (tindakan berubah)@endif @endif</td>
                    <td class="num">{{ $line->proposed_subtotal !== null ? OversightFormat::rupiah($line->proposed_subtotal) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table>
        <thead><tr><th>Waktu (WITA)</th><th>Transisi</th><th>Oleh</th></tr></thead>
        <tbody>
            @foreach ($c->events as $event)
                <tr>
                    <td>{{ OversightFormat::clinicalTime($event->occurred_at, 'd/m/Y H:i') }}</td>
                    <td>{{ $event->from_status ? RmeInvoiceCorrectionStatus::label($event->from_status).' → ' : '' }}{{ RmeInvoiceCorrectionStatus::label($event->to_status) }}</td>
                    <td>{{ $event->actor?->name ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($versions->isNotEmpty())
        <p class="meta">Versi dokumen: @foreach ($versions as $version){{ $version->label() }} ({{ $version->document_reference }})@if (! $loop->last); @endif @endforeach</p>
    @endif
</body>
</html>
