@php
    use App\Modules\ClinicOversight\Support\OversightFormat;
    $t = $payload['totals'];
    $from = OversightFormat::clinicalDate($payload['start_date']);
    $to = OversightFormat::clinicalDate($payload['end_date']);
    $period = $payload['is_single_day'] ? $from : $from.' – '.$to;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Dashboard Finance &amp; Data Analyst — {{ $period }}</title>
    @include('oversight.partials.print-styles')
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
        <a href="{{ route('oversight.dashboard', array_filter(['start_date' => $payload['start_date'], 'end_date' => $payload['end_date'], 'branch_id' => $payload['selected_branch_id']])) }}">&larr; Kembali</a>
    </div>

    <h1>Dashboard Finance &amp; Data Analyst</h1>
    <p class="meta">Periode tanggal klinik: <strong>{{ $period }}</strong> ({{ $payload['day_count'] }} hari, WITA, inklusif) · dibuat {{ $payload['generated_at'] }} WITA · dokumen hanya baca</p>

    <table>
        <thead>
            <tr>
                <th>Cabang</th>
                <th class="num">Pasien (check-in, unik)</th>
                <th class="num">Transaksi (batch kasir)</th>
                <th class="num">Omzet (pembayaran RME)</th>
                <th class="num">Piutang Aktif Per Saat Ini (saldo saat ini)</th>
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
        Periode = tanggal mulai s.d. tanggal akhir, keduanya termasuk (tanggal klinik WITA).
        Pasien = pasien unik yang check-in dalam periode (bukan sekadar terdaftar; datang berulang dihitung satu). Transaksi = batch pembayaran kasir dalam periode.
        Omzet = pembayaran RME aktual dengan tanggal bayar dalam periode, diatribusikan ke cabang invoice; tagihan lab tidak termasuk.
        Piutang = sisa tagihan invoice UNPAID/PARTIAL per saat ini — saldo saat ini, tidak mengikuti periode tanggal dan bukan piutang historis periode.
        Cancel = kunjungan yang waktu pembatalannya dalam periode (bukan no-show).
    </p>
</body>
</html>
