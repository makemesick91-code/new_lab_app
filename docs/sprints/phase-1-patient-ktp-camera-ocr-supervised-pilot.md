# PHASE-1-PATIENT-KTP-CAMERA-OCR-SUPERVISED-PILOT

Status: **ENGINEERING READINESS GO — DEPLOYED INERT. The physical pilot has NOT been run.**
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `aa627cdb`
(= `revision-registration-ktp-camera-ocr-1-go`, verified as the live production HEAD before work began).
Rule: `.cursor/rules/178-ktp-camera-ocr-supervised-pilot.mdc` (extends 177).
Protocol: `docs/runbooks/ktp-camera-ocr-supervised-pilot-protocol.md`.

## Why this sprint exists

REVISION-REGISTRATION-KTP-CAMERA-OCR-1 shipped camera capture + browser OCR
behind `patient.ktp_camera_ocr`. Audit showed that flag is **global**: it is
read in exactly two places (the parse endpoint's FormRequest and the
`_ktp-scan` Blade partial) and nothing else limits it. Switching it on for a
"pilot" would have turned OCR on for every operator at every branch. A
supervised pilot needs a server-side scope; this sprint adds it and nothing else.

## Audit of the baseline (verified, not rebuilt)

All 13 baseline properties re-verified in source and by the existing 50-test
suite: camera capture, self-hosted tesseract assets (no CDN fallback),
suggestion-only parser, low-confidence never pre-ticked, no silent overwrite,
explicit operator confirmation, existing duplicate check at submit, private
storage + policy-gated serving, no NIK in logs, parse endpoint stores nothing,
safe temp cleanup (systemd timer), flag off → 404. None was rewritten.

## What changed

| | |
|---|---|
| `config/patient_ktp_ocr_pilot.php` | Per-deployment cohort / branch / devices / period from the environment; committed ceilings (5 operators, 1 branch, 3 devices, 31 days). Device requirement fails safe ON. **No "everyone" key.** |
| `KtpCameraOcrPilotGate` + `KtpCameraOcrPilotDecision` | The single decider: flag → valid config → operator in cohort → server-resolved working branch (`RmeWorkingBranchScope::activeBranchId`) equals the pilot branch → inside period (`ClinicalClock`) → approved bound tablet. Stable reason codes; PII-free; writes nothing. |
| `ParseKtpOcrRequest`, `_ktp-scan.blade.php` | Both readers now ask the gate. Denial = 404 + hidden camera UI; manual registration unchanged. |
| `DoctorDeviceRepository::findById` | Plain (non-locking) read for the eligibility check. |
| `patient:ktp-ocr-pilot-status` | Read-only posture: `INERT` / `INERT_CONFIGURED` / `ARMED` / `ARMED_UNREACHABLE` / `MISCONFIGURED`; `--user` probe runs in an always-rolled-back transaction; `--strict` exits 2 when the flag is on but the scope is unusable. No `--arm`. |
| `config/feature_flags.php` | Flag metadata states the flag alone enables nobody. |

No migration, no permission, no role, no route, no JS change.

## Defect found by the tests

`env()` returns boolean `false` for the literal string `"false"`, and
`(string) false === ''`. The first draft of the device switch therefore read an
explicit owner `…REQUIRE_BOUND_DEVICE=false` as "unset" — failing safe, but
silently ignoring a recorded decision. Fixed (boolean checked before the cast),
pinned by a 7-case env matrix.

## Design decisions

- **Governance roles are refused.** Owner / Supervisor RME / Super Admin span
  every RME branch, which is ambiguous for a one-branch pilot. Pilot operators
  are context-bound roles (Admin Klinik / Front Office / Perawat) at the branch.
- **The device check has no definition of its own** — it requires the
  front-office lock's own `evaluate()` to ALLOW, then narrows to the pilot
  allowlist and branch (security review MEDIUM-2 / LOW-3).
- **Device binding reuses the front-office trusted-device lock.** The only
  server-verified "this session is on that tablet" fact for a password-authenticated
  operator is `front_office_device.device_id`, written only after a WebAuthn
  ceremony. That lock is OFF in production, so a device-requiring pilot reads
  `ARMED_UNREACHABLE` until the owner arms it or records a waiver (D5).
- **No telemetry table.** Rule 177 §7 forbids the parse path storing anything;
  pilot accuracy is recorded by operators in an aggregated, identifier-free form.

## Evidence

