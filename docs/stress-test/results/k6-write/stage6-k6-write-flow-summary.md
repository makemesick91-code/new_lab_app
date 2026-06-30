# Sprint 67.6 — k6 Write Full-Flow Local Test (Stage 6)

**Local-only stress evidence. No VPS deploy. No pilot/live data. Stress DB only.**

## Environment

| Item | Value |
| --- | --- |
| Branch | `test/sprint-67-6-k6-write-full-flow-local` |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Base commit (HEAD at start) | `a0d99b8` |
| APP_ENV | `stress` |
| DB_DATABASE | `daengtisia_stress` |
| APP_URL | `http://127.0.0.1:8008` (`php artisan serve`) |
| k6 version | `k6 v2.0.0 (go1.26.3, linux/amd64)` |
| Test users | `stress.admin002`..`stress.admin010` (Admin Klinik), rotated per VU |
| Password | passed via env `STRESS_PASSWORD` only — **not committed** |
| Test script | `tests/k6/rme-write-full-flow-local.js` |

## What the write flow does (per iteration)

1. **Login** as a stress Admin Klinik user (rotated across `admin002..010`).
2. **Select RME online context / branch** — `POST /rme/online-context/admin-clinic`
   with `branch_id` (real context write that sets the active branch).
3. **Create a clinic visit (the concurrent DB write)** — `POST /rme/visits` for an
   existing patient, `patient_mode=existing`, `visit_type=new`,
   `initial_treatment_id`, tagged with the unique `K6 Sprint 67.6 | K6S676 | …`
   marker. Real `INSERT` into `trx_clinic_visits`.
4. Parse the `302` redirect `Location` to capture the created `visit_id`.
5. **Verify pages 200** — `GET /rme/visits/:id` (created visit) and
   `GET /rme/patient-queue` (queue reflects the new visit). The CSRF token for
   the create POST is taken from the lightweight `GET /rme/visits` index page.

The login uses a **per-iteration login**: k6 resets the per-VU cookie jar at the
start of every iteration, so a true "login once per VU" is not possible without
fragile manual cookie replay. Each iteration therefore re-establishes an
authenticated, context-ready session before the write.

## Synthetic data namespace (no real patient data)

- Visit marker prefix: `K6 Sprint 67.6` / `K6S676` written to `chief_complaint`.
- Visits attach to **existing** seeded patients (random id in `[1, 200000]`,
  branch 1) — no patient PII is generated, read, or printed.
- No KTP/NIK, password, cookie, CSRF token value, or login HTML is written to
  any committed file.

## Results

| VUs | Iters | checks | HTTP failed | visit_create | write_flow success | write_flow p95 | http_req p95 | throughput | Decision |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1  | 5  | 100% (45/45)   | 0.00% | 100% (5/5)   | 100% | 1.84 s  | 0.73 s  | 4.7 req/s | **PASS** |
| 5  | 25 | 100% (225/225) | 0.00% | 100% (25/25) | 100% | 9.05 s  | 2.06 s  | 5.4 req/s | **PASS (duration WATCH)** |
| 10 | 50 | 100% (450/450) | 0.00% | 100% (50/50) | 100% | 17.98 s | 4.13 s  | 5.5 req/s | **PASS (duration WATCH)** |
| 20 | 40 | 100% (360/360) | 0.00% | 100% (40/40) | 100% | 35.76 s | 10.9 s  | 5.5 req/s | **WATCH (functional PASS, heavy)** |
| 50 | —  | not run | — | — | — | — | — | — | **Intentionally skipped** |

