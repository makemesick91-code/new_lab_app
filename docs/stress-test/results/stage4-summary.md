# Stage 4 Local Concurrency Smoke Summary

Generated: 2026-06-30T11:57:52Z

Scope: local only (`http://127.0.0.1:8008`), stress DB `daengtisia_stress`, no VPS/pilot.

Dataset baseline: Stage 3 (1M visits, visit_id=1000000 for detail pages).

Tool: parallel `curl` (ab/wrk unavailable).

## 5 concurrent / 50 requests

- Total: 50
- HTTP 200: 50
- Failed: 0
- Status counts: {200: 50}
- min/avg/max/p95 (s): 0.1297 / 0.1988 / 0.2167 / 0.2149

## 10 concurrent / 100 requests

- Total: 100
- HTTP 200: 100
- Failed: 0
- Status counts: {200: 100}
- min/avg/max/p95 (s): 0.1745 / 0.4176 / 0.5045 / 0.4999

## Decision

Patch only if concurrency failures or sustained p95 above 1s under local smoke.

Evidence: `stage4-concurrency-*.csv`, `stage4-concurrency-*.txt`, `stage4-system-resources.txt`.
