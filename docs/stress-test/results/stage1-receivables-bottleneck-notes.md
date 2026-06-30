# Stage 1 Receivables Bottleneck Notes

Dataset:
- mst_patients: 50,000
- trx_clinic_visits: 150,000
- trx_rme_invoices: 150,000
- active receivable candidates: 45,000 invoices (UNPAID + PARTIAL)

Smoke result:
- /rme/cashier/receivables: 12.897s
- Other RME pages: mostly <0.4s

Initial finding:
- Direct SQL query with LIMIT 25 is very fast (~0.303 ms).
- Bottleneck likely comes from RmeInvoiceController@receivables loading all active receivable invoices into PHP:
  $summaryInvoices = (clone $query)->get();

Risk:
- Summary and aging are calculated from full Eloquent collection before pagination.
- With tens/hundreds of thousands of receivables, this will grow linearly and can exceed 1s target.

Recommended fix direction:
- Keep paginated list query limited to current page.
- Move summary and aging calculations to SQL aggregate queries.
- Avoid loading all invoice models/payments just to calculate summary.

## Sprint 67.1 Patch Result

Patch:
- Removed full Eloquent collection load for receivable summary/aging.
- Summary and aging now use SQL aggregate queries.
- Paginated list remains unchanged.

Result:
- Before patch: /rme/cashier/receivables = 12.897411s
- After patch:  /rme/cashier/receivables = 1.650158s

Status:
- Major improvement achieved.
- Still above <1s target.
- Next focus: payment lookup index, invoice active receivable ordering index, paginator/count query.

## Migration Verification

Migration:
- 2026_06_30_153729_add_rme_receivables_performance_indexes
- Status: DONE on stress database

Full smoke after migration:
- rme_dashboard: 0.2017s
- rme_visits: 0.1048s
- rme_cashier: 0.1006s
- rme_receivables: 0.6278s
- rme_reports_patients: 0.1387s
- rme_reports_payments: 0.1771s

Final result:
- RME main pages are below 1 second on Stage 1 stress dataset.
- Receivables bottleneck reduced from 12.8974s to 0.6278s.