- **No 419 (CSRF) errors, no 500 errors, no duplicate-key conflicts** at any level.
- `http_req_failed` is `0.00%` at every level.
- 50 VU was **not run on purpose**: per the sprint safety guidance ("do not force
  50 VU if database writes become too heavy"). The 1→20 VU trend already proves
  the writes serialize (see below), so 50 VU adds load without new signal.

### Decision: **Functional PASS / Write-Duration WATCH**

Functionally every write succeeded with zero failures and zero integrity issues
through 20 concurrent VUs. However the write path **does not scale** with
concurrency:

- `write_flow_duration` grows almost linearly with VUs: 1.8 s → 8.5 s → 17 s → 32 s
  (avg) for 1 / 5 / 10 / 20 VUs.
- End-to-end throughput is **flat at ~5.5 req/s** regardless of VU count → the
  visit-create write is effectively **serialized**.
- During the 20 VU run, **PostgreSQL CPU peaked ~685%** (multi-core saturation)
  while the PHP layer stayed near-idle — the bottleneck is the database write,
  not the app server. (See `stage6-k6-write-system-resources.txt`.)

This mirrors the Sprint 67.5 read-flow "Flow Duration WATCH" and is the headline
follow-up item for a future sprint (queue-number generation / per-branch write
contention on the 1M-row `trx_clinic_visits` table is the likely cause).

## Dataset verification (post-run)

| Metric | Value |
| --- | --- |
| `trx_clinic_visits` total (baseline → after) | 1,000,000 → 1,000,220 |
| Visits created with `K6S676` marker | 220 |
| Visits with full `K6 Sprint 67.6 …` namespace | 212 |
| Distinct patients across K6 visits | 220 (= visit count → **no patient collision**) |
| Branch | all branch 1 (TST, RME-enabled) |
| Visit id range | 1000001 – 1000220 |

`distinct_patients == visit_count` confirms there were **no duplicate / unique-key
conflicts** under concurrency — every write landed cleanly.

Reproduce the count:

```bash
php artisan tinker --env=stress --execute="
echo DB::table('trx_clinic_visits')->where('chief_complaint','like','%K6S676%')->count();
" < /dev/null
```

## Sub-flows — implemented vs deferred (WATCH)

| Target sub-flow | Status | Blocker / reason |
| --- | --- | --- |
| Login | **PASS** | per-iteration login (k6 cookie-jar reset) |
| Select RME online context / branch | **PASS** | `POST /rme/online-context/admin-clinic` |
| Create clinic visit (existing patient) | **PASS** | real INSERT, verified in DB |
| Verify result pages (200) | **PASS** | visit show + patient queue |
| **Create a unique NEW patient** | **WATCH / deferred** | No seeded stress role has the `manage patients` permission, so `patient_mode=new` (and `settings/patients` store) returns **403**. Visit-create therefore attaches to existing seeded patients. *Next sprint: seed a Pendaftaran/registration role with `manage patients`, then a dedicated patient-create write test.* |
| Assign treatment room | **WATCH / deferred** | `ClinicVisitService::assignRoom` → `UserOnlineContextService::resolveDoctorIdForRoom` requires **exactly one non-expired online doctor in the room**; with none it raises `ValidationException` ("Belum ada dokter online di ruangan ini") and the room is not set. Needs a multi-actor online-doctor session out of scope for a single k6 HTTP VU. |
| Write / finalize medical record | **WATCH / deferred** | The medical-record routes are gated by the `visit.room` middleware (`EnsureVisitRoomAssigned`), so they are blocked by the room prerequisite above. Finalize additionally requires a **mandatory handwriting PNG multipart upload** (immutable after finalize). |
| Write odontogram | **WATCH / deferred** | Needs an existing odontogram id + a structured `tooth_map_payload` JSON, and is also behind the room gate. |
| Cashier / billing + payment | **WATCH / deferred** | Requires the visit at `cashier_pending` (doctor→cashier completion gate), a patient **consent** record, billable treatment items, and a payment method; it also creates financial rows (`trx_rme_invoice` / `trx_rme_payment`). Too stateful/financial for a blind k6 write this sprint. |

## Safety statement

- Ran **local only** against `http://127.0.0.1:8008` (`php artisan serve`).
- `APP_ENV=stress`, `DB_DATABASE=daengtisia_stress` only.
- **No VPS / SSH / deploy.** No pilot or live data touched.
- No destructive commands (`migrate:fresh`, `db:wipe`, `dropdb`, `truncate`,
  mass delete). Only additive synthetic visit rows were created.
- Password / cookies / CSRF tokens / login HTML are **not** committed — the
  password is supplied at runtime via `-e STRESS_PASSWORD=…`.

## How to run

```bash
# 1 VU smoke
k6 run -e VUS=1  -e ITER_PER_VU=5 -e STRESS_PASSWORD='***' tests/k6/rme-write-full-flow-local.js
# 5 / 10 / 20 VU
k6 run -e VUS=5  -e ITER_PER_VU=5 -e STRESS_PASSWORD='***' tests/k6/rme-write-full-flow-local.js
k6 run -e VUS=10 -e ITER_PER_VU=5 -e STRESS_PASSWORD='***' tests/k6/rme-write-full-flow-local.js
k6 run -e VUS=20 -e ITER_PER_VU=2 -e STRESS_PASSWORD='***' tests/k6/rme-write-full-flow-local.js
```
