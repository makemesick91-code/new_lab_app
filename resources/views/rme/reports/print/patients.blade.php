<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Pasien RME</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1a1a1a; background: #fff; padding: 20px 24px; }
        .header { border-bottom: 2px solid #0f766e; padding-bottom: 10px; margin-bottom: 14px; }
        .header h1 { font-size: 18px; font-weight: 700; color: #0f766e; }
        .meta { font-size: 11px; color: #6b7280; margin-bottom: 12px; }
        .summary { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .summary span { background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 4px; padding: 4px 10px; font-size: 11px; }
        .filters { margin-bottom: 14px; padding: 8px 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 11px; color: #374151; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { border: 1px solid #e5e7eb; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; font-weight: 600; }
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
        <h1>Laporan Pasien RME</h1>
        <p class="meta">Dicetak: {{ $printedAt->format('d/m/Y H:i') }}</p>
    </div>

    @if (! empty($filterSummary))
        <div class="filters">
            <strong>Filter aktif:</strong>
            {{ implode(' · ', $filterSummary) }}
        </div>
    @endif

    <div class="summary">
        <span>Total Pasien Hasil Filter: <strong>{{ number_format($totalFilteredPatients) }}</strong></span>
        <span>Total Baris Kunjungan: <strong>{{ number_format($visits->count()) }}</strong></span>
    </div>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>ID/RM Pasien</th>
                <th>Nama Pasien</th>
                <th>Tanggal Kunjungan</th>
                <th>Status</th>
                <th>Dokter</th>
                <th>Cabang</th>
                <th>Keluhan Utama</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($visits as $visit)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $visit->patient?->medical_record_number ?? ('#'.$visit->patient_id) }}</td>
                    <td>{{ $visit->patient?->name ?? '—' }}</td>
                    <td>{{ $visit->visit_date?->format('d/m/Y') ?? '—' }}</td>
                    <td>{{ $statusOptions[$visit->status] ?? $visit->status }}</td>
                    <td>{{ $visit->doctor?->name ?? '—' }}</td>
                    <td>{{ $visit->branch?->name ?? '—' }}</td>
                    <td>{{ $visit->chief_complaint ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:#9ca3af;">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
