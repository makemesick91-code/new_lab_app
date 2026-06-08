@php
    /** @var \Illuminate\Support\Collection $rows */
    /** @var array $filters */
    /** @var array $filterOptions */

    $locationName = null;
    if (! empty($filters['inventory_location_id'])) {
        $locationName = optional(
            collect($filterOptions['locations'] ?? [])->firstWhere('id', (int) $filters['inventory_location_id'])
        )->name;
    }

    $productName = null;
    if (! empty($filters['product_id'])) {
        $productName = optional(
            collect($filterOptions['products'] ?? [])->firstWhere('id', (int) $filters['product_id'])
        )->name;
    }

    $categoryName = null;
    if (! empty($filters['category_id'])) {
        $categoryName = optional(
            collect($filterOptions['categories'] ?? [])->firstWhere('id', (int) $filters['category_id'])
        )->name;
    }
@endphp

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Checklist Refill Stok Ruangan</title>
    <style>
        @page { margin: 24px; }
        body {
            color: #111827;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.35;
        }
        h1, h2, h3, p { margin: 0; }
        .header {
            border-bottom: 2px solid #0f766e;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }
        .brand {
            color: #0f766e;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .title {
            font-size: 18px;
            font-weight: 700;
            margin-top: 4px;
        }
        .meta-table, .items-table, .signature-table {
            border-collapse: collapse;
            width: 100%;
        }
        .meta-table td {
            border: 1px solid #d1d5db;
            padding: 5px 7px;
            vertical-align: top;
        }
        .label {
            color: #4b5563;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .value {
            font-size: 10px;
            font-weight: 600;
            margin-top: 2px;
        }
        .section-title {
            font-size: 12px;
            font-weight: 700;
            margin: 14px 0 6px;
        }
        .items-table th {
            background: #f3f4f6;
            border: 1px solid #9ca3af;
            color: #374151;
            font-size: 8px;
            font-weight: 700;
            padding: 5px 4px;
            text-align: left;
            vertical-align: middle;
        }
        .items-table td {
            border: 1px solid #d1d5db;
            padding: 6px 4px;
            vertical-align: top;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .muted { color: #6b7280; }
        .blank-cell { height: 22px; }
        .check-box {
            border: 1px solid #111827;
            display: inline-block;
            height: 11px;
            width: 11px;
        }
        .footer-note {
            background: #ecfdf5;
            border: 1px solid #99f6e4;
            color: #134e4a;
            margin-top: 14px;
            padding: 8px;
        }
        .signature-table {
            margin-top: 18px;
        }
        .signature-table td {
            border: 1px solid #d1d5db;
            height: 58px;
            padding: 7px;
            vertical-align: top;
        }
        .signature-label {
            color: #374151;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <div class="header">
        <p class="brand">Asia Dental Lab</p>
        <h1 class="title">Checklist Refill Stok Ruangan</h1>
        <p class="muted">Dokumen perencanaan operasional untuk item ruangan di bawah minimum. Tidak mengubah stok atau membuat pergerakan inventory.</p>
    </div>

    <table class="meta-table">
        <tr>
            <td>
                <div class="label">Cabang</div>
                <div class="value">{{ $branch?->name ?? '-' }}</div>
            </td>
            <td>
                <div class="label">Ruangan / Lokasi</div>
                <div class="value">{{ $locationName ?? 'Semua Ruangan' }}</div>
            </td>
            <td>
                <div class="label">Kategori</div>
                <div class="value">{{ $categoryName ?? 'Semua Kategori' }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Produk</div>
                <div class="value">{{ $productName ?? 'Semua Produk' }}</div>
            </td>
            <td>
                <div class="label">Dicetak Pada</div>
                <div class="value">{{ format_datetime_id($printedAt) }}</div>
            </td>
            <td>
                <div class="label">Dicetak Oleh</div>
                <div class="value">{{ $printedBy?->name ?? '-' }}</div>
            </td>
        </tr>
    </table>

    <h2 class="section-title">Item Perlu Refill ({{ $rows->count() }})</h2>
    <table class="items-table">
        <thead>
            <tr>
                <th class="center" style="width: 20px;">No</th>
                <th style="width: 80px;">Ruangan / Lokasi</th>
                <th>Produk</th>
                <th style="width: 58px;">Kode Produk</th>
                <th class="right" style="width: 44px;">Stok Saat Ini</th>
                <th class="right" style="width: 44px;">Min. Ruangan</th>
                <th class="right" style="width: 44px;">Maks. Ruangan</th>
                <th class="right" style="width: 44px;">Saran Refill</th>
                <th style="width: 90px;">Rekomendasi / Sumber Refill</th>
                <th class="center" style="width: 30px;">Ambil</th>
                <th style="width: 52px;">Jumlah Diambil / Diisi</th>
                <th style="width: 70px;">Catatan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>{{ $row->inventory_location_name }}</td>
                    <td>{{ $row->product_name }}</td>
                    <td>{{ $row->product_code }}</td>
                    <td class="right">{{ format_quantity_id($row->current_stock) }}</td>
                    <td class="right">{{ format_quantity_id($row->minimum_stock) }}</td>
                    <td class="right">{{ $row->maximum_stock === null ? '-' : format_quantity_id($row->maximum_stock) }}</td>
                    <td class="right">{{ format_quantity_id($row->suggested_refill_qty) }}</td>
                    <td>{{ $row->recommendation }}</td>
                    <td class="center"><span class="check-box"></span></td>
                    <td class="blank-cell"></td>
                    <td class="blank-cell"></td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="center muted">Tidak ada item ruangan yang berada di bawah minimum untuk filter yang dipilih.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer-note">
        Checklist ini hanya dokumen perencanaan refill. Stok ruangan baru berubah di sistem setelah transfer/penerimaan stok diposting melalui modul terkait.
    </div>

    <table class="signature-table">
        <tr>
            <td><span class="signature-label">Petugas Gudang</span></td>
            <td><span class="signature-label">Penanggung Jawab Ruangan</span></td>
            <td><span class="signature-label">Verifikasi Admin</span></td>
        </tr>
    </table>
</body>
</html>
