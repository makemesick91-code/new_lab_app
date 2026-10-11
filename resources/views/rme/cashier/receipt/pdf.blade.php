{{--
    RME invoice as a PDF (dompdf).

    REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1 — this file is now only
    the page box. The document itself is the shared partial
    `rme.cashier.receipt.partials.invoice-document`, which the on-screen receipt
    includes too, so the browser print and the PDF cannot drift apart.

    Page: A4 LANDSCAPE, matching the clinic's printed form (same paper as
    before, rotated). The one-page contract of FIX-RME-CONSENT-WORKFLOW-PRINT-UX-2
    / FIX-06 still holds and is still asserted against real renderer output in
    CI (RmeReceiptOnePageTest): one page for a real-world invoice, and a longer
    one continues onto another page rather than losing a row.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @page { size: A4 landscape; margin: 8mm 10mm 8mm 10mm; }
        html, body { margin: 0; padding: 0; background: #fff; }
    </style>
</head>
<body>
    @include('rme.cashier.receipt.partials.invoice-document', ['pdf' => true])
</body>
</html>
