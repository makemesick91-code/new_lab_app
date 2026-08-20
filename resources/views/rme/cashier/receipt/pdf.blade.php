{{--
    FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-06 — RME kwitansi as a single-page
    PDF.

    The one-page contract is achieved by REMOVING WASTE, never by hiding money:
    a declared A4 page box with tight margins, compact but readable type
    (9.5pt body, nothing below 8pt), and stacked card chrome replaced by rules.
    No overflow:hidden, no clipping, no display:none on financial data, and no
    item or payment row is ever dropped.

    Beyond the supported envelope the item table CONTINUES onto a second page
    with a repeating header (thead { display: table-header-group }) rather than
    losing rows. Losing a line of a financial document to satisfy a layout
    target would be the worse failure, so the contract is: one page for the
    real-world receipt, and never a truncated one.

    Table-based throughout (dompdf): no flexbox, no grid.
--}}
@php
    $paidAmount = (($allocatedToParent ?? 0) + ($allocatedToControl ?? 0)) > 0
        ? (($allocatedToParent ?? 0) + ($allocatedToControl ?? 0))
        : ($payment?->amount ?? $invoice->grand_total);

    $rupiah = fn ($value) => 'Rp '.number_format((float) $value, 0, ',', '.');
    $remaining = $invoice->remainingAmount();
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kwitansi {{ $invoice->invoice_number }}</title>
    <style>
        @page { size: A4 portrait; margin: 11mm 12mm 10mm 12mm; }

        html, body { margin: 0; padding: 0; background: #fff; }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 9.5pt;
            line-height: 1.35;
            color: #111827;
        }

        .rule { border: 0; border-top: 1px solid #D1D5DB; margin: 6px 0; }
        .rule-strong { border: 0; border-top: 2px solid #1D4ED8; margin: 6px 0; }

        .header { text-align: center; }
        .header .clinic { font-size: 13pt; font-weight: bold; color: #1E40AF; text-transform: uppercase; }
        .header .meta { font-size: 8.5pt; color: #4B5563; }
        .header .doc { margin-top: 3px; font-size: 10pt; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; color: #1D4ED8; }

        table { width: 100%; border-collapse: collapse; }

        .meta-table td { padding: 1.5px 0; vertical-align: top; font-size: 9pt; }
        .meta-table .k { color: #6B7280; width: 27%; }
        .meta-table .v { color: #111827; font-weight: bold; width: 23%; }

        .section-title {
            font-size: 8.5pt;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #6B7280;
            margin: 8px 0 3px;
        }

        .items th {
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #6B7280;
            border-bottom: 1.5px solid #9CA3AF;
            padding: 3px 2px;
            text-align: left;
        }
        .items td { padding: 3px 2px; border-bottom: 1px solid #E5E7EB; }
        .items .num { text-align: right; white-space: nowrap; }
        /* Continuation rather than truncation when a receipt is unusually long. */
        .items thead { display: table-header-group; }
        .items tr { page-break-inside: avoid; }

        .totals td { padding: 3px 2px; }
        .totals .label { text-align: right; color: #374151; }
        .totals .value { text-align: right; font-weight: bold; white-space: nowrap; }
        .totals .grand { font-size: 11pt; border-top: 2px solid #111827; }

        .pay-box { border: 1px solid #047857; background: #ECFDF5; padding: 5px 8px; }
        .pay-box .label { font-weight: bold; color: #047857; }
        .pay-box .value { text-align: right; font-size: 13pt; font-weight: bold; color: #047857; white-space: nowrap; }

        .due-box { border: 1px solid #B45309; background: #FFFBEB; padding: 4px 8px; }
        .due-box .label { font-weight: bold; color: #B45309; }
        .due-box .value { text-align: right; font-weight: bold; color: #B45309; white-space: nowrap; }

        .footer { margin-top: 10px; text-align: center; font-size: 8pt; color: #6B7280; }
        .stamp {
            display: inline-block;
            margin-top: 4px;
            border: 1.5px solid #6B7280;
            padding: 2px 22px;
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 4px;
            color: #374151;
        }

        /* Keep the money together on the page it belongs to. */
        .keep { page-break-inside: avoid; }
    </style>
</head>
<body>

    <div class="header">
        <div class="clinic">{{ $invoice->branch?->name ?? config('app.name') }}</div>
        @if ($invoice->branch?->address || $invoice->branch?->phone)
            <div class="meta">{{ $invoice->branch?->address }}@if ($invoice->branch?->address && $invoice->branch?->phone) &middot; @endif{{ $invoice->branch?->phone }}</div>
        @endif
        <div class="doc">Kwitansi Pembayaran RME</div>
    </div>

    <hr class="rule-strong">

    <table class="meta-table">
        <tr>
            <td class="k">No. Kwitansi</td>
            <td class="v">{{ $payment?->payment_number ?? '—' }}</td>
            <td class="k">No. Invoice</td>
            <td class="v">{{ $invoice->invoice_number }}</td>
        </tr>
        <tr>
            <td class="k">Tanggal Bayar</td>
            <td class="v">{{ $payment?->paid_at?->format('d/m/Y H:i') ?? '—' }}</td>
            <td class="k">Kasir</td>
            <td class="v">{{ $payment?->cashier?->name ?? $invoice->cashier?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">Nama Pasien</td>
            <td class="v">{{ $visit->patient?->name ?? '—' }}</td>
            <td class="k">No. Rekam Medis</td>
            <td class="v">{{ $visit->patient?->medical_record_number ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">No. Kunjungan</td>
            <td class="v">{{ $visit->visit_number ?? '—' }}</td>
            <td class="k">Tanggal Kunjungan</td>
            <td class="v">{{ $visit->visit_date?->format('d/m/Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">Dokter</td>
            <td class="v" colspan="3">{{ $visit->doctor?->name ?? '—' }}</td>
        </tr>
    </table>

    <div class="section-title">Rincian Tindakan</div>

    <table class="items">
        <thead>
            <tr>
                <th style="width:52%">Tindakan</th>
                <th style="width:8%" class="num">Qty</th>
                <th style="width:20%" class="num">Harga Satuan</th>
                <th style="width:20%" class="num">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ $item->qty }}</td>
                    <td class="num">{{ $rupiah($item->unit_price) }}</td>
                    <td class="num">{{ $rupiah($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals keep">
        @if ($invoice->discount_total > 0)
            <tr>
                <td class="label" style="width:80%">Diskon</td>
                <td class="value" style="width:20%">- {{ $rupiah($invoice->discount_total) }}</td>
            </tr>
        @endif
        <tr>
            <td class="label grand">Total Tagihan</td>
            <td class="value grand">{{ $rupiah($invoice->grand_total) }}</td>
        </tr>
    </table>

    <div class="keep">
        @if ($hasPaymentAllocation ?? false)
            <div class="section-title">Alokasi Pembayaran</div>
            <table class="totals">
                <tr>
                    <td class="label" style="width:80%">Dibayarkan ke tagihan sebelumnya</td>
                    <td class="value" style="width:20%">{{ $rupiah($allocatedToParent) }}</td>
                </tr>
                <tr>
                    <td class="label">Dibayarkan ke tagihan kunjungan ini</td>
                    <td class="value">{{ $rupiah($allocatedToControl) }}</td>
                </tr>
            </table>
        @endif

        <table class="totals">
            <tr>
                <td class="label" style="width:80%">Metode Pembayaran</td>
                <td class="value" style="width:20%">{{ $payment?->paymentMethod?->name ?? 'Tunai' }}</td>
            </tr>
            @if ($payment?->reference_number)
                <tr>
                    <td class="label">No. Referensi</td>
                    <td class="value">{{ $payment->reference_number }}</td>
                </tr>
            @endif
        </table>

        <table class="pay-box">
            <tr>
                <td class="label">Jumlah Dibayar</td>
                <td class="value">{{ $rupiah($paidAmount) }}</td>
            </tr>
        </table>

        @if ($remaining > 0)
            {{-- A remaining balance is real money owed. It is printed, never
                 omitted to make the receipt look settled. --}}
            <table class="due-box" style="margin-top:4px;">
                <tr>
                    <td class="label">Sisa Tagihan (Piutang)</td>
                    <td class="value">{{ $rupiah($remaining) }}</td>
                </tr>
            </table>
        @endif
    </div>

    <div class="footer keep">
        <div>Dicetak pada {{ now()->format('d/m/Y H:i') }}</div>
        <div class="stamp">{{ $remaining > 0 ? 'DIBAYAR SEBAGIAN' : 'LUNAS' }}</div>
    </div>

</body>
</html>
