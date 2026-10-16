@php
    use App\Modules\ClinicOversight\Services\OversightBillingCorrectionService;
    use App\Modules\ClinicOversight\Support\OversightFormat;
    $from = OversightFormat::clinicalDate($range->startDate);
    $to = OversightFormat::clinicalDate($range->endDate);
    $period = $range->isSingleDay() ? $from : $from.' – '.$to;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Koreksi Tagihan — {{ $period }}</title>
    @include('oversight.partials.print-styles')
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
        <a href="{{ route('oversight.billing-corrections.index', $carriedFilters) }}">&larr; Kembali</a>
    </div>

    <h1>Riwayat Koreksi Tagihan RME</h1>
    <p class="meta">Periode pengajuan: <strong>{{ $period }}</strong> ({{ $range->dayCount }} hari, WITA, inklusif) · dibuat {{ $generatedAt }} WITA · dokumen hanya baca</p>
    @if ($truncated)
        <p class="meta"><strong>Dipotong pada {{ OversightBillingCorrectionService::EXPORT_LIMIT }} baris — persempit rentang tanggal untuk data lengkap.</strong></p>
    @endif

    <table>
        <thead>
            <tr>
                @foreach (OversightBillingCorrectionService::csvHeader() as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $c)
                <tr>
                    @foreach (OversightBillingCorrectionService::csvRow($c) as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count(OversightBillingCorrectionService::csvHeader()) }}">Tidak ada catatan koreksi.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
