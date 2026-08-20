<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Odontogram — {{ $clinicVisit->patient?->name ?? 'Pasien' }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 12px;
            color: #1a1a1a;
            background: #fff;
            padding: 20px 24px;
        }

        .section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #374151;
            margin: 12px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e7eb;
        }

        .summary-box {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 12px;
            font-size: 12px;
            white-space: pre-wrap;
            color: #374151;
        }

        /* Print / actions */
        .print-actions { margin-bottom: 16px; display: flex; gap: 8px; }
        .btn-print {
            display: inline-flex; align-items: center; padding: 6px 14px;
            background: #1D4ED8; color: #fff; border: none; border-radius: 6px;
            font-size: 12px; font-weight: 600; cursor: pointer;
        }
        .btn-print:hover { background: #1E40AF; }
        .btn-close {
            display: inline-flex; align-items: center; padding: 6px 14px;
            background: #f3f4f6; color: #374151; border: 1px solid #d1d5db;
            border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer;
            text-decoration: none;
        }

        .footer {
            margin-top: 18px; padding-top: 8px; border-top: 1px solid #e5e7eb;
            font-size: 10px; color: #9ca3af; display: flex; justify-content: space-between;
        }

        @media print {
            body { padding: 10px 14px; }
            .print-actions { display: none; }
            .footer { page-break-inside: avoid; }
            @page { margin: 1cm; }
        }
    </style>
</head>
<body>

    {{-- Print / Close actions (hidden on print) --}}
    <div class="print-actions">
        <button class="btn-print" onclick="window.print()">&#128438; Cetak / Simpan PDF</button>
        <a href="{{ route('rme.visits.odontogram.show', $clinicVisit) }}" class="btn-close">&larr; Kembali</a>
    </div>

    @php
        // FIX-01 — identity always follows the record's own branch.
        $branchTitle = strtoupper($clinicVisit->branch?->name ?: '');
        $patientName = $clinicVisit->patient?->name ?? '—';
        $rmNumber = $clinicVisit->patient?->medical_record_number ?? '—';
    @endphp

    {{-- Sprint 63.1 — shared structured Daengtisia template (header + visual + DMF-T + legend + table) --}}
    @include('rme.visits.odontogram.partials.structured-print-template', [
        'structured' => $structured,
        'patientName' => $patientName,
        'rmNumber' => $rmNumber,
        'branchTitle' => $branchTitle,
        'branchAddress' => $clinicVisit->branch?->address,
        'branchPhone' => $clinicVisit->branch?->phone,
        'showHeader' => true,
        'showVisual' => true,
    ])

    {{-- Legacy general notes (preserved for backward compatibility; hidden from the
         doctor input UI since Sprint 60.2 but still printed when present). --}}
    @if ($odontogram->additional_conditions)
        <div class="section-title">Kondisi Tambahan</div>
        <div class="summary-box">{{ $odontogram->additional_conditions }}</div>
    @endif
    @if ($odontogram->summary_notes)
        <div class="section-title">Catatan Odontogram</div>
        <div class="summary-box">{{ $odontogram->summary_notes }}</div>
    @endif

    {{-- Footer --}}
    <div class="footer">
        <span>{{ config('app.name') }} — Dicetak {{ now()->format('d/m/Y H:i') }}</span>
        <span>
            Status: {{ $odontogram->isFinalized() ? 'Final' : 'Draft' }}
            @if ($odontogram->isFinalized() && $odontogram->finalized_at)
                ({{ $odontogram->finalized_at->format('d/m/Y H:i') }})
            @endif
            &middot; Odontogram #{{ $odontogram->id }} &mdash; Kunjungan {{ $clinicVisit->visit_number ?? '—' }}
        </span>
    </div>

</body>
</html>
