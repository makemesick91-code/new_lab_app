{{--
    REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1 — the RME payment invoice,
    redesigned after the clinic's own printed "INVOICE" form (owner-supplied
    reference): Daengtisia wordmark top-left, NOMOR / TANGGAL PEMBAYARAN top
    centre, a turquoise INVOICE block top-right, "Ditujukan Kepada" beside a
    yellow-headed treatment table, a payment summary, refund terms, bank details
    and the admin acknowledgement.

    ONE template, two renderers: the on-screen/browser-print receipt and the
    dompdf PDF both include this partial, so they cannot drift apart. That is
    why it is table-based throughout (dompdf has no flexbox or grid) and why its
    styles are scoped under `.dmsinv`.

    Contract (unchanged from FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-06):
      - one page for a real-world invoice, reached by layout, never by hiding
        money: nothing is clipped, scrolled away or suppressed — every value is
        always rendered;
      - a longer invoice CONTINUES onto another page with a repeating table
        header rather than losing a row;
      - every amount comes from $document (RmeInvoiceDocument), which reads
        stored canonical fields — nothing here computes money;
      - branch identity is the INVOICE's branch (the transaction), never the
        cashier's current context, and a missing address/phone prints nothing
        rather than a guess;
      - refund terms and bank details come from config/rme_invoice.php
        (owner-approved, source-controlled); an empty block prints nothing.

    Expects: $visit, $invoice, $payment, $document, $invoiceTerms,
             $hasPaymentAllocation, $allocatedToParent, $allocatedToControl,
             $pdf (bool — embed the logo for dompdf).
--}}
@php
    $pdf = (bool) ($pdf ?? false);
    $terms = is_array($invoiceTerms ?? null) ? $invoiceTerms : [];
    $refund = $terms['refund_terms'] ?? [];
    $refundItems = array_values(array_filter($refund['items'] ?? [], fn ($i) => trim((string) ($i['text'] ?? '')) !== ''));
    $bank = $terms['bank_transfer'] ?? [];
    $hasBank = trim((string) ($bank['account_number'] ?? '')) !== '';
    $closing = $terms['closing'] ?? [];
    $branch = $invoice->branch;
    $patient = $visit->patient;
    $patientPhone = $patient?->phone ?: ($patient?->whatsapp_number ?: null);
    $cashierName = $payment?->cashier?->name ?? $invoice->cashier?->name;
    $rp = fn (int $cents) => \App\Modules\RmeInvoice\Support\RmeInvoiceDocument::rupiah($cents);
    $controlLabel = $visit->isControlVisit() ? 'Dibayarkan ke tagihan kontrol' : 'Dibayarkan ke tagihan kunjungan ini';
@endphp

