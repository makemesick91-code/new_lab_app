# Sprint 67.7 — Final Stress Closure & Bottleneck Recommendation Report

Status: FINAL CLOSURE REPORT  
Scope: Local-only stress testing  
Application: Daengtisia Management System / DaengtisiaMS  
Environment: stress  
Database: daengtisia_stress  
App URL: http://127.0.0.1:8008  
VPS deploy: Not performed  

---

## 1. Executive Summary

Sprint 67 validates DaengtisiaMS RME performance under large local stress datasets and concurrent simulated usage.

The stress program covered:

1. RME receivables performance optimization.
2. Stage 2 local stress evidence.
3. Stage 3 local stress evidence.
4. Local concurrency smoke.
5. k6 read-heavy full-flow testing up to 50 virtual users.
6. k6 write-flow local testing up to 20 virtual users.
7. Final closure and bottleneck recommendation.

Overall decision:

- Read-heavy RME pages: PASS.
- Local mixed concurrency smoke: PASS.
- k6 50 VU read-heavy full flow: FUNCTIONAL PASS / FLOW DURATION WATCH.
- k6 write-flow up to 20 VU: FUNCTIONAL PASS / WRITE-DURATION WATCH.
- No additional emergency code patch required from Sprint 67.5–67.6 results.
- No VPS deploy was performed as part of stress execution.
- Stress data must not be deployed or copied to pilot/live.

---

## 2. Sprint 67 Milestone Summary

### Sprint 67.1 — RME Receivables Stress Performance Optimization

PR: #104  
Merge commit: 8d298c4  
GO tag: sprint-67-1-rme-receivables-stress-performance-go  

Purpose:
- Fix RME receivables page performance bottleneck found during local stress testing.

Result:
- Receivables page improved from approximately 12.8974 seconds to below 1 second in Stage 1 benchmark.
- SQL aggregate strategy and indexes were applied.
- Main RME pages returned to acceptable local stress performance.

Decision:
- PASS.
- Performance fix is valid and should be included in normal deploy planning.
- Stress data must not be deployed.

---

### Sprint 67.2–67.4 — Local RME Stress and Concurrency Evidence

PR: #105  
Merge commit: 0cc482d  
GO tag: sprint-67-2-4-local-rme-stress-concurrency-evidence-go  

Covered:
- Stage 2 local stress dataset.
- Stage 3 local stress dataset.
- Stage 4 local concurrency smoke.

#### Stage 2 Dataset

- Patients: 100,000
- Visits / MR / invoices: 300,000
- Invoice items: 900,000
- Payments / lab candidates: 270,000
- Follow-ups: 60,000
- DB size: approximately 1070 MB

Result:
- Main RME pages HTTP 200.
- All benchmarked pages stayed below 1 second.

Decision:
- PASS.

#### Stage 3 Dataset

- Patients: 250,000
- Clinic visits / MR / invoices: 1,000,000
- Handwriting pages: 1,000,000
- Odontograms: 1,000,000
- Invoice items: 3,000,000
- Payments: 900,000
- Follow-ups: 200,000
- Lab candidates: 900,000

Result:
- Sequential RME benchmarks returned HTTP 200.
- Main RME pages averaged below 0.05 seconds in command-level benchmark output.

Decision:
- PASS.

#### Stage 4 Local Concurrency Smoke

Tools:
- curl-based local concurrency script.
- Local stress app at http://127.0.0.1:8008.

Results:
- 5x50: 50/50 HTTP 200, failed 0, p95 approximately 0.215s.
- 10x100: 100/100 HTTP 200, failed 0, p95 approximately 0.500s.

Decision:
- PASS.

---

### Sprint 67.5 — k6 Full Flow 50 Users Local Evidence

PR: #106  
Merge commit: a0d99b8  
GO tag: sprint-67-5-k6-full-flow-50-users-local-evidence-go  

Purpose:
- Simulate concurrent read-heavy RME full flow with k6.

Flow:
1. Login.
2. Select RME online context.
3. Dashboard.
4. Visit list.
5. Visit detail.
6. Medical record.
7. Odontogram.
8. Cashier.
9. Receivables.
10. Patient report.
11. Payment report.

Session model:
- Login/context once per VU for session-style test.
- Strict login-each-iteration script also preserved as reference.

Results:

| VU | Checks | HTTP Failed | HTTP p95 | Full Flow p95 | Decision |
| --- | --- | --- | --- | --- | --- |
| 1 | 100% | 0.00% | 21.55ms | 2.2966s | PASS |
| 5 | 100% | 0.00% | 23.48ms | 2.2065s | PASS |
| 20 | 100% | 0.00% | 307.38ms | 10.70485s | PASS |
| 50 | 100% | 0.00% | 915.17ms | 25.96225s | FUNCTIONAL PASS / FLOW DURATION WATCH |