All local (PHP 8.5 CLI; CI runs PHP 8.3 / PostgreSQL 16 and is authoritative).

| Check | Result |
|---|---|
| `PatientKtpCameraOcrPilotTest` (new) | 51 passed |
| `PatientKtpCameraOcrTest` (existing; actor now a real pilot operator) | 50 passed — no assertion weakened |
| Gate mutation, round 1 | 15 / 15 killed |
| Device mutation, round 2 (after review fixes) | 4 killed, 1 equivalent (D2: every lock denial is also caught by a later clause — kept as defence in depth) |
| `tests/Feature/RME` | 1622 passed, 0 failed |
| `tests/Feature/Patient` · `Pwa` · `Cicd` | 74 · 24 · 272 passed |
| `FrontOfficeDevice` · `DoctorDevice` · `DoctorDeviceWebAuthn` | 8 · 4 · 69 failures — **identical by test name at the unmodified base `aa627cdb`** (pre-existing local-environment WebAuthn failures, not this change) |
| `npm run test:js` | 76 pass |
| `npm run build` | pass; OCR assets self-hosted under `public/build/ocr/` |
| pint · `git diff --check` | clean |
| `sprint:manifest-check` · `sprint:scope-audit --strict` · `foundation:devflow-check --strict` · `foundation:ci-runtime-control-check --strict` · `foundation:security-compliance-check` | GO |

Security review (independent, adversarial): no CRITICAL/HIGH. MEDIUM-1 (the
"writes nothing" claim was false on the request path — corrected and pinned),
MEDIUM-2 (device predicate omitted enrolment — fixed by reusing the lock's
own `evaluate()`), LOW-3 (a bound session kept OCR after the lock flag was
turned off — fixed), LOW-4 (OCR runs in the browser; only the parse endpoint
and camera UI are server-gated — documented). No request-supplied branch
influence, no session-key spoofing path, no type juggling, no PII leak found.

## Remaining blockers for the physical pilot (owner / clinic)

D1–D7 in the protocol: pilot branch, operator ids, tablet ids, period, device
requirement decision, acceptance thresholds, consent wording. Then the
supervised captures. `REAL_DEVICE_PILOT = PENDING PHYSICAL EXECUTION`.

## Release evidence

| | |
|---|---|
| PR | #465, squash-merged as `87930fcd807615dfda1bbd6de74c42c98c16ac8a` |
| Tree | `5e536e58` — identical to the CI-tested candidate `d99d52e9` |
| CI | run `37950370237` success on `d99d52e9`; NSF-R011 Critical **5358 passed / 0 failed** (27619 assertions), `PatientKtpCameraOcrPilotTest` executed by name; Quality, Selective Module, NSF-9, NSF-10, Android gate success |
| Full Suite | **SKIPPED** under the active temporary Full-Suite policy — not a pass |
| Deploy | `scripts/deploy-vps-runner.sh start` run ON `srv1730088`: `exit=0`, `DEPLOY OK: 20261009-172149`, `DEPLOY_HEAD_TARGET_MATCH=YES`, snapshot cleaned; no pending migrations |
| Backup | `pre_auto_deploy_20261009-172149.sql`, 32 MB, `0640 daengtisiams` |
| Production | `/login` + `/health/{live,ready,lb}` 200 over `https://daengtisia.online`; registration pages 302 for guests (no 500); guest parse POST 419 |
| Pilot posture | `patient:ktp-ocr-pilot-status --strict` → `INERT`, exit 0; flag `enabled=false via=default`; probe of a real front-desk account → `feature_disabled` |
| Errors | 0 since deploy. One pre-deploy `pilot.ERROR` at 2026-10-09 17:21:34 UTC was self-inflicted (the status command probed before it existed); it is not an application error and ages out of the 24h monitoring window |
| GO tag | `phase-1-patient-ktp-camera-ocr-pilot-readiness-go` (object `93ffd76c`) → `87930fcd`, exact-match at VPS HEAD |

The tag name deliberately says **readiness**. `phase-1-patient-ktp-camera-ocr-supervised-pilot-go`
is reserved for after the supervised physical pilot passes the protocol's acceptance criteria.

```
ENGINEERING_RELEASE_GO=YES
PILOT_ACTIVATION_GO=NO        (owner decisions D1–D7 outstanding)
REAL_DEVICE_PILOT_GO=NO       (PENDING PHYSICAL EXECUTION)
WIDER_ROLLOUT_GO=NO
```
