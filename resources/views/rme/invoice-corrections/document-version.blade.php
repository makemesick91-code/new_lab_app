@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    $branch = $snapshot['branch'] ?? [];
    $inv = $snapshot['invoice'] ?? [];
    $totals = $snapshot['totals'] ?? [];
    $payments = $snapshot['payments'] ?? [];
    $patient = $version->invoice?->patient;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $version->document_reference }} — {{ $version->label() }}</title>
    @include('oversight.partials.print-styles')
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
        @if ($version->sourceCorrection)
            <a href="{{ route('rme.invoice-corrections.show', $version->source_correction_id) }}">&larr; Koreksi {{ $version->sourceCorrection->correction_number }}</a>
        @endif
    </div>

    {{-- FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — rendered ONLY from the
         frozen snapshot of this version, never from live invoice rows, so a reprint
         of a historical document can never silently show corrected numbers. --}}
    <h1 data-document-version="{{ $version->version_number }}">
        {{ $version->isOriginal() ? 'VERSI ASLI' : 'REVISI KE-'.$version->version_number }}
        @if ($supersededBy !== null)
            — DIGANTIKAN OLEH REVISI KE-{{ $supersededBy }}
        @endif
    </h1>
    <p class="meta">
        Referensi dokumen <strong>{{ $version->document_reference }}</strong> · invoice {{ $inv['invoice_number'] ?? '—' }}
        · dibekukan {{ OversightFormat::clinicalTime($version->issued_at, 'd/m/Y H:i') }} WITA
        @if ($version->sourceCorrection) · dari koreksi {{ $version->sourceCorrection->correction_number }} @endif
    </p>
    @if ($version->isOriginal())
        <p class="meta" data-capture-basis>Dokumen asli dibekukan dari data tagihan tepat sebelum koreksi pertama diterapkan (sebelumnya tagihan tidak memiliki jalur perubahan). Pembayaran yang tercantum adalah yang tercatat pada saat itu.</p>
    @endif
    @unless ($integrity && $complete)
        <p class="meta" data-integrity-warning><strong>Peringatan: integritas snapshot tidak terverifikasi.</strong></p>
    @endunless

    <table>
        <tr><th>Cabang</th><td>{{ $branch['name'] ?? '—' }} ({{ $branch['code'] ?? '—' }})@if (! empty($branch['address'])) · {{ $branch['address'] }}@endif</td></tr>
        <tr><th>Pasien</th><td>{{ $patient?->name ?? '—' }} · {{ $patient?->medical_record_number ?? '—' }}</td></tr>
        <tr><th>Status saat dibekukan</th><td>{{ $inv['status'] ?? '—' }}</td></tr>
    </table>

    <table>
        <thead>
            <tr>
                <th>Deskripsi</th>
                <th class="num">Qty</th>
                <th class="num">Harga Satuan</th>
                <th class="num">Diskon</th>
                <th class="num">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($snapshot['items'] ?? [] as $item)
                <tr>
                    <td>{{ $item['description'] ?? '' }}@if (! empty($item['treatment_name'])) ({{ $item['treatment_name'] }})@endif</td>
                    <td class="num">{{ $item['qty'] ?? '' }}</td>
                    <td class="num">{{ OversightFormat::rupiahCents((int) ($item['unit_price_cents'] ?? 0)) }}</td>
                    <td class="num">{{ OversightFormat::rupiahCents((int) ($item['discount_cents'] ?? 0)) }}</td>
                    <td class="num">{{ OversightFormat::rupiahCents((int) ($item['subtotal_cents'] ?? 0)) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="4" class="num">Jumlah</td><td class="num">{{ OversightFormat::rupiahCents((int) ($totals['subtotal_cents'] ?? 0)) }}</td></tr>
            <tr><td colspan="4" class="num">Diskon</td><td class="num">{{ OversightFormat::rupiahCents((int) ($totals['discount_total_cents'] ?? 0)) }}</td></tr>
            <tr><td colspan="4" class="num"><strong>Total</strong></td><td class="num"><strong>{{ OversightFormat::rupiahCents((int) ($totals['grand_total_cents'] ?? 0)) }}</strong></td></tr>
            <tr><td colspan="4" class="num">Dibayar</td><td class="num">{{ OversightFormat::rupiahCents((int) ($totals['paid_cents'] ?? 0)) }}</td></tr>
        </tfoot>
    </table>

    @if ($payments !== [])
        <table>
            <thead><tr><th>No. Kwitansi</th><th>Waktu Bayar</th><th class="num">Jumlah</th></tr></thead>
            <tbody>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment['payment_number'] ?? '' }}</td>
                        <td>{{ OversightFormat::clinicalTime($payment['paid_at'] ?? null, 'd/m/Y H:i') }} WITA</td>
                        <td class="num">{{ OversightFormat::rupiahCents((int) ($payment['amount_cents'] ?? 0)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="meta">SHA-256 snapshot: <code>{{ $version->snapshot_sha256 }}</code></p>
</body>
</html>
