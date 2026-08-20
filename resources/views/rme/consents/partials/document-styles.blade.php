{{--
    Shared print-safe styles for the signed consent document. Table-based and
    dompdf-friendly: no flexbox, no grid, no custom properties.
--}}
<style>
    .consent-document {
        font-family: DejaVu Sans, Arial, sans-serif;
        font-size: 11px;
        line-height: 1.45;
        color: #111827;
    }

    .consent-title {
        text-align: center;
        font-size: 15px;
        font-weight: bold;
        text-decoration: underline;
        margin-bottom: 2px;
    }

    .consent-number {
        text-align: center;
        font-size: 11px;
        margin-bottom: 12px;
    }

    .consent-void-stamp {
        border: 2px solid #B91C1C;
        color: #B91C1C;
        font-weight: bold;
        text-align: center;
        padding: 6px;
        margin-bottom: 12px;
        font-size: 11px;
    }

    .consent-lead {
        margin: 10px 0 4px;
    }

    .consent-identity {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6px;
    }

    .consent-identity td {
        vertical-align: top;
        padding: 2px 0;
    }

    .consent-label {
        width: 150px;
    }

    .consent-sep {
        width: 12px;
    }

    .consent-value {
        border-bottom: 1px dotted #9CA3AF;
    }

    .consent-statement,
    .consent-relationship {
        margin: 8px 0 4px;
        text-align: justify;
    }

    .consent-inline-value {
        font-weight: bold;
        text-decoration: underline;
    }

    .consent-clauses {
        width: 100%;
        border-collapse: collapse;
        margin: 4px 0 10px;
    }

    .consent-clauses td {
        vertical-align: top;
        padding: 3px 0;
    }

    .consent-clause-number {
        width: 22px;
    }

    .consent-clause-text {
        text-align: justify;
    }

    .consent-documentation-answer {
        display: inline-block;
        margin-left: 4px;
        padding: 1px 6px;
        border: 1px solid #111827;
        font-weight: bold;
    }

    .consent-declaration {
        margin: 10px 0 16px;
    }

    .consent-signatures {
        width: 100%;
        border-collapse: collapse;
        page-break-inside: avoid;
    }

    .consent-signature-cell {
        width: 50%;
        text-align: center;
        vertical-align: top;
        padding: 2px 6px;
    }

    .consent-place-date {
        text-align: center;
        padding-bottom: 6px;
    }

    .consent-signature-heading {
        font-weight: bold;
        padding-bottom: 4px;
    }

    .consent-signature-box {
        height: 90px;
    }

    .consent-signature-image {
        max-height: 85px;
        max-width: 100%;
    }

    .consent-signature-name {
        border-top: 1px solid #111827;
        padding-top: 4px;
    }
</style>
