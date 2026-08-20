<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Persetujuan Tindakan Medis — {{ $consent->consent_number }}</title>
    <style>
        @page { size: A4 portrait; margin: 14mm 14mm 12mm 14mm; }

        html, body {
            margin: 0;
            padding: 0;
            background: #fff;
        }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #111827;
        }

        .clinic-header {
            border-bottom: 2px solid #1D4ED8;
            padding-bottom: 6px;
            margin-bottom: 10px;
        }

        .clinic-name {
            font-size: 13px;
            font-weight: bold;
            color: #1E40AF;
            text-transform: uppercase;
        }

        .clinic-meta {
            font-size: 10px;
            color: #374151;
        }

        .print-actions {
            text-align: right;
            margin-bottom: 10px;
        }

        .print-actions button {
            font-family: inherit;
            font-size: 12px;
            padding: 6px 14px;
            border: 1px solid #1D4ED8;
            background: #1D4ED8;
            color: #fff;
            border-radius: 4px;
            cursor: pointer;
        }

        @media print {
            .print-actions { display: none; }
        }
    </style>
    @include('rme.consents.partials.document-styles')
</head>
<body>

    <div class="print-actions">
        <button type="button" onclick="window.print()">Cetak</button>
    </div>

    <div class="clinic-header">
        <div class="clinic-name">{{ $branch?->name ?: 'Klinik Gigi Daengtisia' }}</div>
        @if (filled($branch?->address))
            <div class="clinic-meta">{{ $branch->address }}</div>
        @endif
        @if (filled($branch?->phone))
            <div class="clinic-meta">Telp: {{ $branch->phone }}</div>
        @endif
    </div>

    @include('rme.consents.partials.document')

</body>
</html>