Interpretation:
- At 50 VU, individual HTTP endpoint p95 remained below 1 second.
- All checks passed.
- No HTTP failures occurred.
- End-to-end serialized user journey exceeded the full-flow-duration target.

Decision:
- Functional PASS.
- HTTP-level PASS.
- End-to-end full-flow duration WATCH at 50 VU on local dev server.

---

### Sprint 67.6 — k6 Write Full Flow Local Evidence

PR: #107  
Merge commit: 490e6d8  
GO tag: sprint-67-6-k6-write-full-flow-local-evidence-go  

Purpose:
- Simulate safe local write-flow concurrency using k6.

Reliable implemented write subset:
1. Login.
2. Select RME branch context.
3. Create clinic visit for existing patient.
4. Verify page/result.

Sub-flows documented as WATCH, not faked:
- New patient create:
  - blocked by authorization because no stress role had `manage patients`.
- Room assignment / medical record / odontogram:
  - room assignment requires a non-expired online doctor in the room.
  - MR/odontogram gated behind room assignment.
  - finalize requires mandatory handwriting PNG upload.
- Cashier/payment:
  - requires cashier_pending, consent, treatment items, and payment method.
  - financial row generation should remain separately controlled.

Results:

| VU | Checks | HTTP Failed | Visit Create | Write p95 | Throughput | Decision |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 100% | 0.00% | 100% | 1.84s | 4.7/s | PASS |
| 5 | 100% | 0.00% | 100% | 9.05s | 5.4/s | PASS / DURATION WATCH |
| 10 | 100% | 0.00% | 100% | 17.98s | 5.5/s | PASS / DURATION WATCH |
| 20 | 100% | 0.00% | 100% | 35.76s | 5.5/s | WATCH / FUNCTIONAL PASS |
| 50 | Not run | Not run | Not run | Not run | Not run | Intentionally skipped |

DB verification:
- 220 visits created.
- 220 distinct patients involved.
- No unique-key conflicts.
- Total visits increased from 1,000,000 to 1,000,220.

Observed resource behavior:
- PostgreSQL CPU peaked high at 20 VU.
- PHP was comparatively near-idle.
- Write throughput flattened around 5.5 req/s.

Interpretation:
- Functional write path is safe through 20 VU locally.
- No HTTP 500, 419 CSRF, duplicate conflict, or systemic validation error occurred.
- Write duration scales linearly with VU count.
- Visit-create write path appears DB-bound / serialized under local concurrency.

Decision:
- Functional PASS.
- Write-duration WATCH.
- 50 VU write test intentionally skipped because the bottleneck was already visible at 20 VU.

---

## 3. Final PASS / WATCH Matrix

| Area | Result | Decision |
| --- | --- | --- |
| Receivables optimization | Severe bottleneck fixed | PASS |
| Stage 2 100k patient stress | Main RME pages below 1s | PASS |
| Stage 3 250k patient / 1M visit stress | Main RME pages healthy | PASS |
| Stage 4 local concurrency smoke | 0 failures, p95 below 1s | PASS |
| k6 read-heavy 50 VU | 0 failures, HTTP p95 below 1s, full-flow p95 25.96s | FUNCTIONAL PASS / FLOW WATCH |
| k6 write-flow 20 VU | 0 failures, all writes succeeded, p95 35.76s | FUNCTIONAL PASS / WRITE WATCH |
| k6 write-flow 50 VU | intentionally skipped | WATCH |
| VPS deploy | not performed | N/A |

---

## 4. Bottleneck Analysis

### 4.1 Read-Heavy Flow

Read-heavy RME endpoints remain healthy even under 50 VU k6 load.

Key evidence:
- 50 VU read-heavy:
  - checks 100%
  - HTTP failed 0.00%
  - full_flow_success_rate 100%
  - HTTP p95 915.17ms

Bottleneck:
- End-to-end full-flow duration increased because the complete user journey is serialized across multiple requests and think-time sleeps.
- On local dev server, full-flow p95 reached 25.96s at 50 VU.
- Individual endpoint p95 remained below 1 second.

Recommendation:
- No urgent read-path code patch is required.
- Continue monitoring if production traffic approaches similar concurrent full-flow behavior.
- Keep SQL aggregate/index improvements from Sprint 67.1.

---

### 4.2 Write Flow

The write-flow bottleneck is more important than the read-flow bottleneck.

