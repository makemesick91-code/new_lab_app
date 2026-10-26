# SECURITY-DOCTOR-WEBAUTHN-DEVICE-BOUND-FAIL-CLOSED-1

**Status:** COMPLETE — merged (PR #529, `53f01a72`), deployed to the production pilot,
verified, rules synced, GO tagged (`security-doctor-webauthn-device-bound-fail-closed-1-go`).
A real-tablet WebAuthn ceremony after deploy was **not** performed and is not claimed
(§8.4).

**Type:** `SECURITY_FIX` (P0 hardening). **Base:** `3ae7a502`
(`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`, one docs-only
commit after the production authority `9748aeb0` / `dev-ai-claude-memory-optimization-1-go`).
**Canonical rule:** `.cursor/rules/208-doctor-webauthn-device-bound-fail-closed.mdc`
(WEBAUTHN-SEC-01..10). **Contract:** `docs/architecture/doctor-pwa-webauthn.md` §19.

No migration, no route, no permission, no seeder, no feature flag, no enforcement-scope
change, no frontend asset.

## 1. Finding

`DEV-AI-CLAUDE-MEMORY-OPTIMIZATION-1` §8 item 1 recorded that
`config/webauthn.php` read `(bool) env('WEBAUTHN_REQUIRE_DEVICE_BOUND', true)`, so a
present-but-empty value turned the device-bound requirement off. Verified against the
source before any change, and widened by the audit to a second, independent defect.

## 2. Root cause

**Defect 1 — configuration (fail open).** Laravel's `env()` returns a present-but-empty
variable as `''`, maps `empty`/`(empty)` to `''` and `null`/`(null)` to null. PHP casts all
of them to false. The runtime reader, `WebAuthnDeviceBinding::isRequired()`, was
`(bool) config(...)`, which reads a null value as false as well. Three further readers (the
readiness command and two enrolment controllers) re-cast the key themselves and could
disagree with enforcement.

Reproduced on the unmodified base with the real config file evaluated under a controlled
environment: exactly five spellings disabled the requirement — `""`, `empty`, `(empty)`,
`null`, `(null)`. Whitespace, `off`, `no` and typos resolved to required (they are truthy
strings), which is why the fix must not start reading them as a relaxation.

**Defect 2 — authenticator data (fail open).** `web-auth/webauthn-lib` 5.3.8 verifies the
assertion signature and, when the signed BE bit differs from the credential record,
dispatches `BackupEligibilityChangedEvent` and continues
(`AuthenticatorAssertionResponseValidator` lines 76–96). DaengtisiaMS wires no dispatcher
(the library's no-op default), and both login services judged only the verdict stored at
registration. A registration with `attestation: none` carries no attestation signature, so
that stored BE is the client's word; the assertion's BE is covered by the credential's own
signature but was never read. W3C WebAuthn Level 3 (Recommendation, 25 Aug 2026) §7.2:
*"If the credential backup state is used as part of Relying Party business logic or
policy … If credentialRecord.backupEligible is not set, verify that currentBe is not set."*
§6.1.3: BE *"MUST NOT change"*. Reproduced on the base: an assertion signed with the genuine
key but reporting BE=1, for a credential enrolled device-bound, logged the doctor in; the
same held on the Front Office path.

Affected authentication paths: doctor WebAuthn login (`DoctorDeviceWebAuthnLoginService`),
Front Office device login (`FrontOfficeDeviceWebAuthnLoginService`), credential registration
(`DoctorDeviceWebAuthnRegistrationService`), the per-request session check
(`DoctorAppLoginGate`), and the live-proof and estate reports that classify credentials
through `isAcceptable()`.

## 3. Threat model (abridged)

| Threat | Before | After |
| --- | --- | --- |
| Blank/`empty`/`null` value disables the requirement | **open** | refused: read as REQUIRED, `--strict` fault |
| Unparseable value (`off`, `no`, typo) coerced to a relaxation | not reachable (truthy) | kept unreachable; reported as invalid |
| Runtime null / string in a cached or hand-edited config | **open** (`(bool) null`) | REQUIRED; reported `runtime_value_invalid_fail_closed` |
| Config cache freezes a relaxed value | **open** (froze `false`) | the cache freezes `true` + provenance |
| Readers disagreeing with enforcement | possible (3 re-casts) | one reader; structural test |
| Signed BE contradicts registration | **accepted** | refused `backup_eligibility_changed` |
| Invalid `BE=0, BS=1` at assertion | refused (library) | refused (pinned by test) |
| Flags tampered in transit | refused (signature) | refused (pinned by test) |
| Unapproved / revoked device, revoked credential, cross-branch | refused | unchanged |
| Fail closed widens who is enforced | n/a | pinned: flags, cohort, scope untouched |
| Fully software authenticator signs BE=0 | open by nature | **residual**: WebAuthn cannot prove key custody |

## 4. Design

One parser, one reader, one assertion check, all in
`App\Modules\DoctorDevice\Support\WebAuthnDeviceBinding`:

- `resolveRequirementFromEnv()` / `classifyRequirementOverride()` — called from
  `config/webauthn.php`, so the answer and its provenance (`require_device_bound_source`)
  are var_exported into the config cache. Relaxes ONLY on a boolean `false` (an unquoted
  `false`/`(false)`), the exact string `0` and the integer 0. Tightening spellings (`true`,
  `1`, `on`, `yes`) are accepted generously; a spelling is only ever read as the safer of
  its meanings.
- `isRequired()` — relaxes only on a genuine boolean `false`.
- `requirementPosture()` — `{required, source, valid}` for reports; enforcement never
  reads it.
- `assertionDenialReason(recorded BE, signed BE)` — called on both assertion paths after
  signature verification and before any write.

`webauthn:readiness` reports `require_device_bound`, `require_device_bound_source`,
`device_binding_config_valid` and a `DEVICE BINDING` summary line; `--strict` fails on an
invalid value. The two enrolment controllers read `isRequired()`.

Separation kept explicit: (1) is enforcement in force for this user — unchanged; (2) is the
security configuration valid — new, reported; (3) does the credential satisfy device
binding — stored verdict **and** signed BE; (4) is the device and user authorized —
unchanged; (5) may authentication continue — only when all hold.

## 5. Files changed

- `app/Modules/DoctorDevice/Support/WebAuthnDeviceBinding.php` — parser, strict reader,
  posture, assertion check.
- `config/webauthn.php` — the requirement and its provenance through the parser.
- `app/Modules/DoctorDevice/Services/DoctorDeviceWebAuthnLoginService.php`,
  `app/Modules/FrontOfficeDevice/Services/FrontOfficeDeviceWebAuthnLoginService.php` —
  signed-BE check.
- `app/Console/Commands/DoctorDeviceWebAuthnReadinessCommand.php`,
  `app/Modules/DoctorDevice/Controllers/DoctorDeviceWebAuthnController.php`,
  `app/Modules/DoctorDevice/Controllers/DoctorDeviceRegistrationWorkflowController.php` —
  read through `WebAuthnDeviceBinding`.
- `tests/Feature/DoctorDeviceWebAuthn/DoctorPwaWebAuthnDeviceBoundFailClosedTest.php` (new).
- `tests/Support/FakeWebAuthnAuthenticator.php` — per-assertion BE/BS overrides.
- `tests/Pest.php` — `ffBuildRegistry()` takes an optional config file (extended, not
  duplicated).
- `config/ci_runner.php` — suite declared mandatory.
- Rules and knowledge base: `.cursor/rules/208-…`, rule 93 §3 pointer,
  `.claude/rules/doctor-device-android.md` (AIR-DDA-19 corrected, AIR-DDA-42 added, index
  row), `docs/architecture/doctor-pwa-webauthn.md` (§2, §7, §19), this record,
  `.sprint/current.yml`.

## 6. Independent security review

An independent reviewer (separate agent, read-only, given the code and questions rather
than conclusions) found no CRITICAL, HIGH or MEDIUM issue. Every finding was verified
against the source before it was acted on.

| # | Finding | Severity | Disposition |
| --- | --- | --- | --- |
| R1 | First draft relaxed on the STRINGS `'false'`/`'(false)'`. `Env::get` maps a bare `false` to boolean before stripping quotes (`Env.php:256–272`), so a literally quoted `'false'` (e.g. a container env-file) arrives as the string — which `(bool)` read as true. A widening. | LOW (not in production: variable absent) | **Fixed.** Relaxes only on boolean `false`, integer 0, exact string `'0'`; quoted `'false'` is REQUIRED + invalid. Dataset cases added. |
| R2 | A runtime `false` with no recorded provenance passed `--strict` as `unrecorded`. | LOW | **Fixed.** `valid` is false for an unexplained relaxation. Test added. |
| R3 | Docblock overstated "every consumer": three consumers ignore the setting and always demand device-bound. | INFO | **Fixed** wording (class, rule 208); disagreement is only ever stricter, by design. |
| R4 | `device_binding_policy` named both the stored-verdict and the signed-BE refusal. | INFO | **Fixed.** Signed refusal is `asserted_device_binding_policy`. |
| R5 | Placement of the signed-BE check after `check()`, flags read from signed bytes, no write before the check, `deny()` typed `never`, null BE handled. | — | Verified, no issue. |
| R6 | No reader bypasses the helper; no widening of who is enforced; no lockout of a consistent device-bound credential; no leak of the raw value; no uncovered login path (Android makes no BE decision; per-request gate goes through `isAcceptable()`). | — | Verified, no issue. |
| R7 | Tests: two library pins pass without the fix; the "one reader" scan missed double quotes / block reads / views; the "every path" scan did not pin order; one registration test did not assert its reason; one test misnamed; missing cases (null recorded BE under both policies; Front Office tamper/order). | LOW | **Fixed.** Pins labelled as pins; scans widened (quotes, block reads, facade; app, views, routes, bootstrap) and ordered (verify < check < acted-on < write); reason asserted; renamed; cases added — including the inconsistent row (verdict recorded, BE column null) that is the only way to reach the signed-BE *policy* rule. |

## 7. Tests and quality gates

Local runs use SQLite in one test process (CG-20); PostgreSQL 16 coverage comes from the CI
critical gate. A throwaway test-only application key is supplied in the process environment
because no local environment file exists (CI provides its own).

**Reproduction on the unmodified base (`3ae7a502`)** — the new suite run against the original
application code: 15 FAILURE / 32 ERROR / 8 PASS. The behavioural failures are the two
defects: five spellings resolved the requirement to `false` (`""`, `empty`, `(empty)`,
`null`, `(null)`); a blank cached requirement registered a syncable credential; a
genuinely signed BE-flip assertion logged in on both the doctor and Front Office paths;
a blank requirement kept a syncable session alive. The errors are calls to the new API.

**After the fix** — `DoctorPwaWebAuthnDeviceBoundFailClosedTest`: **65 passed, 181
assertions**, 0 failed, 0 skipped.

**Mutation** — 13 mutants, each applied once, run against the suite, reverted by copy and
verified byte-identical by sha256: **13 killed, 0 survived, 0 not applied**.

| Mutant | Tests failing |
| --- | --- |
| M1 config back to `(bool) env()` | 10 |
| M2 runtime `(bool) config()` | 8 |
| M3 doctor signed-BE check disabled | 4 |
| M4 Front Office signed-BE check disabled | 2 |
| M5 consistency rule removed | 3 |
| M6 policy on signed BE removed | 1 (the inconsistent-row test added from review R7) |
| M7 `off`/`no` accepted as relaxation | 3 |
| M8 padded / quoted `false` accepted as relaxation | 4 |
| M9 blank read as relaxation | 9 |
| M10 readiness re-casts the config | 2 |
| M11 `--strict` ignores validity | 1 |
| M12 controller re-casts the config | 2 |
| M13 unexplained runtime relaxation reported valid | 1 |

**Dependency-scoped regression (pre-review code)** — `tests/Feature/DoctorDeviceWebAuthn`,
`DoctorDevice`, `FrontOfficeDevice`, `DoctorAccess`, `Pwa`, `Auth`,
`FeatureFlagRuntimeOverrideTest`, `DevflowAiInstructionSystemTest`: 1573 passed, 1 failed.
The one failure was this sprint's own domain-rule edit (an invariant numbered out of order);
fixed by folding it into AIR-DDA-19.

**Dependency-scoped regression (final code, after review)** — the same suites plus
`DevflowSprintToolingTest`, `LegacyRmeProgramClosureContractTest` and
`CriticalGateSuiteCoverageTest` (which proves the new mandatory suite is selected by both
runner variants' filters): **1640 passed, 0 failed, 0 skipped, 15,693 assertions**, 725 s
(2026-10-26 11:04–11:16 UTC, 19:04–19:16 WITA).

**DEVFLOW** — `devflow:base-ref-check --strict` GO, `sprint:manifest-check` GO,
`sprint:scope-audit --strict` GO (2 modules: DoctorDevice, FrontOfficeDevice),
`foundation:devflow-check --strict` GO, `foundation:shared-service-audit --strict` GO.
`foundation:ci-runtime-control-check` and `foundation:security-compliance-check` cannot run
locally (no migrated database: they fail on ENT-5…ENT-10 queue, health and developer-console
tables, none WebAuthn-related) and are taken from CI and the deploy's own gate phase.

**Full Suite:** DEFERRED by the GLOBAL TEMPORARY FULL-SUITE POLICY (CG-18). Not run, not
claimed.

**CI on the exact candidate** — run `40314431929`, head `28d5604e`, conclusion **success**:
CICD-CTRL Classifier (profile `unknown_high_risk`, the strongest), NSF-R012 Quality, NSF-R011
Critical (one runner variant ran, the other skipped by design), CICD-CTRL Selective Module,
Phase 3 Android Clinic App, NSF-9 Release Safety & Smoke, NSF-10 Release Evidence. The critical
step log was read rather than the check mark: `critical_test_exit_status=0`, **6781 passed
(37,400 assertions)** on PostgreSQL 16, and `DoctorPwaWebAuthnDeviceBoundFailClosedTest`
appears as PASS with **65 tests, 0 failed**. NSF-R011 Full Suite Gate: skipped by policy.

## 8. Production

### 8.1 Pre-deploy posture (read-only, 2026-10-26, as `daengtisiams`)

| Item | Measured |
| --- | --- |
| Host / app path | `srv1730088`, `/var/www/asia-dental-lab-v2` |
| HEAD | `9748aeb0` (`dev-ai-claude-memory-optimization-1-go`), tree `6b6036c6` |
| `WEBAUTHN_REQUIRE_DEVICE_BOUND` in the environment file | ABSENT (classified on the host; value never printed) → effective `true` |
| `WEBAUTHN_USER_VERIFICATION`, `WEBAUTHN_ALLOW_INSECURE_LOCALHOST` | ABSENT → `required`, `false` |
| Credentials usable / device-bound | 7 / 7 |
| Active devices | 9 |
| `doctor.trusted_device_enforcement`, `doctor.pwa_webauthn_device_login` | ON (env) |
| `doctor.single_active_session` / `doctor.branch_lock` | ON / OFF |
| `front_office.branch_device_lock` | OFF (Front Office WebAuthn path inactive) |
| Enforcement scope | `pilot`, covered users `[9, 15, 18]`, `global_enforcement_active=false` |
| Last qualifying WebAuthn assertion | 2026-10-17 07:22 UTC (15:22 WITA); 0/9 devices fresh (6 STALE, 3 NEVER_PROVEN) |

Impact matrix before deploy:

| Population | Effect of this change |
| --- | --- |
| Pilot doctors `[9,15,18]` on a device-bound credential | none: BE=0 registered and BE=0 asserted is accepted |
| Pilot doctors on the Android app | none: the Keystore path makes no BE decision and is untouched |
| Doctors outside the cohort (browser login) | none: enforcement scope unchanged |
| Front Office accounts | none: their device lock is OFF |
| Open sessions | none: per-request check reads the stored verdict, all device-bound |
| A credential whose authenticator signs a BE change | refused at login (new) — none known; no assertion BE was ever recorded |

The deploy changes no configuration: the variable stays absent, so the resolved value is
`true` before and after.

### 8.2 Merge

PR #529 squash-merged as `53f01a721bfae34c38f8b1c9c8179561fc881110`. Merge tree `fec41ea5`
is identical to the CI-validated candidate tree; the base had not moved (`3ae7a502`).

### 8.3 Deployment

Executed on the VPS with `bash scripts/deploy-vps-runner.sh start` from
`/var/www/asia-dental-lab-v2` (never from a workstation).

| Item | Evidence |
| --- | --- |
| Start / end | 2026-10-26 14:18:04 UTC → status written 14:38:06 UTC (22:18–22:38 WITA) |
| Runner | detached pid 313824; status `exit=0`; log `DEPLOY OK: 20261026-141806` |
| Lock / pin | `DEPLOY_LOCK_ACQUIRED=YES`, `DEPLOY_TARGET_PINNED=53f01a72…` |
| Execution source | trusted immutable snapshot under `/run/daengtisiams-deploy` (`DEPLOY_SNAPSHOT_TRUSTED=PASS`), cleaned afterwards |
| Backup | `storage/app/backups/deploy/pre_auto_deploy_20261026-141806.sql`, 32,289,407 B, `0640 daengtisiams` |
| Migrations | `Nothing to migrate` (`migrate --force` only) |
| Gates | writable-path gates GO, secret-file permissions GO, runtime isolation 70 GO / 0 FAIL / 0 SKIP, auth-landing smoke GO |
| Smoke | NSF-9 command readiness GO; HTTP probe WATCH only for `http://127.0.0.1/login` → 404, the known co-tenant loopback shadow (CG-14); the canonical domain returns 200 |
| HEAD | `DEPLOY_HEAD_TARGET_MATCH=YES`; HEAD `53f01a72`, tree `fec41ea5`, tracked tree clean |
| Rollback | `bash scripts/rollback-vps.sh dev-ai-claude-memory-optimization-1-go` (previous GO, `9748aeb0`) + the backup above if ever needed |

### 8.4 Production verification (read-only, as `daengtisiams`, 2026-10-26)

| Check | Result |
| --- | --- |
| `https://daengtisia.online` `/login`, `/health/live`, `/health/ready`, `/health/lb` | 200 ×4 |
| Guest `/doctor-device-webauthn`, `/front-office-device-webauthn` | 302 (no 500) |
| Cached config `webauthn.device_binding` | `require_device_bound=true`, `require_device_bound_source=default` |
| `webauthn:readiness --report-only` | REQUIRED (default), `device_binding_config_valid=true`, RP usable, UV `required`, 7/7 credentials device-bound, verdict ARMED |
| `webauthn:readiness --strict --report-only` | exit 0 |
| Fix present | `assertionDenialReason(` once in each login service; resolver in the config; no `(bool) env(` for the key |
| Enforcement scope | `pilot`, covered `[18, 15, 9]`, `global_enforcement_active=false`, `global_scope_permitted=false` — unchanged |
| Flags | `doctor.trusted_device_enforcement` ON, `doctor.pwa_webauthn_device_login` ON, `doctor.single_active_session` ON, `doctor.branch_lock` OFF, `front_office.branch_device_lock` OFF, `front_office.branch_context_lock` ON — unchanged |
| Application log | 1,442,282 B / 163 ERROR before and after: **0 new errors** |
| Failed jobs | 0 |
| Runtime | env `pilot`, debug OFF, maintenance OFF; php8.3-fpm, nginx, queue worker active |

**Not performed, not claimed:** a real-tablet WebAuthn login after deploy. The last qualifying
production WebAuthn assertion was 2026-10-17 07:22 UTC; nothing in production history records
the assertion-time BE bit, so the new check's effect on real hardware is unmeasured. It can only
refuse an authenticator that contradicts its own registration. After the next real PWA login by
a pilot doctor, confirm it with a read-only look at `sys_audit_logs` for
`DOCTOR_DEVICE_WEBAUTHN_LOGIN_SUCCESS`, and for any `DOCTOR_DEVICE_WEBAUTHN_LOGIN_REJECTED` whose
reason is `backup_eligibility_changed` or `asserted_device_binding_policy`. Recovery if one
appears: the Android Clinic App path (unaffected), break-glass, or enrolling a conforming
credential; never relax the requirement.

### 8.5 Rules and knowledge base

- Canonical rule `.cursor/rules/208-doctor-webauthn-device-bound-fail-closed.mdc`; every one of
  its path globs matches tracked files (checked). Rule 93 §3 points to it.
- Domain rule `.claude/rules/doctor-device-android.md` AIR-DDA-19 rewritten (it described the
  fail-open as current drift) and loads on `config/webauthn.php`; the canonical index lists 208.
  `DevflowAiInstructionSystemTest` enforces numbering, path scope, size and references.
- Contract `docs/architecture/doctor-pwa-webauthn.md` §2, §7 and §19.
- `CLAUDE.md` unchanged (CG-24).
- Obsidian vault `/home/fikri/Projects/daengtisiams-kb-vault` refreshed by detached checkout
  to `53f01a72`; still detached, locked, clean; Sync OFF.

### 8.6 GO tag

`security-doctor-webauthn-device-bound-fail-closed-1-go` — annotated, tag object `accdaa39`,
points at the deployed `53f01a72`; verified locally, on `origin`, and on the VPS
(`git describe --tags --exact-match HEAD`). This evidence commit comes after the GO commit and
is not deployed (CG-16).

## 9. Risks and future improvements

- Real-hardware effect of the signed-BE check is unmeasurable from production history: the
  library's event was never dispatched to anything, and no audit row records the assertion
  BE. A genuine single-device authenticator never changes BE, so a doctor refused with
  `backup_eligibility_changed` indicates a non-conforming authenticator; recovery is the
  existing Android app path, break-glass, or re-enrolment of a conforming credential.
- `WEBAUTHN_ALLOW_INSECURE_LOCALHOST` uses the same `(bool) env()` shape (`no` reads as
  true). Not a production bypass — `isPermittedInsecureHost()` refuses it in `production`
  and `pilot` regardless — and left out of this minimal patch.
- Other `(bool) env()` security knobs outside WebAuthn (e.g. `require_separate_approver`)
  are recorded in `DEV-AI-CLAUDE-MEMORY-OPTIMIZATION-1` §8 and are not in scope.
