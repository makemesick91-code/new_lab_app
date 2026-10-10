# KTP Camera OCR — Supervised Pilot Activation, Cabang Sunu (2026-10-10)

Task `PHASE-2-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT-ACTIVATION-1` · Rule 178 · Rule 177 ·
Protocol `docs/runbooks/ktp-camera-ocr-supervised-pilot-protocol.md`.

**Configuration-only activation.** No code, migration, route, permission or deployment.
Production kept running `87930fcd` (GO tag `phase-1-patient-ktp-camera-ocr-pilot-readiness-go`).
All times are WITA (UTC+8) unless marked UTC.

```
CONFIGURATION_ACTIVATION_GO = YES
REAL_CAMERA_TEST            = PENDING PHYSICAL EXECUTION
REAL_KTP_PILOT              = PENDING PHYSICAL EXECUTION (blocked on D7)
CLINICAL_PILOT_COMPLETION   = NO
WIDER_ROLLOUT               = NO-GO
```

> **Forward note — PHASE-3-PATIENT-KTP-ROI-OCR-CLINICAL-PILOT-1 (same day).** The owner approved the
> D7 consent wording on 2026-10-10. It is held verbatim in `config/patient_ktp_ocr_consent.php`
> (version `D7-2026-10-10`), shown on screen before the camera opens and printed as protocol
> Appendix A; the parse endpoint now refuses OCR text without it. The D7 row below is kept as the
> record of this activation, when it was still open. Pilot scope, period (ends 2026-10-16) and the
> device waiver are unchanged. Evidence: `docs/sprints/phase-3-patient-ktp-roi-ocr-clinical-pilot-1.md`.

## 1. Owner decisions (protocol §0)

| # | Decision | Value | Source |
|---|---|---|---|
| D1 | Pilot branch | **SPN4 — Cabang Sunu** (branch id 5) | owner, 2026-10-10 |
| D2 | Operator | **user 29, Admin Sunu** (Front Office) — one operator | owner, 2026-10-10 |
| D3 | Clinic tablets | **none** — waived by D5 | owner, 2026-10-10 |
| D4 | Period | **2026-10-10 .. 2026-10-16**, inclusive, clinical calendar | owner, 2026-10-10 |
| D5 | Device requirement | **WAIVED** — the pilot device is the Front Office desktop/laptop with a webcam, which is not an enrolled clinic tablet. The waiver applies to KTP OCR eligibility only. | owner, 2026-10-10 |
| D6 | Acceptance criteria | protocol §5 **plus** the working targets in §7 below | owner, 2026-10-10 |
| D7 | Consent wording for KTP holders | **NOT RECORDED** | outstanding |

**D7 blocks every physical capture.** The protocol makes written consent from the KTP holder a
precondition of each capture, and no approved wording exists yet. Configuration activation does
not depend on D7; the first real capture does.

## 2. What the waiver does and does not touch

`PATIENT_KTP_OCR_PILOT_REQUIRE_BOUND_DEVICE=false` makes `KtpCameraOcrPilotGate::decide()` skip
its device clause. That clause is the gate's **only** reader of the front-office device lock, so
the waiver cannot change any other control. Verified after arming:

| Control | State |
|---|---|
| `front_office.branch_device_lock` | `enabled=false via=default` — unchanged |
| `front_office.branch_context_lock` | `enabled=true via=env` — unchanged; user 29 pinned to SPN4 |
| Doctor trusted devices, doctor sessions, WebAuthn, device approvals | not read by the waived clause; untouched |
| `PATIENT_KTP_OCR_PILOT_DEVICE_IDS` | not set — an empty device list raises no error only because the requirement is waived |

## 3. Pre-activation measurements (read-only)

| Check | Result |
|---|---|
| Production HEAD | `87930fcd807615dfda1bbd6de74c42c98c16ac8a`, tree `5e536e58`, exact match on the readiness GO tag |
| Working tree | clean except the pre-existing untracked `storage/runtime-home/` (not task-owned) |
| Deploy locks | `deploy.lock` and `rollback.lock` free |
| Services | php8.3-fpm, nginx, postgresql, queue worker, db-backup timer: active |
| Health | `/login`, `/health/live`, `/health/ready`, `/health/lb` → 200; every readiness component `ok` |
| Migrations / failed jobs | 0 pending / none |
| Disk | 88 GB free of 96 GB |
| Pilot gate | `INERT`, every pilot key absent |
| User 29 | name **Admin Sunu**, active, not deleted, sole role **Front Office**, `manage patients` via role |
| Branch authority | branch-context pin armed for `29:SPN4`; online context and today's daily branch context both SPN4 |
| Application log | 1,437,201 bytes, 162 `ERROR` lines (newest is the pre-deploy probe of 2026-10-09 17:21:34 UTC) |

