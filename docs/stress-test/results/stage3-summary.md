# Stage 3 Local Stress Test Summary

Scope: local only, no VPS deploy.

Dataset target:
- Patients: 250,000
- RME visits / medical records / invoices: 1,000,000
- Invoice items: 3,000,000
- Payments / lab candidates: 900,000
- Follow-ups: 200,000

Status:
- Incremental seed completed.
- VACUUM ANALYZE completed.
- Benchmark evidence saved.
- No VPS deployment performed.
- No pilot/live data touched.

Decision:
- Patch only if benchmark shows pages above 1 second or EXPLAIN ANALYZE proves a clear bottleneck.
