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
            border-bottom: 2px solid #4f46e5;
            padding-bottom: 10px;
            margin-bottom: 14px;
        }
        .header h1 {
            font-size: 18px;
            font-weight: 700;
            color: #4f46e5;
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

        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 24px;
            padding: 8px 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
        }
        .field-item dt {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b7280;
        }
        .field-item dd {
            font-size: 12px;
            color: #111827;
            margin-top: 2px;
            white-space: pre-wrap;
        }
        .field-item.full-width {
            grid-column: 1 / -1;
        }
        .field-empty {
            color: #9ca3af;
            font-style: italic;
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

        .print-actions {
            margin-bottom: 16px;
            display: flex;
            gap: 8px;
        }
        .btn-print {
            display: inline-flex;
            align-items: center;
            padding: 6px 14px;
            background: #4f46e5;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-print:hover { background: #4338ca; }
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
            @page { margin: 1cm; }
        }
    </style>
</head>
<body>

    {{-- Print / Close actions (hidden on print) --}}
    <div class="print-actions">
        <button class="btn-print" onclick="window.print()">&#128438; Cetak / Simpan PDF</button>
        <a href="{{ route('rme.visits.show', $visit) }}" class="btn-close">&larr; Kembali</a>
    </div>

    {{-- Document header --}}
    <div class="header">
        <div class="app-name">{{ config('app.name') }}</div>
        <h1>Rekam Medis Elektronik</h1>
    </div>

    {{-- Patient & Visit Info --}}
    <div class="section-block">
        <div class="section-title">Data Pasien &amp; Kunjungan</div>
        <div class="info-grid">
            <div class="info-row">
                <dt>Nama Pasien</dt>
                <dd>{{ $visit->patient?->name ?? '—' }}</dd>
            </div>
            <div class="info-row">
                <dt>No. Rekam Medis</dt>
                <dd>{{ $visit->patient?->medical_record_number ?? '—' }}</dd>
            </div>
            <div class="info-row">
                <dt>No. Kunjungan</dt>
                <dd>{{ $visit->visit_number ?? '—' }}</dd>
            </div>
            <div class="info-row">
                <dt>Tanggal Kunjungan</dt>
                <dd>{{ $visit->visit_date?->format('d/m/Y') ?? '—' }}</dd>
            </div>
            <div class="info-row">
                <dt>Antrian</dt>
                <dd>#{{ $visit->queue_number ?? '—' }}</dd>
            </div>
            <div class="info-row">
                <dt>Dokter</dt>
                <dd>{{ $visit->doctor?->name ?? '—' }}</dd>
            </div>
            <div class="info-row" style="grid-column: 1 / -1;">
                <dt>Keluhan Utama</dt>
                <dd>{{ $visit->chief_complaint ?? '—' }}</dd>
            </div>
        </div>
    </div>

    {{-- Medical Record --}}
    @php $medicalRecord = $visit->medicalRecord; @endphp
    <div class="section-block">
        <div class="section-title">Rekam Medis
            @if ($medicalRecord)
                &nbsp;
                @if ($medicalRecord->status === \App\Modules\MedicalRecord\Models\MedicalRecord::STATUS_FINAL)
                    <span class="status-badge status-final">Final</span>
                @else
                    <span class="status-badge status-draft">Draft</span>
                @endif
            @endif
        </div>
        @if ($medicalRecord)
            @php
                $savedHandwriting = $medicalRecord->latestHandwriting();
                $hasLegacySoap = filled($medicalRecord->subjective)
                    || filled($medicalRecord->objective)
                    || filled($medicalRecord->assessment)
                    || filled($medicalRecord->plan)
                    || filled($medicalRecord->notes);
            @endphp

            @if ($hasLegacySoap)
                <div class="field-grid" style="margin-bottom: 10px;">
                    @if (filled($medicalRecord->subjective))
                        <div class="field-item">
                            <dt>Subjektif (Anamnesis)</dt>
                            <dd>{{ $medicalRecord->subjective }}</dd>
                        </div>
                    @endif
                    @if (filled($medicalRecord->objective))
                        <div class="field-item">
                            <dt>Objektif (Pemeriksaan)</dt>
                            <dd>{{ $medicalRecord->objective }}</dd>
                        </div>
                    @endif
                    @if (filled($medicalRecord->assessment))
                        <div class="field-item">
                            <dt>Assessment (Diagnosis)</dt>
                            <dd>{{ $medicalRecord->assessment }}</dd>
                        </div>
                    @endif
                    @if (filled($medicalRecord->plan))
                        <div class="field-item">
                            <dt>Plan (Rencana Perawatan)</dt>
                            <dd>{{ $medicalRecord->plan }}</dd>
                        </div>
                    @endif
                    @if (filled($medicalRecord->notes))
                        <div class="field-item full-width">
                            <dt>Catatan Tambahan</dt>
                            <dd>{{ $medicalRecord->notes }}</dd>
                        </div>
                    @endif
                </div>
            @endif

            <div class="handwriting-preview">
                <div class="section-title" style="margin-bottom: 8px; border-bottom: none; padding-bottom: 0;">RME Tulisan Tangan</div>
                @if ($savedHandwriting && $savedHandwriting->previewUrl())
                    <p class="handwriting-saved-at">
                        Tersimpan pada {{ $savedHandwriting->saved_at?->format('d/m/Y H:i') }}
                    </p>
                    <img src="{{ $savedHandwriting->previewUrl() }}"
                         alt="RME Tulisan Tangan">
                @else
                    <p class="field-empty">Belum ada handwriting RM.</p>
                @endif
            </div>
        @else
            <div class="not-available">Rekam medis belum tersedia.</div>
        @endif
    </div>

    {{-- Odontogram Summary --}}
    @php
        $odontogram = $visit->odontogram;
        $statusLabels = [
            'caries'       => 'Karies',
            'missing'      => 'Cabut/Missing',
            'crown'        => 'Mahkota',
            'root_treated' => 'Perawatan Saluran Akar',
            'mobility'     => 'Goyang',
            'impaction'    => 'Impaksi',
            'filling'      => 'Tambalan',
            'normal'       => 'Normal',
        ];
        $conditionLabels = $statusLabels;
    @endphp
    <div class="section-block">
        <div class="section-title">Odontogram
            @if ($odontogram)
                &nbsp;
                @if ($odontogram->isFinalized())
                    <span class="status-badge status-finalized">Final</span>
                @else
                    <span class="status-badge status-draft">Draft</span>
                @endif
            @endif
        </div>
        @if ($odontogram)
            <div class="odonto-summary">
                @if ($odontogram->summary_notes)
                    <div class="field-item">
                        <dt style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;">Catatan Ringkas</dt>
                        <div class="odonto-notes">{{ $odontogram->summary_notes }}</div>
                    </div>
                @endif

                @php
                    $teethData = $odontogram->tooth_map_payload['teeth'] ?? [];
                    $markedTeeth = array_filter($teethData, fn ($td) =>
                        ! empty($td['status']) || ! empty($td['conditions']) || (isset($td['note']) && $td['note'] !== '')
                    );
                    ksort($markedTeeth);
                @endphp

                @if (count($markedTeeth) > 0)
                    <table class="odonto-table" style="margin-top: {{ $odontogram->summary_notes ? '10px' : '0' }}">
                        <thead>
                            <tr>
                                <th style="width:55px">Gigi</th>
                                <th style="width:120px">Status</th>
                                <th>Kondisi Tambahan</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($markedTeeth as $toothNum => $td)
                                <tr>
                                    <td><strong>{{ $toothNum }}</strong></td>
                                    <td>{{ $statusLabels[$td['status'] ?? ''] ?? ($td['status'] ? ucfirst($td['status']) : '—') }}</td>
                                    <td>
                                        @if (! empty($td['conditions']) && is_array($td['conditions']))
                                            {{ implode(', ', array_map(fn ($c) => $conditionLabels[$c] ?? $c, $td['conditions'])) }}
                                        @else
                                            <span style="color:#9ca3af">—</span>
                                        @endif
                                    </td>
                                    <td>{{ (isset($td['note']) && $td['note'] !== '') ? $td['note'] : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p style="margin-top:6px;color:#9ca3af;font-style:italic;font-size:11px;">Belum ada gigi yang ditandai.</p>
                @endif
            </div>
        @else
            <div class="not-available">Odontogram belum tersedia.</div>
        @endif
    </div>

    {{-- Footer --}}
    <div class="footer">
        <span>{{ config('app.name') }} — Dicetak {{ now()->format('d/m/Y H:i') }}</span>
        <span>Kunjungan {{ $visit->visit_number ?? '—' }}</span>
    </div>

</body>
</html>
