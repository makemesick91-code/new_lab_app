{{-- FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1 — shared browser print of an
     oversight monitoring list. Rows are already reduced to displayable cells;
     KTP/NIK and clinical free text are never part of them. --}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} — {{ $date }}</title>
    @include('oversight.partials.print-styles')
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
        <a href="javascript:history.back()">&larr; Kembali</a>
    </div>

    <h1>{{ $title }}</h1>
    <p class="meta">Tanggal klinik: <strong>{{ $date }}</strong> (WITA) · {{ $subtitle }} · dokumen hanya baca · {{ count($rows) }} baris</p>

    <table>
        <thead>
            <tr>
                @foreach ($header as $h)
                    <th>{{ $h }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($header) }}">Tidak ada data.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
