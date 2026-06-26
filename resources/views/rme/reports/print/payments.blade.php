<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Pembayaran RME</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1a1a1a; background: #fff; padding: 20px 24px; }
        .header { display: flex; align-items: center; gap: 14px; border-bottom: 2px solid #0f766e; padding-bottom: 10px; margin-bottom: 14px; }
        .header .clinic-logo { height: 44px; width: auto; max-width: 150px; flex-shrink: 0; }
        .header h1 { font-size: 18px; font-weight: 700; color: #0f766e; }
        .meta { font-size: 11px; color: #6b7280; margin-bottom: 12px; }
        .summary { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .summary span { background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 4px; padding: 4px 10px; font-size: 11px; }
        .filters { margin-bottom: 14px; padding: 8px 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 11px; color: #374151; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-weight: 600; }
        td.amount { text-align: right; }
        .no-print { margin-bottom: 16px; }
        .btn { background: #0f766e; color: #fff; border: none; border-radius: 6px; padding: 8px 16px; font-size: 13px; cursor: pointer; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button type="button" class="btn" onclick="window.print()">Cetak</button>
    </div>

    <div class="header">
        <x-brand.daengtisia-logo class="clinic-logo" />
        <div>
            <h1>Laporan Pembayaran RME</h1>
            <p class="meta">Dicetak: {{ $printedAt->format('d/m/Y H:i') }}</p>
        </div>
    </div>

    @if (! empty($filterSummary))
        <div class="filters">
            <strong>Filter aktif:</strong>
            {{ implode(' · ', $filterSummary) }}
        </div>
    @endif

    <div class="summary">
        <span>Total Pasien Hasil Filter: <strong>{{ number_format($totalFilteredPatients) }} pasien</strong></span>
        <span>Total Baris Transaksi: <strong>{{ number_format($totalFilteredTransactions) }} transaksi</strong></span>
        <span>Total Pembayaran Hasil Filter: <strong>{{ format_currency_id($totalPaymentAmount) }}</strong></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>ID/RM Pasien</th>
                <th>Nama Pasien</th>
                <th>Tanggal Kunjungan</th>
                <th>Metode Pembayaran</th>
                <th>Treatment</th>
                <th>Dokter</th>
                <th>Status Invoice</th>
                <th>Total Tagihan</th>
                <th>Nominal Pembayaran</th>
                <th>Tanggal Pembayaran</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payments as $payment)
                @php
                    $treatmentNames = $payment->rmeInvoice?->items
                        ?->pluck('treatment.name')
                        ->filter()
                        ->unique()
                        ->values();
                    $doctorNames = collect([$payment->clinicVisit?->doctor?->name])
                        ->merge($payment->rmeInvoice?->items?->pluck('doctor.name') ?? [])
                        ->filter()
                        ->unique()
                        ->values();
                @endphp
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $payment->patient?->medical_record_number ?? ('#'.$payment->patient_id) }}</td>
                    <td>{{ $payment->patient?->name ?? '—' }}</td>
                    <td>{{ $payment->clinicVisit?->visit_date?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $payment->paymentMethod?->name ?? '—' }}</td>
                    <td>{{ $treatmentNames?->isNotEmpty() ? $treatmentNames->join(', ') : '—' }}</td>
                    <td>{{ $doctorNames->isNotEmpty() ? $doctorNames->join(', ') : '—' }}</td>
                    <td>{{ $payment->rmeInvoice?->status ?? '—' }}</td>
                    <td class="amount">{{ format_currency_id($payment->rmeInvoice?->grand_total ?? 0) }}</td>
                    <td class="amount">{{ format_currency_id($payment->amount) }}</td>
                    <td>{{ $payment->paid_at?->format('d/m/Y H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="11" style="text-align:center;color:#9ca3af;">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
