<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Resep Dokter — {{ $prescription->patient_name_snapshot }}</title>
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
            text-align: center;
            border-bottom: 2px solid #0f766e;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .header .clinic-logo { height: 48px; width: auto; max-width: 140px; vertical-align: middle; margin-bottom: 6px; }
        .header h1 { font-size: 18px; font-weight: 700; color: #111827; }
        .header .branch { font-size: 13px; font-weight: 600; color: #0f766e; margin-top: 4px; }
        .header .address { font-size: 11px; color: #4b5563; margin-top: 4px; }
        .fields {
            display: table;
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .fields tr td {
            padding: 6px 8px;
            vertical-align: top;
            border-bottom: 1px solid #e5e7eb;
        }
        .fields .label {
            width: 38%;
            font-weight: 600;
            color: #374151;
        }
        .section-title {
            font-size: 13px;
            font-weight: 700;
            margin: 12px 0 8px;
            color: #111827;
        }
        .rx-image {
            width: 100%;
            max-height: 420px;
            object-fit: contain;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            background: #fff;
        }
        .signature-wrap {
            margin-top: 24px;
            text-align: right;
        }
        .signature-image {
            display: inline-block;
            max-width: 280px;
            max-height: 100px;
            object-fit: contain;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            background: #fff;
        }
        .signature-caption {
            margin-top: 6px;
            font-size: 12px;
            color: #374151;
        }
        .print-actions { margin-bottom: 16px; display: flex; gap: 8px; }
        .btn-print {
            display: inline-flex; align-items: center; padding: 8px 16px;
            background: #0f766e; color: #fff; border: none; border-radius: 6px;
            font-size: 12px; font-weight: 600; cursor: pointer;
        }
        .btn-close {
            display: inline-flex; align-items: center; padding: 8px 16px;
            background: #f3f4f6; color: #374151; border: 1px solid #d1d5db;
            border-radius: 6px; font-size: 12px; font-weight: 500; cursor: pointer;
            text-decoration: none;
        }
        .footer {
            margin-top: 20px; padding-top: 8px; border-top: 1px solid #e5e7eb;
            font-size: 10px; color: #9ca3af;
        }
        @media print {
            body { padding: 10px 14px; }
            .print-actions { display: none; }
            @page { margin: 1cm; }
        }
    </style>
</head>
<body>
    @php
        $branch = $clinicVisit->branch;
        $branchTitle = strtoupper($branch?->name ?: 'TELKOMAS');
        $rxUrl = $prescription->prescriptionCanvasUrl();
        $sigUrl = $prescription->signatureCanvasUrl();
        $dash = '—';
    @endphp

    <div class="print-actions">
        <button class="btn-print" type="button" onclick="window.print()">Cetak / Simpan PDF</button>
        <a href="{{ route('rme.visits.prescription.show', $clinicVisit) }}" class="btn-close">&larr; Kembali</a>
    </div>

    <div class="header">
        <x-brand.daengtisia-logo class="clinic-logo" />
        <h1>Klinik Gigi Daengtisia</h1>
        <p class="branch">CABANG {{ $branchTitle }}</p>
        <p class="address">{{ $branch?->address ?: 'Makassar' }}@if ($branch?->phone) &middot; {{ $branch->phone }}@endif</p>
    </div>

    <table class="fields">
        <tr>
            <td class="label">Dari Dokter</td>
            <td>{{ $prescription->prescribed_by_name }}</td>
        </tr>
        <tr>
            <td class="label">Tanggal Resep</td>
            <td>{{ $prescription->prescription_date?->format('d/m/Y') ?? $dash }}</td>
        </tr>
        <tr>
            <td class="label">Nama Pasien</td>
            <td>{{ $prescription->patient_name_snapshot }}</td>
        </tr>
        <tr>
            <td class="label">Umur</td>
            <td>{{ $prescription->patient_age_snapshot ?: $dash }}</td>
        </tr>
        <tr>
            <td class="label">Alergi Obat</td>
            <td>{{ $prescription->allergy_note ?: $dash }}</td>
        </tr>
        <tr>
            <td class="label">Hamil / Menyusui</td>
            <td>{{ $prescription->pregnant_or_breastfeeding ?: $dash }}</td>
        </tr>
        <tr>
            <td class="label">Gangguan Fungsi Ginjal</td>
            <td>{{ $prescription->renal_function_issue ?: $dash }}</td>
        </tr>
    </table>

    <div class="section-title">R/</div>
    @if ($rxUrl)
        <img src="{{ $rxUrl }}" alt="Resep dokter" class="rx-image">
    @else
        <p style="color:#6b7280;font-style:italic;">Belum ada gambar resep.</p>
    @endif

    <div class="signature-wrap">
        <div class="section-title" style="text-align:right;">Tanda Tangan Dokter</div>
        @if ($sigUrl)
            <img src="{{ $sigUrl }}" alt="Tanda tangan dokter" class="signature-image">
        @endif
        <p class="signature-caption">( drg. {{ $prescription->prescribed_by_name }} )</p>
    </div>

    <div class="footer">
        Resep #{{ $prescription->id }} &middot; Kunjungan {{ $clinicVisit->visit_number ?? '—' }}
        &middot; Dicetak {{ now()->format('d/m/Y H:i') }}
    </div>
</body>
</html>
