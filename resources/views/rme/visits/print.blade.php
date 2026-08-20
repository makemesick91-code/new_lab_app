<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RME — {{ $visit->patient?->name ?? 'Pasien' }} — {{ $visit->visit_number ?? '' }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 12px;
            color: #1a1a1a;
            background: #fff;
            padding: 20px 24px;
        }

        .header {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 2px solid #1D4ED8;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .header .clinic-logo {
            height: 46px;
            width: auto;
            max-width: 160px;
            flex-shrink: 0;
        }
        .header h1 {
            font-size: 18px;
            font-weight: 700;
            color: #1D4ED8;
        }
        .header .app-name {
            font-size: 11px;
            color: #6b7280;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 24px;
            margin-bottom: 14px;
            padding: 10px 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
        }
        .info-row dt {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
        }
        .info-row dd {
            font-size: 12px;
            color: #111827;
            margin-top: 2px;
        }

        .section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #374151;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e7eb;
        }

        .section-block {
            margin-bottom: 14px;
        }

        .meta-line {
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid;
        }
        .status-draft     { background: #fffbeb; color: #92400e; border-color: #f59e0b; }
        .status-final     { background: #f0fdf4; color: #166534; border-color: #22c55e; }
        .status-finalized { background: #eff6ff; color: #1e40af; border-color: #3b82f6; }

        .odonto-summary {
            padding: 8px 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            font-size: 12px;
        }
        .odonto-notes {
            margin-top: 6px;
            color: #374151;
            white-space: pre-wrap;
        }
        .odonto-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 11px;
        }
        .odonto-table th {
            text-align: left;
            padding: 4px 6px;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
        }
        .odonto-table td {
            padding: 4px 6px;
            border: 1px solid #e5e7eb;
            vertical-align: top;
        }
        .odonto-table tr:nth-child(even) td { background: #fafafa; }

        .not-available {
            padding: 8px 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            color: #9ca3af;
            font-style: italic;
            font-size: 12px;
        }

        .handwriting-preview {
            padding: 8px 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
        }
        .handwriting-preview img {
            display: block;
            width: 100%;
            height: auto;
            object-fit: contain;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            page-break-inside: avoid;
        }
        .handwriting-saved-at {
            font-size: 10px;
            color: #6b7280;
            margin-bottom: 6px;
        }
        .field-empty {
            color: #9ca3af;
            font-style: italic;
        }

        .print-actions {
            margin-bottom: 16px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-print, .btn-pdf {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-print { background: #1D4ED8; }
        .btn-print:hover { background: #1E40AF; }
        .btn-pdf { background: #374151; }
        .btn-pdf:hover { background: #1f2937; }
        .btn-close {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
        }
        .btn-close:hover { background: #e5e7eb; }

        .footer {
            margin-top: 20px;
            padding-top: 8px;
            border-top: 1px solid #e5e7eb;
            font-size: 10px;
            color: #9ca3af;
            display: flex;
            justify-content: space-between;
        }

        @media print {
            body { padding: 10px 14px; }
            .print-actions { display: none; }
            .footer { page-break-inside: avoid; }
            .section-block { page-break-inside: avoid; }
            @page { margin: 1cm; }
        }
    </style>
</head>
<body>

    <div class="print-actions">
        <button class="btn-print" type="button" onclick="window.print()">&#128438; Cetak / Simpan PDF</button>
        <a href="{{ route('rme.visits.pdf', $visit) }}" class="btn-pdf">Unduh PDF</a>
        <a href="{{ route('rme.visits.show', $visit) }}" class="btn-close">&larr; Kembali</a>
    </div>

    <div class="header">
        <x-brand.daengtisia-logo class="clinic-logo" />
        <div>
            <div class="app-name">{{ config('app.name') }}</div>
            <h1>Rekam Medis Elektronik</h1>
        </div>
    </div>

    @include('rme.visits.partials.print-body', [
        'visit' => $visit,
        'paidInvoice' => $paidInvoice,
        'payment' => $payment,
        'labCaseCandidates' => $labCaseCandidates,
    ])

    <div class="footer">
        <span>{{ config('app.name') }} — Dicetak {{ now()->format('d/m/Y H:i') }}</span>
        <span>Kunjungan {{ $visit->visit_number ?? '—' }}</span>
    </div>

</body>
</html>