## 4. Change applied

Two steps, as the protocol prescribes. Each edit wrote a temp file in the application directory
and renamed it over the environment file (atomic), refused to run if any key already existed,
held the deploy flock so no deploy could interleave, and rebuilt the config cache as
`daengtisiams` (`config:clear` + `config:cache`).

| Step | Time | Result |
|---|---|---|
| Backup | 07:00:24 | `/root/env-backups/env-backup-ktp-ocr-pilot-activation-20261009-230024`, `0600 root:root`, hash equal to the pre-change file (`b6685fc1…`) |
| Stage scope, flag off | 07:00:25 | `INERT_CONFIGURED`, `ERRORS=[]`, strict exit 0 |
| Arm flag | **07:00:37** (`2026-10-09T23:00:37Z`) | `ARMED`, `ERRORS=[]`, strict exit 0 |

Keys added, one occurrence each (102 → 109 lines, one comment line included):

```
PATIENT_KTP_OCR_PILOT_USER_IDS=29
PATIENT_KTP_OCR_PILOT_BRANCH_CODES=SPN4
PATIENT_KTP_OCR_PILOT_STARTS_ON=2026-10-10
PATIENT_KTP_OCR_PILOT_ENDS_ON=2026-10-16
PATIENT_KTP_OCR_PILOT_REQUIRE_BOUND_DEVICE=false
FEATURE_PATIENT_KTP_CAMERA_OCR=true
```

The file kept `640 root:daengtisiams`; `www-data` still cannot read it; no temp file left behind.
`foundation:feature-flags` reports `patient.ktp_camera_ocr enabled=true via=env captured=yes`, so
the value survives the config cache.

## 5. Post-activation verification

### Server-side gate (`patient:ktp-ocr-pilot-status --user=<id>`, rolled-back transaction)

| Account | Decision |
|---|---|
| 29 Admin Sunu | **allowed**, branch 5, no device |
| 7, 8, 16, 17, 30, 31, 32 (every other Front Office account) | `operator_not_in_pilot` |
| 1 Super Admin, 11 Supervisor RME | `operator_not_in_pilot` |
| non-existent id | `not_authenticated` |

### HTTP (over `https://daengtisia.online`)

| Probe | Result |
|---|---|
| Guest `GET /settings/patients/create`, `GET /rme/visits/create` | 302 → `/login` |
| Guest `POST …/ktp-scan/parse-ocr` | 419 (refused before auth); `GET` → 405 |
| Self-hosted OCR assets (`/build/ocr/…`: worker, SIMD core, `ind` model) | 200, served from this origin |
| `Permissions-Policy` / `Feature-Policy` | absent — nothing blocks `getUserMedia` on this HTTPS origin |

### Production-shape verification (local, against `ad975452`)

The existing suite covers Admin Klinik operators and the device-lock-ON path. The production
combination — Front Office account + branch-context pin + device lock OFF + device waiver — had
no test, so it was verified with an ad-hoc suite (not committed) that arms exactly that shape:

| # | Property | Result |
|---|---|---|
| V1 | Pilot operator gets the camera on **both** Daftar Kunjungan Baru and Master Data, parse endpoint 200; scanner-agent and manual-upload controls still render | pass |
| V2 | Status `ARMED`, no errors, strict exit 0, decision `allowed` with no device | pass |
| V3 | Front-office device lock stays off and out of scope | pass |
| V4 | Non-pilot Front Office operator at the same branch: 404, no camera, manual upload intact | pass |
| V5 | Cohort operator working at another branch: `branch_not_in_pilot`, 404 | pass |
| V6 | The pinned pilot operator cannot switch to another branch | pass |
| V7 | Super Admin outside the cohort: denied | pass |
| V8 | Unauthenticated: 401 / `not_authenticated` | pass |
| V9 | `MAIN` cannot be configured | pass |
| V10 | Window boundaries: 09 Oct 23:59:59 denied · 10 Oct 00:00 allowed · 16 Oct 23:59:59 allowed · 17 Oct 00:00 denied | pass |
| V11 | After 16 Oct the status still reads `ARMED` while every decision is `outside_pilot_period` (see §8) | pass |
| V12 | The six production environment strings parse exactly (including `"false"` → boolean false) | pass |
| V13 | Rollback: flag off removes the camera and the endpoint; manual flow intact | pass |

