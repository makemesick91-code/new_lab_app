{{--
    LEGACY-RME-PDF-1D — dompdf export of a PUBLISHED legacy RME record.

    dompdf-safe by construction: table-based layout only, no flexbox, no grid,
    no external stylesheet, no remote asset. Page images arrive already inlined
    as data URIs from the controller, so no filesystem path is ever rendered.

    Visually distinct from a native RME export on purpose — a legacy archive is
    a historical document and must never read as an encounter from this system.
    KTP/NIK is never rendered.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Arsip RME Lama</title>
    <style>
        @page { margin: 18px 16px; }
        body {
            margin: 0;
            background: #fff;
            color: #111827;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 11px;
        }
        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        .banner td {
            border: 2px solid #1D4ED8;
            background: #EFF4FF;
            padding: 8px 10px;
        }
        .banner .title {
            font-size: 13px;
            font-weight: bold;
            color: #1E40AF;
            letter-spacing: .04em;
        }
        .banner .sub { font-size: 10px; color: #1F2937; }
        .void td {
            border: 2px solid #B91C1C;
            background: #FEF2F2;
            color: #7F1D1D;
            padding: 8px 10px;
            font-weight: bold;
        }
        .meta { margin-top: 10px; }
        .meta th, .meta td {
            border: 1px solid #CBD5E1;
            padding: 4px 7px;
            text-align: left;
            font-size: 10px;
        }
        .meta th { background: #F1F5F9; width: 22%; font-weight: bold; }
        .spacer { height: 10px; }
        .page-label { font-size: 9px; color: #6B7280; padding-bottom: 3px; }
        .page-cell { page-break-inside: avoid; }
        .page-cell img { max-width: 100%; }
        .note td {
            border: 1px solid #F59E0B;
            background: #FFFBEB;
            color: #92400E;
            padding: 7px 9px;
            font-size: 10px;
        }
        .footer {
            margin-top: 12px;
            border-top: 1px solid #CBD5E1;
            padding-top: 6px;
            font-size: 9px;
            color: #6B7280;
        }
    </style>
</head>
<body>
    <table class="banner">
        <tr>
            <td>
                <div class="title">ARSIP RME LAMA — DOKUMEN HISTORIS (HANYA BACA)</div>
                <div class="sub">
                    Arsip rekam medis lama milik pasien yang diimpor dari berkas historis.
                    Bukan kunjungan, tagihan, pembayaran, maupun order lab pada sistem ini.
                </div>
            </td>
        </tr>
    </table>

    @if ($record->isVoided())
        <div class="spacer"></div>
        <table class="void">
            <tr>
                <td>ARSIP DIBATALKAN (VOID) — tidak lagi menjadi bagian dari riwayat aktif pasien.</td>
            </tr>
        </table>
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
            <td>{{ $totalPages }}</td>
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

    @if ($truncated)
        <div class="spacer"></div>
        <table class="note">
            <tr>
                <td>
                    Ekspor ini dipotong: hanya {{ count($pages) }} dari {{ $totalPages }} halaman disertakan.
                    Dokumen lengkap tersedia melalui berkas PDF arsip aslinya.
                </td>
            </tr>
        </table>
    @endif

    @foreach ($pages as $page)
        <div class="spacer"></div>
        <table>
            <tr>
                <td class="page-cell">
                    <div class="page-label">Halaman {{ $page['page_number'] }} dari {{ $totalPages }}</div>
                    <img src="{{ $page['data_uri'] }}" alt="Halaman {{ $page['page_number'] }}">
                </td>
            </tr>
        </table>
    @endforeach

    @if (count($pages) === 0)
        <div class="spacer"></div>
        <table class="note">
            <tr>
                <td>Tidak ada halaman terender yang dapat disertakan pada ekspor ini.</td>
            </tr>
        </table>
    @endif

    <div class="footer">
        Arsip RME Lama · diekspor {{ now()->format('d-m-Y H:i') }} · dokumen historis, hanya bacaan.
    </div>
</body>
</html>