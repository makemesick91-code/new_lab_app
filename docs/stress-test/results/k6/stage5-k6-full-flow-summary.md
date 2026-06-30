# Sprint 67.5 k6 Full Flow 50 Users Local Test

Scope: local only, no VPS deploy.

## Dataset

- Patients: 250,000
- RME visits / medical records / invoices: 1,000,000
- Invoice items: 3,000,000
- Payments / lab candidates: 900,000
- Follow-ups: 200,000

## Tool

- k6
- constant-vus executor
- Laravel login + CSRF correlation
- RME online context selection
- Full RME read-heavy flow
- Session model: login/context once per VU, then repeat RME page flow

## Flow

1. Login
2. Select RME online context
3. Dashboard
4. Visit list
5. Visit detail
6. Medical record
7. Odontogram
8. Cashier
9. Receivables
10. Patient report
11. Payment report

## Result Summary

### 1 VU

- Checks: 100%
- HTTP failed: 0.00%
- HTTP p95: 21.55ms
- Full flow p95: 2.2966s
- Decision: PASS

### 5 VU

- Checks: 100%
- HTTP failed: 0.00%
- HTTP p95: 23.48ms
- Full flow p95: 2.2065s
- Decision: PASS

### 20 VU

- Checks: 100%
- HTTP failed: 0.00%
- HTTP p95: 307.38ms
- Full flow p95: 10.70485s
- Decision: PASS

### 50 VU

- Checks: 100%
- HTTP failed: 0.00%
- HTTP p95: 915.17ms
- Full flow success rate: 100%
- Full flow p95: 25.96225s
- Decision: FUNCTIONAL PASS / FLOW DURATION WATCH

## Interpretation

The application successfully served 50 concurrent virtual users in local-only k6 full-flow testing without HTTP failures or failed checks.

The 50 VU run crossed the full-flow-duration threshold, but HTTP p95 remained below 1 second and all functional checks passed.

This indicates that individual HTTP endpoints remain healthy, while the complete serialized user journey becomes slower under 50 concurrent local users on the local development server.

## Decision

- 1 VU: PASS
- 5 VU: PASS
- 20 VU: PASS
- 50 VU: FUNCTIONAL PASS / FLOW DURATION WATCH
- No code patch required from this run.
- No EXPLAIN ANALYZE required unless a future run shows endpoint-level p95 above 1 second or HTTP failures.
- No VPS deployment performed.

## Safety

- Local only.
- Stress DB only: daengtisia_stress.
- No VPS deployment.
- No pilot/live data touched.
- No cookies, login HTML, CSRF token, or plaintext password committed.
