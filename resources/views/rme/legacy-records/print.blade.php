{{--
    LEGACY-RME-PDF-1D — printable view of a PUBLISHED legacy RME record.

    Deliberately visually distinct from a native RME print: a legacy archive is
    a historical document, and a reader holding the paper must never mistake it
    for an encounter produced by this system.

    Page images are REFERENCED through the policy-gated page route, never
    embedded and never as a storage path — the browser re-requests each one with
    the caller's own session. KTP/NIK is never rendered.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Arsip RME Lama — {{ $record->rme_date?->format('d-m-Y') ?? '-' }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 16px;
            background: #fff;
            color: #111827;
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 12px;
        }
        .legacy-banner {
            border: 2px solid #1D4ED8;
            background: #EFF4FF;
            padding: 10px 12px;
            margin-bottom: 14px;
        }
        .legacy-banner h1 { margin: 0 0 4px; font-size: 15px; color: #1E40AF; letter-spacing: .04em; }
        .legacy-banner p { margin: 0; font-size: 11px; color: #1F2937; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.meta th, table.meta td {
            border: 1px solid #CBD5E1;
            padding: 5px 8px;
            text-align: left;
            vertical-align: top;
            font-size: 11px;
        }
        table.meta th { background: #F1F5F9; width: 22%; font-weight: 600; }
        .void-notice {
            border: 2px solid #B91C1C;
            background: #FEF2F2;
            color: #7F1D1D;
            padding: 10px 12px;
            margin-bottom: 14px;
            font-weight: 600;
        }
        .page { page-break-inside: avoid; margin-bottom: 14px; }
        .page-label { font-size: 10px; color: #6B7280; margin-bottom: 4px; }
        .page img { max-width: 100%; border: 1px solid #CBD5E1; }
        .footer { margin-top: 18px; font-size: 10px; color: #6B7280; border-top: 1px solid #CBD5E1; padding-top: 8px; }
        .no-print { margin-bottom: 14px; }
        @media print { .no-print { display: none !important; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">Cetak</button>
        <a href="{{ route('rme.legacy-records.show', $record->getKey()) }}">Kembali ke arsip</a>
    </div>

    <div class="legacy-banner">
        <h1>ARSIP RME LAMA — DOKUMEN HISTORIS (HANYA BACA)</h1>
        <p>
            Dokumen ini adalah arsip rekam medis lama milik pasien yang diimpor dari berkas historis.
            Ini <strong>bukan</strong> kunjungan, tagihan, pembayaran, maupun order lab pada sistem ini.
        </p>
    </div>

    @if ($record->isVoided())
        <div class="void-notice">
            ARSIP DIBATALKAN (VOID) — tidak lagi menjadi bagian dari riwayat aktif pasien.
        </div>
    @endif

    <table class="meta">
        <tr>
            <th>Pasien</th>
            <td>{{ $record->patient?->name ?? '-' }}</td>
            <th>No. Rekam Medis</th>
            <td>{{ $record->patient?->medical_record_number ?? '-' }}</td>
        </tr>
        <tr>
            <th>Tanggal Dokumen</th>
            <td>{{ $record->rme_date?->format('d-m-Y') ?? '-' }}</td>
            <th>Status</th>
            <td>{{ $record->status }}</td>
        </tr>
        <tr>
            <th>Judul</th>
            <td>{{ $record->title ?? '-' }}</td>
            <th>Cabang Asal</th>
            <td>{{ $record->originBranch?->name ?? '-' }}</td>
        </tr>
        <tr>
            <th>Jumlah Halaman</th>
            <td>{{ $record->page_count }}</td>
            <th>Dipublikasikan</th>
            <td>{{ $record->published_at?->format('d-m-Y H:i') ?? '-' }}</td>
        </tr>
        @if ($record->description)
            <tr>
                <th>Keterangan</th>
                <td colspan="3">{{ $record->description }}</td>
            </tr>
        @endif
    </table>

    @forelse ($pages as $page)
        <div class="page">
            <div class="page-label">Halaman {{ $page->page_number }} dari {{ $record->page_count }}</div>
            <img
                src="{{ route('rme.legacy-records.pages.show', [$record->getKey(), $page->page_number]) }}"
                alt="Halaman {{ $page->page_number }}"
            >
        </div>
    @empty
        <p>Tidak ada halaman terender untuk arsip ini.</p>
    @endforelse

    <div class="footer">
        Arsip RME Lama · dicetak {{ now()->format('d-m-Y H:i') }} · dokumen historis, hanya bacaan.
    </div>
</body>
</html>