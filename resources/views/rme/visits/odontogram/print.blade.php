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

        /* Daengtisia clinic header (page 1) */
        .clinic-header {
            display: flex;
            align-items: center;
            gap: 14px;
            border-bottom: 2px solid #111827;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .clinic-header .clinic-logo {
            height: 48px;
            width: auto;
            max-width: 160px;
            flex-shrink: 0;
        }
        .clinic-header h1 {
            font-size: 17px;
            font-weight: 700;
            color: #111827;
            letter-spacing: 0.02em;
        }
        .clinic-header .clinic-meta {
            font-size: 10px;
            color: #4b5563;
            margin-top: 2px;
            font-style: italic;
        }

        .doc-title {
            text-align: center;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #111827;
            margin: 6px 0 10px;
        }

        /* Nama / No. RM identity box */
        .identity {
            display: flex;
            border: 1px solid #111827;
            margin-bottom: 12px;
        }
        .identity > div {
            flex: 1;
            padding: 6px 10px;
            font-size: 12px;
        }
        .identity > div:first-child { border-right: 1px solid #111827; }
        .identity .lbl { font-weight: 700; }

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

        /* DMF-T tally */
        .dmft {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin: 10px 0 12px;
        }
        .dmft-item {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 5px 12px;
            font-size: 12px;
        }
        .dmft-item .v { font-weight: 700; }

        /* Tooth grid */
        .tooth-section { margin-bottom: 12px; }
        .tooth-grid-wrapper { overflow-x: auto; }
        .tooth-grid {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            min-width: max-content;
            gap: 3px;
        }
        .side-label {
            display: flex;
            width: 100%;
            font-size: 9px;
            color: #6b7280;
            margin-bottom: 2px;
        }
        .side-label-left  { flex: 1; text-align: right; padding-right: 10px; }
        .side-label-right { flex: 1; text-align: left;  padding-left:  10px; }
        .jaw-row {
            display: flex;
            align-items: stretch;
            justify-content: center;
            gap: 2px;
        }
        .jaw-divider {
            width: 100%;
            border-top: 2px dashed #d1d5db;
            margin: 4px 0;
        }
        .jaw-center {
            width: 1px;
            background: #9ca3af;
            margin: 0 4px;
            align-self: stretch;
            flex-shrink: 0;
        }
        .tooth-cell {
            width: 26px;
            height: 26px;
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
        .t-filling      { background: #bfdbfe;  color: #1e3a8a; border-color: #60a5fa; }
        .t-crown        { background: #fde68a;  color: #78350f; border-color: #fcd34d; }
        .t-root_treated { background: #bae6fd;  color: #0c4a6e; border-color: #38bdf8; }

        /* Legend */
        .legend {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 16px;
            margin-bottom: 12px;
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

        /* GIGI / DIAGNOSA / PERAWATAN / DOKTER data table — header repeats per page */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 11px;
        }
        .data-table thead { display: table-header-group; }
        .data-table th,
        .data-table td {
            border: 1px solid #111827;
            padding: 5px 8px;
            vertical-align: top;
        }
        .data-table th {
            background: #f3f4f6;
            text-align: center;
            font-weight: 700;
            color: #111827;
        }
        .data-table .repeat-title th {
            background: #fff;
            text-align: center;
            font-size: 12px;
        }
        .data-table .repeat-title .sub {
            display: block;
            font-weight: 600;
            font-size: 10px;
            margin-top: 2px;
            color: #374151;
        }
        .data-table .gigi { text-align: center; font-weight: 700; width: 50px; }
        .data-table tr { page-break-inside: avoid; }

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
        .summary-empty { color: #9ca3af; font-style: italic; }

        /* Print / actions */
        .print-actions { margin-bottom: 16px; display: flex; gap: 8px; }
        .btn-print {
            display: inline-flex; align-items: center; padding: 6px 14px;
            background: #0f766e; color: #fff; border: none; border-radius: 6px;
            font-size: 12px; font-weight: 600; cursor: pointer;
        }
        .btn-print:hover { background: #0d9488; }
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
        $payload   = $odontogram->tooth_map_payload ?? [];
        $teethData = ! empty($payload['teeth']) && is_array($payload['teeth']) ? $payload['teeth'] : [];

        $statusLabels = [
            'normal'       => 'Normal',
            'caries'       => 'Karies',
            'missing'      => 'Hilang',
            'filling'      => 'Tambalan',
            'crown'        => 'Crown',
            'root_treated' => 'PSA',
        ];
        // Legacy per-tooth clinical signs (Sprint 20) — preserved in DIAGNOSA detail.
        $conditionLabels = [
            'caries' => 'Karies',
            'missing' => 'Hilang',
            'crown' => 'Crown',
            'root_treated' => 'PSA',
            'mobility' => 'Goyang',
            'impaction' => 'Impaksi',
            'filling' => 'Tambalan',
        ];

        // Permanent (Q1–Q4) + primary (Q5–Q8) display order: center → outer.
        $upperRight = [18, 17, 16, 15, 14, 13, 12, 11];
        $upperLeft  = [21, 22, 23, 24, 25, 26, 27, 28];
        $lowerRight = [48, 47, 46, 45, 44, 43, 42, 41];
        $lowerLeft  = [31, 32, 33, 34, 35, 36, 37, 38];
        $upperRightPrimary = [55, 54, 53, 52, 51];
        $upperLeftPrimary  = [61, 62, 63, 64, 65];
        $lowerRightPrimary = [85, 84, 83, 82, 81];
        $lowerLeftPrimary  = [71, 72, 73, 74, 75];

        $toothClass = function (string $status): string {
            return match ($status) {
                'normal'       => 't-normal',
                'caries'       => 't-caries',
                'missing'      => 't-missing',
                'filling'      => 't-filling',
                'crown'        => 't-crown',
                'root_treated' => 't-root_treated',
                default        => 't-default',
            };
        };

        $dmft = $odontogram->dmftCounts();
        $branchTitle = strtoupper($clinicVisit->branch?->name ?: 'Telkomas');
        $patientName = $clinicVisit->patient?->name ?? '—';
        $rmNumber = $clinicVisit->patient?->medical_record_number ?? '—';

        // Selected rows = teeth carrying a DIAGNOSA (status) or any per-row text.
        $markedTeeth = array_filter($teethData, fn ($td) =>
            ! empty($td['status'])
            || (isset($td['additional_condition']) && $td['additional_condition'] !== '')
            || (isset($td['additional_note']) && $td['additional_note'] !== '')
            || (isset($td['dokter']) && $td['dokter'] !== '')
        );
        ksort($markedTeeth, SORT_NATURAL);

        $renderToothRow = function (array $right, array $left) use ($teethData, $toothClass) {
            $html = '<div class="jaw-row">';
            foreach ($right as $t) {
                $st = $teethData[(string) $t]['status'] ?? '';
                $html .= '<div class="tooth-cell '.($st ? $toothClass($st) : 't-default').'" title="Gigi '.$t.'">'.$t.'</div>';
            }
            $html .= '<div class="jaw-center"></div>';
            foreach ($left as $t) {
                $st = $teethData[(string) $t]['status'] ?? '';
                $html .= '<div class="tooth-cell '.($st ? $toothClass($st) : 't-default').'" title="Gigi '.$t.'">'.$t.'</div>';
            }
            $html .= '</div>';

            return $html;
        };
    @endphp

    {{-- Daengtisia clinic header (page 1) --}}
    <div class="clinic-header">
        <x-brand.daengtisia-logo class="clinic-logo" />
        <div>
        <h1>KLINIK GIGI DAENGTISIA</h1>
        <div class="clinic-meta">
            Jl. Telkomas Raya, Ruko No. 07, Kel. Berua, Kec. Biringkanaya, Kota Makassar, Sulawesi Selatan. 90245.<br>
            Telp/.WA +62 895 3253 54253 / 085757297146
        </div>
        </div>
    </div>

    <div class="doc-title">ODONTOGRAM GIGI PASIEN {{ $branchTitle }}</div>

    {{-- Nama / No. RM --}}
    <div class="identity">
        <div><span class="lbl">Nama :</span> {{ $patientName }}</div>
        <div><span class="lbl">No. RM :</span> {{ $rmNumber }}</div>
    </div>

    {{-- Generated FDI visual (permanent + primary) --}}
    <div class="tooth-section">
        <div class="tooth-grid-wrapper">
            <div class="tooth-grid">
                <div class="side-label">
                    <span class="side-label-left">KANAN / RIGHT</span>
                    <span style="width:10px"></span>
                    <span class="side-label-right">KIRI / LEFT</span>
                </div>
                {!! $renderToothRow($upperRight, $upperLeft) !!}
                {!! $renderToothRow($upperRightPrimary, $upperLeftPrimary) !!}
                <div class="jaw-divider"></div>
                {!! $renderToothRow($lowerRightPrimary, $lowerLeftPrimary) !!}
                {!! $renderToothRow($lowerRight, $lowerLeft) !!}
            </div>
        </div>
    </div>

    {{-- Jumlah D / M / F / DMF-T --}}
    <div class="dmft">
        <div class="dmft-item">Jumlah D = <span class="v">{{ $dmft['D'] }}</span></div>
        <div class="dmft-item">Jumlah M = <span class="v">{{ $dmft['M'] }}</span></div>
        <div class="dmft-item">Jumlah F = <span class="v">{{ $dmft['F'] }}</span></div>
        <div class="dmft-item">Jumlah DMF-T = <span class="v">{{ $dmft['DMFT'] }}</span></div>
    </div>

    {{-- Legend --}}
    <div class="legend">
        <span class="legend-item"><span class="legend-swatch t-caries"></span> D = Decay (Karies)</span>
        <span class="legend-item"><span class="legend-swatch t-missing"></span> M = Missing (Hilang)</span>
        <span class="legend-item"><span class="legend-swatch t-filling"></span> F = Filling (Tambalan)</span>
        <span class="legend-item"><span class="legend-swatch t-crown"></span> Crown</span>
        <span class="legend-item"><span class="legend-swatch t-root_treated"></span> PSA</span>
    </div>

    {{-- GIGI / DIAGNOSA / PERAWATAN / DOKTER table (header repeats on page 2+) --}}
    <div class="section-title">Hasil Odontogram yang Dipilih</div>
    @if (count($markedTeeth) > 0)
        <table class="data-table">
            <thead>
                <tr class="repeat-title">
                    <th colspan="4">
                        ODONTOGRAM GIGI PASIEN {{ $branchTitle }}
                        <span class="sub">Nama : {{ $patientName }} &nbsp;&nbsp; No. RM : {{ $rmNumber }}</span>
                    </th>
                </tr>
                <tr>
                    <th class="gigi">GIGI</th>
                    <th>DIAGNOSA</th>
                    <th>PERAWATAN</th>
                    <th style="width:130px">DOKTER</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($markedTeeth as $toothNum => $td)
                    @php
                        $diagnosaParts = [];
                        $statusLabel = $statusLabels[$td['status'] ?? ''] ?? ($td['status'] ? ucfirst($td['status']) : '');
                        if ($statusLabel !== '') {
                            $diagnosaParts[] = $statusLabel;
                        }
                        if (isset($td['additional_condition']) && $td['additional_condition'] !== '') {
                            $diagnosaParts[] = $td['additional_condition'];
                        }
                        if (! empty($td['conditions']) && is_array($td['conditions'])) {
                            foreach ($td['conditions'] as $cond) {
                                $diagnosaParts[] = $conditionLabels[$cond] ?? $cond;
                            }
                        }
                        if (isset($td['note']) && $td['note'] !== '') {
                            $diagnosaParts[] = $td['note'];
                        }
                        $diagnosa = implode(' — ', $diagnosaParts);
                        $perawatan = (isset($td['additional_note']) && $td['additional_note'] !== '') ? $td['additional_note'] : '';
                        $dokter = (isset($td['dokter']) && $td['dokter'] !== '') ? $td['dokter'] : '';
                    @endphp
                    <tr>
                        <td class="gigi">{{ $toothNum }}</td>
                        <td>{{ $diagnosa !== '' ? $diagnosa : '—' }}</td>
                        <td>{{ $perawatan !== '' ? $perawatan : '—' }}</td>
                        <td>{{ $dokter !== '' ? $dokter : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p style="color:#9ca3af; font-style:italic; margin-bottom:14px; font-size:11px;">
            Belum ada kondisi odontogram yang dipilih.
        </p>
    @endif

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
