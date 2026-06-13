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

        .header {
            border-bottom: 2px solid #0f766e;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .header h1 {
            font-size: 18px;
            font-weight: 700;
            color: #0f766e;
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

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid;
        }
        .status-draft  { background: #fffbeb; color: #92400e; border-color: #f59e0b; }
        .status-final  { background: #eff6ff; color: #1e40af; border-color: #3b82f6; }

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

        .summary-box {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 8px 12px;
            margin-bottom: 14px;
            font-size: 12px;
            white-space: pre-wrap;
            color: #374151;
        }
        .summary-empty {
            color: #9ca3af;
            font-style: italic;
        }

        /* Tooth grid */
        .tooth-section { margin-bottom: 14px; }
        .tooth-grid-wrapper { overflow-x: auto; }
        .tooth-grid {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            min-width: max-content;
        }
        .jaw-label {
            display: flex;
            width: 100%;
            font-size: 9px;
            color: #9ca3af;
        }
        .jaw-label-left  { flex: 1; text-align: right; padding-right: 10px; }
        .jaw-label-right { flex: 1; text-align: left;  padding-left:  10px; }
        .jaw-row {
            display: flex;
            align-items: stretch;
            gap: 2px;
        }
        .jaw-divider {
            width: 100%;
            border-top: 2px dashed #d1d5db;
            margin: 6px 0;
        }
        .jaw-center {
            width: 1px;
            background: #9ca3af;
            margin: 0 4px;
            align-self: stretch;
            flex-shrink: 0;
        }
        .tooth-cell {
            width: 28px;
            height: 28px;
            border-radius: 4px;
            border: 1px solid;
            font-size: 9px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .t-default      { background: #fff;     color: #6b7280; border-color: #d1d5db; }
        .t-normal       { background: #d1fae5;  color: #065f46; border-color: #6ee7b7; }
        .t-caries       { background: #fecaca;  color: #7f1d1d; border-color: #f87171; }
        .t-missing      { background: #1f2937;  color: #fff;    border-color: #4b5563; }
        .t-crown        { background: #fde68a;  color: #78350f; border-color: #fcd34d; }
        .t-root_treated { background: #bae6fd;  color: #0c4a6e; border-color: #38bdf8; }

        /* Per-tooth detail table */
        .detail-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 11px;
        }
        .detail-table th {
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            padding: 5px 8px;
            text-align: left;
            font-weight: 600;
            color: #374151;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .detail-table td {
            border: 1px solid #e5e7eb;
            padding: 5px 8px;
            vertical-align: top;
            color: #374151;
        }
        .detail-table tr:nth-child(even) td { background: #f9fafb; }

        .cond-chip {
            display: inline-flex;
            align-items: center;
            padding: 1px 6px;
            border-radius: 9999px;
            font-size: 10px;
            background: #ccfbf1;
            color: #134e4a;
            border: 1px solid #99f6e4;
            margin: 1px 2px 1px 0;
        }

        /* Legend */
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 16px;
            margin-bottom: 14px;
            padding: 8px 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
        }
        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
            color: #374151;
        }
        .legend-swatch {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            border: 1px solid;
            flex-shrink: 0;
        }

        /* Print button */
        .print-actions {
            margin-bottom: 16px;
            display: flex;
            gap: 8px;
        }
        .btn-print {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            background: #0f766e;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-print:hover { background: #0d9488; }
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
            cursor: pointer;
            text-decoration: none;
        }

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

    {{-- Document header --}}
    <div class="header">
        <div class="app-name">{{ config('app.name') }}</div>
        <h1>Odontogram</h1>
    </div>

    {{-- Patient / Visit info --}}
    <div class="info-grid">
        <div class="info-row">
            <dt>Nama Pasien</dt>
            <dd>{{ $clinicVisit->patient?->name ?? '—' }}</dd>
        </div>
        <div class="info-row">
            <dt>No. Rekam Medis</dt>
            <dd>{{ $clinicVisit->patient?->medical_record_number ?? '—' }}</dd>
        </div>
        <div class="info-row">
            <dt>No. Kunjungan</dt>
            <dd>{{ $clinicVisit->visit_number ?? '—' }}</dd>
        </div>
        <div class="info-row">
            <dt>Tanggal Kunjungan</dt>
            <dd>{{ $clinicVisit->visit_date?->format('d/m/Y') ?? '—' }}</dd>
        </div>
        <div class="info-row">
            <dt>Dokter</dt>
            <dd>{{ $clinicVisit->doctor?->name ?? '—' }}</dd>
        </div>
        <div class="info-row">
            <dt>Status Odontogram</dt>
            <dd>
                @if ($odontogram->isFinalized())
                    <span class="status-badge status-final">Final</span>
                    @if ($odontogram->finalized_at)
                        &nbsp;{{ $odontogram->finalized_at->format('d/m/Y H:i') }}
                        @if ($odontogram->finalizer)
                            oleh {{ $odontogram->finalizer->name }}
                        @endif
                    @endif
                @else
                    <span class="status-badge status-draft">Draft</span>
                @endif
            </dd>
        </div>
    </div>

    @php
        $payload   = $odontogram->tooth_map_payload ?? [];
        $teethData = ! empty($payload['teeth']) ? $payload['teeth'] : [];

        $statusLabels = [
            'normal'       => 'Normal',
            'caries'       => 'Karies',
            'missing'      => 'Hilang',
            'crown'        => 'Crown',
            'root_treated' => 'PSA',
        ];
        $conditionLabels = [
            'caries'       => 'Karies',
            'missing'      => 'Hilang',
            'crown'        => 'Crown',
            'root_treated' => 'PSA',
            'mobility'     => 'Mobility',
            'impaction'    => 'Impaksi',
            'filling'      => 'Tambalan',
        ];

        $upperRight = [18, 17, 16, 15, 14, 13, 12, 11];
        $upperLeft  = [21, 22, 23, 24, 25, 26, 27, 28];
        $lowerRight = [48, 47, 46, 45, 44, 43, 42, 41];
        $lowerLeft  = [31, 32, 33, 34, 35, 36, 37, 38];

        $allTeeth = array_merge($upperRight, $upperLeft, array_reverse($lowerRight), $lowerLeft);
        sort($allTeeth);

        $toothClass = function (string $status): string {
            return match ($status) {
                'normal'       => 't-normal',
                'caries'       => 't-caries',
                'missing'      => 't-missing',
                'crown'        => 't-crown',
                'root_treated' => 't-root_treated',
                default        => 't-default',
            };
        };
    @endphp

    {{-- Kondisi tambahan (general) --}}
    <div class="section-title">Kondisi Tambahan</div>
    <div class="summary-box">
        @if ($odontogram->additional_conditions)
            {{ $odontogram->additional_conditions }}
        @else
            <span class="summary-empty">Tidak ada kondisi tambahan.</span>
        @endif
    </div>

    {{-- Catatan odontogram --}}
    <div class="section-title">Catatan Odontogram</div>
    <div class="summary-box">
        @if ($odontogram->summary_notes)
            {{ $odontogram->summary_notes }}
        @else
            <span class="summary-empty">Tidak ada catatan odontogram.</span>
        @endif
    </div>

    {{-- Legend --}}
    <div class="legend">
        <span class="legend-item">
            <span class="legend-swatch t-default"></span> Normal (default)
        </span>
        <span class="legend-item">
            <span class="legend-swatch t-normal"></span> Normal (ditandai)
        </span>
        <span class="legend-item">
            <span class="legend-swatch t-caries"></span> Karies
        </span>
        <span class="legend-item">
            <span class="legend-swatch t-missing"></span> Hilang
        </span>
        <span class="legend-item">
            <span class="legend-swatch t-crown"></span> Crown
        </span>
        <span class="legend-item">
            <span class="legend-swatch t-root_treated"></span> PSA (Perawatan Saluran Akar)
        </span>
    </div>

    {{-- Tooth grid --}}
    <div class="tooth-section">
        <div class="section-title">Peta Gigi (FDI — 32 Gigi)</div>
        <div class="tooth-grid-wrapper">
            <div class="tooth-grid">

                <div class="jaw-label">
                    <span class="jaw-label-left">← Kanan atas (Q1)</span>
                    <span style="width:10px"></span>
                    <span class="jaw-label-right">Kiri atas (Q2) →</span>
                </div>

                <div class="jaw-row">
                    @foreach ($upperRight as $tooth)
                        @php $td = $teethData[(string)$tooth] ?? []; $st = $td['status'] ?? ''; @endphp
                        <div class="tooth-cell {{ $st ? $toothClass($st) : 't-default' }}" title="Gigi {{ $tooth }}">{{ $tooth }}</div>
                    @endforeach
                    <div class="jaw-center"></div>
                    @foreach ($upperLeft as $tooth)
                        @php $td = $teethData[(string)$tooth] ?? []; $st = $td['status'] ?? ''; @endphp
                        <div class="tooth-cell {{ $st ? $toothClass($st) : 't-default' }}" title="Gigi {{ $tooth }}">{{ $tooth }}</div>
                    @endforeach
                </div>

                <div class="jaw-divider"></div>

                <div class="jaw-row">
                    @foreach ($lowerRight as $tooth)
                        @php $td = $teethData[(string)$tooth] ?? []; $st = $td['status'] ?? ''; @endphp
                        <div class="tooth-cell {{ $st ? $toothClass($st) : 't-default' }}" title="Gigi {{ $tooth }}">{{ $tooth }}</div>
                    @endforeach
                    <div class="jaw-center"></div>
                    @foreach ($lowerLeft as $tooth)
                        @php $td = $teethData[(string)$tooth] ?? []; $st = $td['status'] ?? ''; @endphp
                        <div class="tooth-cell {{ $st ? $toothClass($st) : 't-default' }}" title="Gigi {{ $tooth }}">{{ $tooth }}</div>
                    @endforeach
                </div>

                <div class="jaw-label" style="margin-top:4px">
                    <span class="jaw-label-left">← Kanan bawah (Q4)</span>
                    <span style="width:10px"></span>
                    <span class="jaw-label-right">Kiri bawah (Q3) →</span>
                </div>

            </div>
        </div>
    </div>

    {{-- Selected odontogram results — per-row Kondisi Tambahan & Catatan Tambahan --}}
    <div class="section-title">Hasil Odontogram yang Dipilih</div>
    @php
        $markedTeeth = array_filter($teethData, fn ($td) =>
            ! empty($td['status']) || ! empty($td['conditions']) || (isset($td['note']) && $td['note'] !== '' && $td['note'] !== null)
        );
        ksort($markedTeeth);
    @endphp

    @if (count($markedTeeth) > 0)
        <table class="detail-table">
            <thead>
                <tr>
                    <th style="width:34px">No</th>
                    <th style="width:50px">Gigi / Area</th>
                    <th style="width:80px">Kondisi Odontogram</th>
                    <th style="width:90px">Tanda Klinis</th>
                    <th>Catatan Gigi</th>
                    <th>Kondisi Tambahan</th>
                    <th>Catatan Tambahan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($markedTeeth as $toothNum => $td)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $toothNum }}</strong></td>
                        <td>{{ $statusLabels[$td['status'] ?? ''] ?? ($td['status'] ? ucfirst($td['status']) : '—') }}</td>
                        <td>
                            @if (! empty($td['conditions']) && is_array($td['conditions']))
                                @foreach ($td['conditions'] as $cond)
                                    <span class="cond-chip">{{ $conditionLabels[$cond] ?? $cond }}</span>
                                @endforeach
                            @else
                                <span style="color:#9ca3af">—</span>
                            @endif
                        </td>
                        <td>{{ (isset($td['note']) && $td['note'] !== '') ? $td['note'] : '—' }}</td>
                        <td>{{ (isset($td['additional_condition']) && $td['additional_condition'] !== '') ? $td['additional_condition'] : '—' }}</td>
                        <td>{{ (isset($td['additional_note']) && $td['additional_note'] !== '') ? $td['additional_note'] : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="color:#9ca3af; font-style:italic; margin-bottom:14px; font-size:11px;">
            Belum ada kondisi odontogram yang dipilih.
        </p>
    @endif

    {{-- Footer --}}
    <div class="footer">
        <span>{{ config('app.name') }} — Dicetak {{ now()->format('d/m/Y H:i') }}</span>
        <span>Odontogram #{{ $odontogram->id }} &mdash; Kunjungan {{ $clinicVisit->visit_number ?? '—' }}</span>
    </div>

</body>
</html>
