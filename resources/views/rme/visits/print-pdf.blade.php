<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>RME — {{ $visit->patient?->name ?? 'Pasien' }} — {{ $visit->visit_number ?? '' }}</title>
    <style>
        @page { margin: 1cm; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            line-height: 1.35;
        }
        .header {
            border-bottom: 2px solid #0f766e;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .header .clinic-logo { height: 42px; width: auto; max-width: 150px; vertical-align: middle; }
        .header .header-text { display: inline-block; vertical-align: middle; padding-left: 12px; }
        .header h1 { font-size: 16px; font-weight: 700; color: #0f766e; }
        .header .app-name { font-size: 10px; color: #6b7280; }
        .info-grid {
            width: 100%;
            margin-bottom: 14px;
            padding: 8px 10px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
        }
        .info-row { margin-bottom: 6px; }
        .info-row dt {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            color: #6b7280;
        }
        .info-row dd { font-size: 11px; color: #111827; }
        .section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: #374151;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e7eb;
        }
        .section-block { margin-bottom: 14px; page-break-inside: avoid; }
        .status-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 700;
            border: 1px solid #d1d5db;
        }
        .status-final { background: #f0fdf4; color: #166534; }
        .status-draft { background: #fffbeb; color: #92400e; }
        .status-finalized { background: #eff6ff; color: #1e40af; }
        .odonto-summary, .handwriting-preview, .not-available {
            padding: 8px 10px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
        }
        .odonto-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 10px;
        }
        .odonto-table th, .odonto-table td {
            padding: 4px 6px;
            border: 1px solid #e5e7eb;
            text-align: left;
        }
        .odonto-table th { background: #f3f4f6; font-size: 9px; text-transform: uppercase; }
        .field-empty, .meta-line { color: #6b7280; font-size: 10px; margin-bottom: 6px; }
        .handwriting-preview img {
            width: 100%;
            max-height: 280px;
            border: 1px solid #d1d5db;
        }
        .footer {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #e5e7eb;
            font-size: 9px;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="header">
        <x-brand.daengtisia-logo :pdf="true" class="clinic-logo" />
        <span class="header-text">
            <div class="app-name">{{ config('app.name') }}</div>
            <h1>Rekam Medis Elektronik</h1>
        </span>
    </div>

    @include('rme.visits.partials.print-body', [
        'visit' => $visit,
        'paidInvoice' => $paidInvoice,
        'payment' => $payment,
        'labCaseCandidates' => $labCaseCandidates,
        'odontogramPrint' => $odontogramPrint ?? null,
    ])

    <div class="footer">
        {{ config('app.name') }} — Dicetak {{ now()->format('d/m/Y H:i') }} — Kunjungan {{ $visit->visit_number ?? '—' }}
    </div>
</body>
</html>