Key evidence:
- 20 VU write-flow:
  - checks 100%
  - HTTP failed 0.00%
  - visit create success 100%
  - write p95 35.76s
  - throughput approximately 5.5/s
- PostgreSQL CPU peaked high while PHP was comparatively near-idle.

Likely bottleneck class:
- DB-bound write serialization.
- Possible transaction contention.
- Possible sequence/index pressure.
- Possible insert path triggers, policies, audit logs, or related lookup overhead.
- Possible branch/context/patient/visit validation repeated per write.

Recommendation:
- Do not assume PHP is the bottleneck.
- Prioritize PostgreSQL write-path analysis before adding app-level complexity.
- Next technical investigation should inspect:
  - `trx_clinic_visits` indexes.
  - foreign key checks and related indexes.
  - policies or authorization queries invoked per write.
  - online context lookup queries.
  - patient lookup strategy in write script and controller.
  - audit/activity log writes if any.
  - transaction boundaries.
  - DB sequence behavior.
  - slow query logs during k6 write test.
  - `EXPLAIN (ANALYZE, BUFFERS)` for repeated write-related SELECTs, not only INSERT.

---

## 5. Production Readiness Interpretation

Sprint 67 does not prove unlimited scale, but it materially improves confidence.

What Sprint 67 proves:
- RME read pages are not obviously blocked by the 1M visit local dataset.
- Receivables bottleneck was found and fixed.
- 50 concurrent virtual users can execute read-heavy full flow without HTTP failure.
- 20 concurrent virtual users can execute safe write-flow without HTTP failure or duplicate conflicts.
- The main remaining concern is write-path duration and DB-bound behavior.

What Sprint 67 does not prove:
- It does not prove production can run unlimited concurrent users.
- It does not validate 50 concurrent full financial writes.
- It does not validate browser asset loading, real network latency, or Nginx/PHP-FPM production behavior.
- It does not validate VPS capacity under the same stress dataset.
- It does not validate real user behavior with file upload handwriting finalization.
- It does not validate cashier payment row generation under high concurrency.

---

## 6. Deployment Recommendation

Recommended deploy scope:
- Deploy Sprint 67.1 performance fix through normal controlled deploy workflow when ready.
- Include evidence reports in repo.
- Do not deploy stress data.
- Do not run k6 stress test on VPS/pilot/live unless explicitly approved and isolated.

Production deploy caution:
- Stress test evidence is local-only.
- VPS should receive code only, not local stress DB.
- If deploying, follow the normal backup → pull → composer → build → migrate → cache → permission → restart → smoke flow.
- This report itself does not require VPS deploy.

---

## 7. Recommended Next Sprint Options

### Option A — Sprint 68.1 Write Bottleneck Investigation

Purpose:
- Investigate why visit-create write throughput flattens around 5.5/s.

Suggested scope:
- Add optional slow-query evidence for local stress.
- Run PostgreSQL observation during 10/20 VU write k6.
- Capture top SQL statements.
- Review indexes and transaction boundaries.
- Produce recommendations or targeted patch.

Decision:
- Recommended if the owner expects many concurrent operators creating visits.

---

### Option B — Sprint 68.2 Full Browser RME Write E2E

Purpose:
- Cover flows that k6 direct HTTP should not fake:
  - room assignment
  - medical record write
  - odontogram write
  - handwriting PNG upload
  - cashier consent/payment

Suggested tool:
- Playwright or Laravel Dusk, not k6 only.

Decision:
- Recommended for workflow correctness, not raw load.

---

### Option C — Controlled VPS Performance Smoke

Purpose:
- Confirm optimized code behaves well on VPS with normal pilot dataset.

Rules:
- No stress dataset on VPS.
- No aggressive k6 on pilot/live.
- Smoke only.

Decision:
- Recommended only after Sprint 67 closure is accepted.

---

## 8. Final Decision

Sprint 67 final decision:

READ PERFORMANCE:
PASS.

READ CONCURRENCY:
FUNCTIONAL PASS / FLOW DURATION WATCH at 50 VU.

WRITE FUNCTIONALITY:
FUNCTIONAL PASS through 20 VU.

WRITE PERFORMANCE:
WRITE-DURATION WATCH. DB-bound write path requires follow-up investigation if high concurrent visit creation is expected.

DEPLOYMENT:
No VPS deploy performed. Stress data must remain local-only.

CLOSURE:
Sprint 67 local stress program can be closed with WATCH items documented.

---

## 9. Safety Statement

This report is documentation only.

No destructive SQL was performed for Sprint 67.7.
No VPS deploy was performed.
No pilot/live data was touched.
No stress data was copied to VPS.
No password, cookie, CSRF token value, or login HTML is included.
