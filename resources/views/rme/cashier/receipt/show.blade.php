<x-settings-shell title="Kwitansi Pembayaran RME">

    {{-- Print isolation: show only receipt-body when printing.

         FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2 / FIX-06 — the kwitansi is contracted
         to a single page, reached by removing waste and never by hiding money: a
         declared page box, tight margins and a compact layout. Nothing is
         clipped, no financial row is suppressed, and a genuinely long invoice
         continues onto another page rather than losing a line.

         REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1 — the printed
         document is now the clinic's landscape INVOICE form, rendered by the
         shared partial `rme.cashier.receipt.partials.invoice-document`. The PDF
         route renders the very same partial, so what the cashier previews here
         is what the PDF contains. The page box is therefore A4 LANDSCAPE (same
         paper, rotated).

         The measurable form of this contract is the PDF route
         (rme.cashier.receipt.pdf), whose page count is asserted in CI. --}}
    <style>
        @page { size: A4 landscape; margin: 8mm 10mm; }

        @media print {
            body * { visibility: hidden; }
            #receipt-body, #receipt-body * { visibility: visible; }
            #receipt-body {
                position: absolute;
                inset: 0;
                width: 100%;
                max-width: none;
                margin: 0;
                padding: 0;
                border: 0;
                box-shadow: none;
                background: #fff;
                /* The screen preview scrolls sideways on a narrow viewport; on
                   paper nothing may ever be clipped. */
                overflow: visible !important;
            }
            /* Keep the lab panel compact if it prints at all. */
            #receipt-body .print-lab-workflow { page-break-inside: avoid; }
        }
    </style>

    <div class="space-y-6">

        {{-- Page Header (screen only) --}}
        <x-ui.page-header
            title="Kwitansi Pembayaran RME"
            subtitle="Bukti pembayaran tagihan kunjungan pasien."
            class="print:hidden">
            <x-slot:breadcrumb>Rekam Medis Elektronik</x-slot:breadcrumb>
            <x-slot:actions>
                <x-ui.button variant="secondary" :href="route('rme.cashier.show', [$visit, $invoice])">&larr; Kembali ke Tagihan</x-ui.button>
                <x-ui.button variant="secondary" :href="route('rme.cashier.receipt.pdf', [$visit, $invoice])">Unduh PDF</x-ui.button>
                <x-ui.button variant="primary" onclick="window.print()">Cetak Kwitansi</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        {{-- Receipt Body — the printed invoice, previewed on a white sheet. --}}
        <div id="receipt-body" class="mx-auto max-w-6xl space-y-4 overflow-x-auto rounded-xl border border-hairline bg-white p-6 shadow-card print:space-y-2">
            @include('rme.cashier.receipt.partials.invoice-document', ['pdf' => false])

            {{-- Lab workflow (read-only visibility) --}}
            <x-rme.lab-workflow-panel
                :invoice="$invoice"
                :candidates="$labCaseCandidates"
                compact
                class="print-lab-workflow"
            />
        </div>
    </div>

</x-settings-shell>
