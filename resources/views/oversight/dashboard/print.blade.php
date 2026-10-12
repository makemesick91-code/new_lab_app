@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    $t = $payload['totals'];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Dashboard Finance &amp; Data Analyst — {{ $payload['date'] }}</title>
    @include('oversight.partials.print-styles')
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
        <a href="{{ route('oversight.dashboard', array_filter(['date' => $payload['date'], 'branch_id' => $payload['selected_branch_id']])) }}">&larr; Kembali</a>
    </div>

    <h1>Dashboard Finance &amp; Data Analyst</h1>
    <p class="meta">Tanggal klinik: <strong>{{ $payload['date'] }}</strong> (WITA) · dibuat {{ $payload['generated_at'] }} WITA · dokumen hanya baca</p>

    <table>
        <thead>
            <tr>
                <th>Cabang</th>
                <th class="num">Pasien (check-in, unik)</th>
                <th class="num">Transaksi (batch kasir)</th>
                <th class="num">Omzet (pembayaran RME)</th>
                <th class="num">Piutang Aktif Per Saat Ini</th>
                <th class="num">Invoice Piutang</th>
                <th class="num">Kunjungan Dibatalkan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payload['branches'] as $row)
                <tr>
                    <td>{{ $row['branch_name'] }} ({{ $row['branch_code'] }})</td>
                    <td class="num">{{ number_format($row['patients'], 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($row['transactions'], 0, ',', '.') }}</td>
                    <td class="num">{{ OversightFormat::rupiahCents($row['revenue_cents']) }}</td>
                    <td class="num">{{ OversightFormat::rupiahCents($row['receivable_cents']) }}</td>
                    <td class="num">{{ number_format($row['receivable_invoices'], 0, ',', '.') }}</td>
                    <td class="num">{{ number_format($row['cancellations'], 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="7">Tidak ada cabang dalam cakupan.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td>Total (unik lintas cabang)</td>
                <td class="num">{{ number_format($t['patients'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($t['transactions'], 0, ',', '.') }}</td>
                <td class="num">{{ OversightFormat::rupiahCents($t['revenue_cents']) }}</td>
                <td class="num">{{ OversightFormat::rupiahCents($t['receivable_cents']) }}</td>
                <td class="num">{{ number_format($t['receivable_invoices'], 0, ',', '.') }}</td>
                <td class="num">{{ number_format($t['cancellations'], 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="footnote">
        Pasien = pasien unik yang check-in (bukan sekadar terdaftar). Transaksi = batch pembayaran kasir.
        Omzet = pembayaran RME aktual pada tanggal bayar, diatribusikan ke cabang invoice; tagihan lab tidak termasuk.
        Piutang = sisa tagihan invoice UNPAID/PARTIAL per saat ini (tidak difilter tanggal).
        Cancel = kunjungan yang dibatalkan pada tanggal ini (bukan no-show).
    </p>
</body>
</html>
