@php
    /** @var \App\Modules\Inventory\Models\PurchaseOrder $purchase_order */
    /** @var \App\Modules\Inventory\Models\Supplier $supplier */
    /** @var \Illuminate\Support\Collection $items */
    $currency = $purchase_order->currency ?? 'IDR';
    $approverName = $purchase_order->approvedBy?->name ?? $purchase_order->createdBy?->name;
@endphp
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Purchase Order {{ $purchase_order->purchase_order_number }} — {{ $supplier->name }}</title>
    <style>
        @page { margin: 26px; }
        body {
            color: #111827;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
        }
        h1, h2, h3, p { margin: 0; }
        .header {
            border-bottom: 2px solid #1D4ED8;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }
        .brand {
            color: #1D4ED8;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .brand-sub { color: #4b5563; font-size: 9px; margin-top: 2px; }
        .title { font-size: 18px; font-weight: 700; margin-top: 10px; }
        .doc-number { color: #1D4ED8; font-weight: 700; }
        table { border-collapse: collapse; width: 100%; }
        .meta-table td {
            border: 1px solid #d1d5db;
            padding: 5px 7px;
            vertical-align: top;
            width: 50%;
        }
        .label { color: #4b5563; font-size: 9px; font-weight: 700; text-transform: uppercase; }
        .value { font-size: 10px; font-weight: 600; margin-top: 2px; }
        .section-title { font-size: 12px; font-weight: 700; margin: 14px 0 6px; }
        .items-table th {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            padding: 6px 7px;
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            color: #374151;
        }
        .items-table td {
            border: 1px solid #d1d5db;
            padding: 6px 7px;
            vertical-align: top;
        }
        .num { text-align: right; }
        .total-row td { font-weight: 700; background: #eff4ff; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .signature-table { margin-top: 34px; }
        .signature-table td { width: 50%; vertical-align: top; padding: 4px 7px; }
        .sign-space { height: 54px; }
        .footer { margin-top: 18px; color: #6b7280; font-size: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">Klinik Gigi Daengtisia</div>
        <div class="brand-sub">{{ $purchase_order->branch?->name ?? 'Cabang' }}</div>
        <div class="title">Purchase Order</div>
        <div class="value">No. <span class="doc-number">{{ $purchase_order->purchase_order_number }}</span></div>
    </div>

    <table class="meta-table">
        <tr>
            <td>
                <div class="label">Ditujukan Kepada (Supplier)</div>
                <div class="value">{{ $supplier->name }}</div>
                @if ($supplier->address)
                    <div class="value" style="font-weight: 400;">{{ $supplier->address }}</div>
                @endif
                @if ($supplier->phone)
                    <div class="value" style="font-weight: 400;">Telp: {{ $supplier->phone }}</div>
                @endif
                @if ($supplier->email)
                    <div class="value" style="font-weight: 400;">Email: {{ $supplier->email }}</div>
                @endif
            </td>
            <td>
                <div class="label">Tanggal Pesanan</div>
                <div class="value">{{ format_date_id($purchase_order->order_date) }}</div>
                <div class="label" style="margin-top: 6px;">Pemesan / Cabang</div>
                <div class="value">{{ $purchase_order->branch?->name ?? '—' }}</div>
                @if ($purchase_order->supplier_reference_number)
                    <div class="label" style="margin-top: 6px;">Nomor Referensi</div>
                    <div class="value">{{ $purchase_order->supplier_reference_number }}</div>
                @endif
                <div class="label" style="margin-top: 6px;">Mata Uang</div>
                <div class="value">{{ $currency }}</div>
            </td>
        </tr>
    </table>

    <div class="section-title">Rincian Item untuk {{ $supplier->name }}</div>
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 26px;">No</th>
                <th>Produk</th>
                <th style="width: 70px;">Kode</th>
                <th class="num" style="width: 60px;">Jumlah</th>
                <th class="num" style="width: 80px;">Harga Satuan</th>
                <th class="num" style="width: 90px;">Subtotal</th>
                <th style="width: 80px;">Estimasi Datang</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $index => $item)
                <tr>
                    <td class="num">{{ $index + 1 }}</td>
                    <td>
                        {{ $item->product?->name ?? '—' }}
                        @if ($item->notes)
                            <div style="color: #6b7280; font-size: 9px;">{{ $item->notes }}</div>
                        @endif
                    </td>
                    <td>{{ $item->product?->code ?? '—' }}</td>
                    <td class="num">{{ format_quantity_id($item->quantity_ordered) }}</td>
                    <td class="num">{{ $item->unit_price !== null ? format_currency_id($item->unit_price) : '—' }}</td>
                    <td class="num">{{ format_currency_id($item->lineTotal()) }}</td>
                    <td>{{ $item->estimated_arrival_date ? format_date_id($item->estimated_arrival_date) : '—' }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="5" class="num">Total untuk {{ $supplier->name }} ({{ $currency }})</td>
                <td class="num">{{ format_currency_id($subtotal) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    @if ($purchase_order->notes)
        <div class="section-title">Catatan</div>
        <div class="value" style="font-weight: 400;">{{ $purchase_order->notes }}</div>
    @endif

    <table class="signature-table">
        <tr>
            <td>
                <div class="label">Dibuat / Disetujui oleh</div>
                <div class="sign-space"></div>
                <div class="value">{{ $approverName ?? '—' }}</div>
            </td>
            <td>
                <div class="label">Diterima oleh (Supplier)</div>
                <div class="sign-space"></div>
                <div class="value">( ................................ )</div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Dokumen ini hanya memuat item yang ditujukan kepada {{ $supplier->name }}.
        Dibuat pada {{ now()->format('d/m/Y H:i') }}.
    </div>
</body>
</html>
