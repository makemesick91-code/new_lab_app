{{--
    FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — browser print of a PUBLISHED
    legacy archive (RME or odontogram) for the oversight roles. Page images are
    REFERENCED through the oversight page route, which re-authorizes each one
    with the reader's own session — never embedded, never a storage path.
    KTP/NIK is never rendered.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} — {{ $documentDate?->format('d-m-Y') ?? '-' }}</title>
    @include('oversight.partials.print-styles')
    <style>
        .legacy-banner { border: 2px solid #1D4ED8; background: #EFF4FF; padding: 8px 10px; margin-bottom: 12px; }
        .legacy-banner h1 { font-size: 14px; color: #1E40AF; }
        .page { page-break-inside: avoid; margin-bottom: 12px; }
        .page img { max-width: 100%; border: 1px solid #CBD5E1; }
    </style>
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
        <a href="{{ $backUrl }}">&larr; Kembali</a>
    </div>

    <div class="legacy-banner">
        <h1>{{ $title }} — DOKUMEN HISTORIS (HANYA BACA)</h1>
        <p class="meta">Arsip lama yang diimpor dari berkas historis. Bukan kunjungan, tagihan, pembayaran, maupun order lab pada sistem ini.</p>
    </div>

    <table>
        <tr><th>Pasien</th><td>{{ $record->patient?->name ?? '-' }}</td><th>No. Rekam Medis</th><td>{{ $record->patient?->medical_record_number ?? '-' }}</td></tr>
        <tr><th>Tanggal Dokumen</th><td>{{ $documentDate?->format('d-m-Y') ?? '-' }}</td><th>Cabang</th><td>{{ $branchName ?? '-' }}</td></tr>
        <tr><th>Judul</th><td>{{ $record->title ?? '-' }}</td><th>Jumlah Halaman</th><td>{{ $record->page_count }}</td></tr>
    </table>

    @forelse ($pages as $page)
        <div class="page">
            <p class="meta">Halaman {{ $page->page_number }} dari {{ $record->page_count }}</p>
            <img src="{{ route($pageRoute, [$record->id, $page->page_number]) }}" alt="Halaman {{ $page->page_number }}">
        </div>
    @empty
        <p>Tidak ada halaman terender untuk arsip ini.</p>
    @endforelse

    <p class="footnote">{{ $title }} · dicetak {{ \App\Modules\ClinicOversight\Support\OversightFormat::clinicalTime(now(), 'd-m-Y H:i') }} WITA · dokumen historis, hanya bacaan.</p>
</body>
</html>