16 tests / 62 assertions. Mutation check: removing the waiver (11 failures), the branch clause
(1) or the cohort clause (2) each turns the suite red; sources restored byte-identical.
Existing suites at the same commit: `PatientKtpCameraOcrPilotTest`, `PatientKtpCameraOcrTest`,
`PatientKtpScanTest`, `RmeVisitNewPatientKtpScanTest`, `FrontOfficeBranchContextLockTest` —
155 passed / 583 assertions.

### Side effects

None. The application log stayed **byte-identical** (1,437,201 bytes, 162 `ERROR`); no failed
jobs; no pending migrations; patients unchanged (1,521, unchanged last update); user 29's online
context unchanged; HEAD unchanged.

### Not verified (requires a person at the clinic)

- **Authenticated browser session as Admin Sunu.** No credential was used or created for this
  account; seeing the camera button in a real session is the operator's first step (§6).
- **Real webcam** on the Front Office computer — `REAL_CAMERA_TEST = PENDING`.
- **Real KTP captures** — `REAL_KTP_PILOT = PENDING`, and blocked on D7.

## 6. First-session checklist for the operator (Admin Sunu, SPN4)

1. Log in normally; select **Cabang Sunu** as working branch.
2. Open **Daftar Kunjungan Baru** → pasien baru → **SCAN KTP**: the button
   **Foto KTP dengan Kamera** must be visible, alongside **Cek Scanner** and
   **Unggah foto KTP secara manual**.
3. Allow the browser camera prompt; confirm preview → **Ulangi** → **Gunakan Foto Ini**, and
   that the webcam light turns off after capture.
4. Do not capture any real KTP until D7 (consent wording) is recorded.
5. Record results per protocol §4 — outcomes only, never a NIK, name, address or birth date.

## 7. Working acceptance targets (owner-approved for this pilot)

Applied on top of protocol §5. Nothing below has been measured yet.

| Metric | Target | Measured |
|---|---|---|
| Consented captures | ≥ 30 | NOT TESTED |
| Camera capture success | ≥ 95 % | NOT TESTED |
| OCR suggestion availability | ≥ 90 % | NOT TESTED |
| NIK exact match | ≥ 95 % | NOT TESTED |
| Name exact match | ≥ 90 % | NOT TESTED |
| Median OCR time | ≤ 5 s | NOT TESTED |
| Security / cross-branch violations | 0 | NOT TESTED |
| Patient data corruption | 0 | NOT TESTED |
| Unverified incorrect data saved | 0 | NOT TESTED |
| Manual registration fallback | available and verified | verified in tests (V4, V13); not yet on site |

Charts are produced only from real, aggregated, identifier-free measurements.

## 8. Operational follow-up: the pilot does not switch itself off

The gate stops admitting anyone after 16 Oct (V10), but the status verdict describes
**configuration**, not today's eligibility: on 17 Oct it still reads `ARMED` while every
decision is `outside_pilot_period` (V11). Nobody gains access, but the posture looks live.

On **2026-10-17**, either disarm (`FEATURE_PATIENT_KTP_CAMERA_OCR=false`) or record a fresh
owner decision extending D4. Check eligibility with `--user=29`, not the verdict alone.

## 9. Rollback (any time, ≤ 5 minutes)

On the VPS, as root, in `/var/www/asia-dental-lab-v2`:

1. Set `FEATURE_PATIENT_KTP_CAMERA_OCR=false` (keep `640 root:daengtisiams`; re-check with
   `runuser -u daengtisiams -- test -r .env`).
2. `runuser -u daengtisiams -- php artisan config:clear && runuser -u daengtisiams -- php artisan config:cache`
3. `runuser -u daengtisiams -- php artisan patient:ktp-ocr-pilot-status` → `INERT_CONFIGURED`.

The camera button disappears and the parse endpoint answers 404 (V13); the scanner-agent and
manual-upload flows are untouched. OCR created no data, so nothing is migrated back. To remove
the pilot entirely, delete the six keys and the comment line; the file then returns to the
backup's hash. Rollback was rehearsed in tests only — it was not executed on production because
nothing required it.