<div class="dmsinv">
    <style>
        .dmsinv { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; font-size: 8.8pt; line-height: 1.32; color: #1F2937; background: #FFFFFF; }
        .dmsinv table { border-collapse: collapse; width: 100%; }
        .dmsinv td, .dmsinv th { vertical-align: top; padding: 0; text-align: left; }

        /* ---- header ---- */
        .dmsinv .head td { vertical-align: top; }
        .dmsinv .logo { height: 19mm; width: auto; display: block; }
        .dmsinv .branch-name { margin-top: 1.5mm; font-size: 10.5pt; font-weight: bold; color: #D6246E; text-transform: uppercase; letter-spacing: 0.4px; }
        .dmsinv .branch-meta { font-size: 7.6pt; color: #374151; }
        .dmsinv .meta td { padding: 0.6mm 1.5mm 0.6mm 0; font-size: 8pt; }
        .dmsinv .meta .k { font-weight: bold; color: #0F2540; text-transform: uppercase; white-space: nowrap; width: 31mm; }
        .dmsinv .meta .nomor { white-space: nowrap; font-size: 11pt; font-weight: bold; color: #D6246E; letter-spacing: 1px; }
        .dmsinv .title-box { background: #38B8B8; border-radius: 6mm 0 0 6mm; padding: 4mm 5mm 4mm 7mm; text-align: center; }
        .dmsinv .title { font-size: 26pt; font-weight: bold; letter-spacing: 7px; color: #0F2540; }
        .dmsinv .title-sub { font-size: 7.4pt; letter-spacing: 1.5px; color: #FFFFFF; text-transform: uppercase; margin-top: 0.5mm; }

        .dmsinv .rule { border: 0; border-top: 0.6mm solid #38B8B8; margin: 2.2mm 0 2.2mm 0; height: 0; }

        /* ---- addressee ---- */
        .dmsinv .body-block { position: relative; }
        .dmsinv .to-block { position: absolute; top: 0; left: 0; width: 31%; }
        /* Reserves the addressee's height when the invoice is short, so the
           out-of-flow block can never run into the footer. */
        .dmsinv .items-block { margin-left: 33%; min-height: 52mm; }
        .dmsinv .to-heading { font-size: 9.5pt; font-weight: bold; color: #0F2540; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 1.5mm; }
        .dmsinv .to td { padding: 0.9mm 1.5mm 0.9mm 0; }
        .dmsinv .to .k { width: 31mm; padding-right: 2.5mm; color: #374151; font-weight: bold; white-space: nowrap; }
        .dmsinv .to .v { color: #111827; }

        /* ---- treatment table: the yellow rounded header of the form ---- */
        .dmsinv .items th { background: #F8D828; color: #0F2540; font-weight: bold; font-size: 9pt; padding: 1.8mm 2mm; }
        .dmsinv .items th.first { border-radius: 4mm 0 0 4mm; padding-left: 4mm; }
        .dmsinv .items th.last { border-radius: 0 4mm 4mm 0; padding-right: 4mm; }
        .dmsinv .items td { padding: 1.05mm 2mm; border-bottom: 0.25mm solid #CBD5E1; }
        .dmsinv .items td.first { padding-left: 4mm; }
        .dmsinv .items td.last { padding-right: 4mm; }
        .dmsinv .items .num { text-align: right; white-space: nowrap; }
        .dmsinv .items .unit { font-size: 7.4pt; color: #6B7280; }
        /* Continuation, not truncation, for an unusually long invoice. */
        .dmsinv .items thead { display: table-header-group; }
        .dmsinv .items tr { page-break-inside: avoid; }

        /* ---- payment + summary ---- */
        .dmsinv .keep { page-break-inside: avoid; }
        .dmsinv .label-caps { font-size: 8.4pt; font-weight: bold; color: #0F2540; text-transform: uppercase; letter-spacing: 0.4px; }
        .dmsinv .methods { margin-top: 1mm; font-size: 9pt; color: #111827; }
        .dmsinv .alloc { margin-top: 2.5mm; border-left: 0.8mm solid #38B8B8; padding: 1mm 0 1mm 2.5mm; }
        .dmsinv .alloc td { padding: 0.5mm 0; font-size: 8pt; }
        .dmsinv .alloc .num { text-align: right; white-space: nowrap; font-weight: bold; }
        .dmsinv .sums td { padding: 0.6mm 0; }
        .dmsinv .sums .k { color: #374151; }
        .dmsinv .sums .num { text-align: right; white-space: nowrap; font-weight: bold; color: #111827; }
        .dmsinv .sums .total td { border-top: 0.3mm solid #0F2540; padding-top: 1mm; }
        .dmsinv .sums .total .k { font-weight: bold; color: #0F2540; }
        .dmsinv .sums .owed .k, .dmsinv .sums .owed .num { color: #B91C1C; }
        .dmsinv .stamp { display: inline-block; margin-top: 2.5mm; border: 0.5mm solid #38B8B8; color: #1F8A8A; border-radius: 1.5mm; padding: 1mm 4mm; font-weight: bold; letter-spacing: 3px; font-size: 10pt; }
        .dmsinv .stamp.partial { border-color: #B91C1C; color: #B91C1C; }
        .dmsinv .warn { margin-top: 2mm; border: 0.4mm solid #B91C1C; background: #FEF2F2; color: #991B1B; padding: 1.5mm 2.5mm; font-size: 8pt; font-weight: bold; }

        /* ---- footer ---- */
        .dmsinv .foot-heading { font-size: 9pt; font-weight: bold; color: #0F2540; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 1mm; }
        .dmsinv .refund td { padding: 0.5mm 0; font-size: 7.6pt; color: #374151; }
        .dmsinv .refund .dot { width: 3mm; color: #38B8B8; font-weight: bold; }
        .dmsinv .refund .hl { color: #D6246E; font-weight: bold; }
        .dmsinv .thanks { font-size: 11pt; font-weight: bold; color: #0F2540; line-height: 1.35; }
        .dmsinv .bank { margin-top: 1.8mm; background: #E6F6F6; border-radius: 2mm; padding: 2mm 3mm; font-size: 8pt; color: #1F2937; }
        .dmsinv .bank .foot-heading { margin-bottom: 0.6mm; }
        .dmsinv .sign { text-align: center; }
        .dmsinv .sign .role { font-size: 9.5pt; font-weight: bold; color: #0F2540; }
        .dmsinv .sign .space { height: 12mm; }
        .dmsinv .sign .line { border-top: 0.3mm solid #0F2540; padding-top: 1mm; font-size: 8.4pt; color: #111827; }
        .dmsinv .printed { margin-top: 1.2mm; font-size: 7pt; color: #6B7280; text-align: right; }
    </style>

    {{-- ============================== HEADER ============================== --}}
    <table class="head">
        <tr>
            <td style="width: 40%;">
                <x-brand.daengtisia-logo variant="wordmark" :pdf="$pdf" class="logo" />
                <div class="branch-name">{{ $branch?->name ?? config('app.name') }}</div>
                @if ($branch?->address)
                    <div class="branch-meta">{{ $branch->address }}</div>
                @endif
                @if ($branch?->phone)
                    <div class="branch-meta">Telp/WA: {{ $branch->phone }}</div>
                @endif
            </td>
            <td style="width: 31%; padding-left: 3mm;">
                <table class="meta">
                    <tr>
                        <td class="k">Nomor</td>
                        <td class="nomor">{{ $invoice->invoice_number }}</td>
                    </tr>
                    <tr>
                        <td class="k">Tanggal Pembayaran</td>
                        <td>{{ $paidAtClinical?->format('d/m/Y') ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="k">No. Kwitansi</td>
                        <td>{{ $payment?->payment_number ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="k">No. Kunjungan</td>
                        <td>{{ $visit->visit_number }}</td>
                    </tr>
                    {{-- FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — a corrected
                         document says so. Rendered only when a correction has been
                         APPLIED (correction_version > 0); for every other invoice this
                         partial prints exactly what it printed before. The original is
                         frozen as document version v0 and is never re-rendered from
                         live rows. --}}
                    @if ($invoice->isRevised())
                        <tr>
                            <td class="k">Versi Dokumen</td>
                            <td data-invoice-document-revision>Revisi ke-{{ (int) $invoice->correction_version }} (asli tersimpan v0)</td>
                        </tr>
                    @endif
                </table>
            </td>
            <td style="width: 29%;">
                <div class="title-box">
                    <div class="title">{{ $terms['document_title'] ?? 'INVOICE' }}</div>
                    <div class="title-sub">Klinik Gigi Daengtisia</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    {{-- =========================== ADDRESSEE + ITEMS ===========================
         The treatment table is a TOP-LEVEL block indented beside the addressee
         — deliberately not a nested table inside a two-cell row: dompdf cannot
         split a row that holds a nested table, so the nested form pushed a long
         invoice off page 1 and stranded a near-blank last page. The addressee
         is positioned (out of flow) rather than floated, because a dompdf float
         "intrudes" into the rows of the NEXT page at the same vertical offset.
         As a top-level table it breaks between rows, repeats its header, and
         keeps its column position on every page. --}}
    <div class="body-block">
    <div class="to-block">
                <div class="to-heading">Ditujukan Kepada</div>
                <table class="to">
                    <tr><td class="k">No. RM</td><td class="v">{{ $patient?->medical_record_number ?? '—' }}</td></tr>
                    <tr><td class="k">Nama</td><td class="v">{{ $patient?->name ?? '—' }}</td></tr>
                    <tr><td class="k">No. HP</td><td class="v">{{ $patientPhone ?? '—' }}</td></tr>
                    <tr><td class="k">Dokter Pemeriksa</td><td class="v">{{ $visit->doctor?->name ?? '—' }}</td></tr>
                </table>
    </div>
    <div class="items-block">
                <table class="items">
                    <thead>
                        <tr>
                            <th class="first" style="width: 55%;">Jenis Perawatan</th>
                            <th class="num" style="width: 9%;">Qty</th>
                            <th class="num" style="width: 16%;">Diskon</th>
                            <th class="num last" style="width: 20%;">Biaya</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($document->lines as $line)
                            <tr>
                                <td class="first">
                                    {{ $line['description'] }}
                                    @if ($line['qty'] > 1)
                                        <div class="unit">{{ $line['qty'] }} &times; {{ $rp($line['unit_price_cents']) }}</div>
                                    @endif
                                </td>
                                <td class="num">{{ $line['qty'] }}</td>
                                <td class="num">{{ $line['discount_cents'] > 0 ? $rp($line['discount_cents']) : '—' }}</td>
                                <td class="num last">{{ $rp($line['gross_cents']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <table class="keep" style="margin-top: 3mm;">
                    <tr>
                        <td style="width: 52%; padding-right: 5mm;">
                            <div class="label-caps">Jenis Pembayaran</div>
                            <div class="methods">{{ $document->paymentMethods === [] ? '—' : implode(' / ', $document->paymentMethods) }}</div>
                            @if ($payment?->reference_number)
                                <div class="methods" style="font-size: 8pt;">No. Referensi: {{ $payment->reference_number }}</div>
                            @endif
                            {{-- A remaining balance is real money owed: it is printed and
                                 stamped as such, never stamped LUNAS. --}}
                            <div><span class="stamp {{ $document->isSettled() ? '' : 'partial' }}">{{ $document->isSettled() ? 'LUNAS' : 'DIBAYAR SEBAGIAN' }}</span></div>

                            @if ($hasPaymentAllocation ?? false)
                                <div class="alloc">
                                    <div class="label-caps">Alokasi Pembayaran</div>
                                    <table>
                                        <tr><td>Dibayarkan ke tagihan sebelumnya</td><td class="num">{{ $rp(\App\Modules\RmeInvoice\Support\RmeInvoiceDocument::toCents($allocatedToParent)) }}</td></tr>
                                        <tr><td>{{ $controlLabel }}</td><td class="num">{{ $rp(\App\Modules\RmeInvoice\Support\RmeInvoiceDocument::toCents($allocatedToControl)) }}</td></tr>
                                        <tr><td>Jumlah diterima pada transaksi ini</td><td class="num">{{ $rp(\App\Modules\RmeInvoice\Support\RmeInvoiceDocument::toCents($allocatedToParent) + \App\Modules\RmeInvoice\Support\RmeInvoiceDocument::toCents($allocatedToControl)) }}</td></tr>
                                    </table>
                                </div>
                            @endif
                        </td>
                        <td style="width: 48%;">
                            <table class="sums">
                                <tr><td class="k">Jumlah</td><td class="num">{{ $rp($document->grossCents) }}</td></tr>
                                <tr><td class="k">Diskon</td><td class="num">{{ $document->discountCents > 0 ? '- '.$rp($document->discountCents) : $rp(0) }}</td></tr>
                                <tr class="total"><td class="k">Total Biaya</td><td class="num">{{ $rp($document->totalCents) }}</td></tr>
                                <tr><td class="k">Pembayaran</td><td class="num">{{ $rp($document->paidCents) }}</td></tr>
                                <tr class="{{ $document->remainingCents > 0 ? 'owed' : '' }}"><td class="k">Sisa Pembayaran</td><td class="num">{{ $rp($document->remainingCents) }}</td></tr>
                            </table>
                            @unless ($document->reconciles())
                                <div class="warn">Perhatian: rincian tindakan tidak sesuai dengan total invoice yang tersimpan. Jangan serahkan dokumen ini — hubungi admin.</div>
                            @endunless
                        </td>
                    </tr>
                </table>
    </div>
    </div>

    <div class="rule"></div>

    {{-- ============================== FOOTER ============================== --}}
    <table class="keep">
        <tr>
            <td style="width: 41%; padding-right: 5mm;">
                @if ($refundItems !== [])
                    <div class="foot-heading">{{ $refund['heading'] ?? 'Ketentuan Refund' }}</div>
                    <table class="refund">
                        @foreach ($refundItems as $item)
                            <tr>
                                <td class="dot">&bull;</td>
                                <td>@if (! empty($item['label']))<span class="hl">{{ $item['label'] }}</span>: @endif{{ $item['text'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
            </td>
            <td style="width: 35%; padding-right: 5mm;">
                @if ($closing !== [])
                    <div class="thanks">
                        @foreach ($closing as $closingLine)
                            {{ $closingLine }}@unless ($loop->last)<br>@endunless
                        @endforeach
                    </div>
                @endif
                @if ($hasBank)
                    <div class="bank">
                        <div class="foot-heading">{{ $bank['heading'] ?? 'Kirim ke Rekening' }}</div>
                        <div>Nomor Rekening: {{ $bank['account_number'] }}@if (! empty($bank['bank_name'])) ({{ $bank['bank_name'] }})@endif</div>
                        @if (! empty($bank['account_name']))
                            <div>Nama Rekening: {{ $bank['account_name'] }}</div>
                        @endif
                    </div>
                @endif
            </td>
            <td style="width: 24%;" class="sign">
                <div class="role">Admin</div>
                <div class="space"></div>
                <div class="line">{{ $cashierName ?? '—' }}</div>
            </td>
        </tr>
    </table>

    <div class="printed">Dicetak pada {{ $printedAtClinical->format('d/m/Y H:i') }} WITA</div>
</div>
