@php
    use App\Modules\Inventory\Models\StockTransfer;

    $statusLabels = [
        StockTransfer::STATUS_DRAFT => 'Draft',
        StockTransfer::STATUS_SUBMITTED => 'Diajukan',
        StockTransfer::STATUS_IN_TRANSIT => 'Dalam Perjalanan',
        StockTransfer::STATUS_RECEIVED => 'Diterima',
        StockTransfer::STATUS_COMPLETED => 'Diterima',
        StockTransfer::STATUS_CANCELLED => 'Dibatalkan',
    ];
@endphp

<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Checklist Pengiriman Barang Antar Lokasi</title>
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
            border-bottom: 2px solid #1D4ED8;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }
        .brand {
            color: #1D4ED8;
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
            background: #EFF4FF;
            border: 1px solid #BFD7FE;
            color: #1E40AF;
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
        <p class="brand">{{ strtoupper(config('app.name', 'Daengtisia Management System')) }}</p>
        <h1 class="title">Checklist Pengiriman Barang Antar Lokasi</h1>
        <p class="muted">Dokumen pemeriksaan fisik transfer stok antar lokasi dalam satu cabang.</p>
    </div>

    <table class="meta-table">
        <tr>
            <td>
                <div class="label">Nomor Transfer</div>
                <div class="value">{{ $stockTransfer->transfer_number }}</div>
            </td>
            <td>
                <div class="label">Status</div>
                <div class="value">{{ $statusLabels[$stockTransfer->status] ?? $stockTransfer->status }}</div>
            </td>
            <td>
                <div class="label">Tanggal Dibuat</div>
                <div class="value">{{ format_datetime_id($stockTransfer->created_at) }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Tanggal Transfer</div>
                <div class="value">{{ format_date_id($stockTransfer->transfer_date) }}</div>
            </td>
            <td>
                <div class="label">Tanggal Dikirim</div>
                <div class="value">{{ $stockTransfer->shipped_at ? format_datetime_id($stockTransfer->shipped_at) : '-' }}</div>
            </td>
            <td>
                <div class="label">Tanggal Diterima</div>
                <div class="value">{{ $stockTransfer->completed_at ? format_datetime_id($stockTransfer->completed_at) : '-' }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Cabang</div>
                <div class="value">{{ $stockTransfer->branch?->name ?? '-' }}</div>
            </td>
            <td>
                <div class="label">Lokasi Asal</div>
                <div class="value">{{ $stockTransfer->sourceInventoryLocation?->name ?? '-' }}</div>
            </td>
            <td>
                <div class="label">Lokasi Tujuan</div>
                <div class="value">{{ $stockTransfer->destinationInventoryLocation?->name ?? '-' }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">Dibuat Oleh</div>
                <div class="value">{{ $stockTransfer->createdBy?->name ?? '-' }}</div>
            </td>
            <td>
                <div class="label">Diminta Oleh</div>
                <div class="value">{{ $stockTransfer->requestedBy?->name ?? '-' }}</div>
            </td>
            <td>
                <div class="label">Dikirim Oleh / Diterima Oleh</div>
                <div class="value">{{ $stockTransfer->shippedBy?->name ?? '-' }} / {{ $stockTransfer->approvedBy?->name ?? '-' }}</div>
            </td>
        </tr>
    </table>

    <h2 class="section-title">Tabel Item</h2>
    <table class="items-table">
        <thead>
            <tr>
                <th class="center" style="width: 22px;">No</th>
                <th style="width: 62px;">Kode Produk/SKU</th>
                <th>Nama Produk</th>
                <th style="width: 45px;">Unit</th>
                <th class="right" style="width: 55px;">Qty Dikirim</th>
                <th style="width: 65px;">Batch/Lot</th>
                <th style="width: 55px;">Expiry</th>
                <th class="center" style="width: 48px;">Checklist Diterima</th>
                <th style="width: 58px;">Qty Diterima Manual</th>
                <th style="width: 58px;">Kondisi Barang</th>
                <th style="width: 70px;">Catatan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($stockTransfer->items as $item)
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>{{ $item->product?->code ?? '-' }}</td>
                    <td>{{ $item->product?->name ?? '-' }}</td>
                    <td>{{ $item->product?->unit?->symbol ?? $item->product?->unit?->name ?? '-' }}</td>
                    <td class="right">{{ format_quantity_id($item->quantity) }}</td>
                    <td>
                        @if ($item->inventoryBatch)
                            {{ $item->inventoryBatch->batch_number }}
                            @if ($item->inventoryBatch->lot_number)
                                / {{ $item->inventoryBatch->lot_number }}
                            @endif
                        @else
                            -
                        @endif
                    </td>
                    <td>{{ $item->inventoryBatch?->expiry_date ? format_date_id($item->inventoryBatch->expiry_date) : '-' }}</td>
                    <td class="center"><span class="check-box"></span></td>
                    <td class="blank-cell"></td>
                    <td class="blank-cell"></td>
                    <td class="blank-cell"></td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="center muted">Tidak ada item transfer.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer-note">
        Checklist ini hanya untuk pemeriksaan fisik. Stok cabang/lokasi bertambah di sistem hanya setelah proses receive berhasil diposting.
    </div>

    <table class="signature-table">
        <tr>
            <td><span class="signature-label">Pengirim</span></td>
            <td><span class="signature-label">Penerima</span></td>
            <td><span class="signature-label">Pemeriksa</span></td>
            <td><span class="signature-label">Tanggal Pemeriksaan</span></td>
        </tr>
    </table>
</body>
</html>
