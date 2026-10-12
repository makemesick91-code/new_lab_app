{{-- Shared print styles of the oversight print pages (browser print → PDF). --}}
<style>
    * { box-sizing: border-box; }
    body { margin: 0; padding: 18px; background: #fff; color: #111827; font-family: "DejaVu Sans", Arial, sans-serif; font-size: 11px; }
    h1 { margin: 0 0 4px; font-size: 16px; color: #0F2747; }
    .meta { margin: 0 0 10px; font-size: 10px; color: #4B5563; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th, td { border: 1px solid #CBD5E1; padding: 4px 6px; text-align: left; vertical-align: top; }
    th { background: #F1F5F9; font-weight: 600; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    .num { text-align: right; white-space: nowrap; }
    tfoot td { font-weight: 700; background: #F8FAFC; }
    .footnote { font-size: 9px; color: #6B7280; }
    .no-print { margin-bottom: 12px; }
    .no-print a, .no-print button { margin-right: 8px; }
    @media print { .no-print { display: none !important; } body { padding: 0; } }
</style>
