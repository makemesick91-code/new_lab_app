# LEGACY-RME-PROGRAM-CLOSURE-1 — Final Program Audit, Foundation Revalidation & Production Steady-State Closure

**Status: `COMPLETE / GO`**
**Programme: `LEGACY RME — FULLY CLOSED / PRODUCTION STEADY-STATE`**

This is the closing record of the Legacy RME engineering programme. It is an
audit, not a feature sprint: it adds no clinical capability, no migration, no
schema change and no patient-data correction. Its job is to prove that what was
already built is coherent, production-safe, operationally sustainable, and
ready to be formally closed.

```
ENGINEERING_ROLLOUT_MODE = CLOSED
STEADY_STATE_OPERATIONS  = AUTHORITATIVE
FUTURE_ROUTINE_BATCHES   = OPERATIONAL
```

---

## 1. Authority

Base resolution followed DEVFLOW-FIX-BASE-REF-1: the canonical **remote** SHA is
the authority, and the local checkout was proven stale and discarded.

```
BASE_SOURCE       = origin (canonical remote)
BASE_BRANCH       = feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report
BASE_SHA          = f246cb31e699cfb2901fe82b5d5f94ed2e2290da
REMOTE_HEAD       = f246cb31e699cfb2901fe82b5d5f94ed2e2290da
LOCAL_BASE_SHA    = f47735acce9b855c2b6a02d8f36655888845d24d
LOCAL_BASE_STALE  = YES  (one commit behind — not used)
VPS_PRE_HEAD      = f246cb31e699cfb2901fe82b5d5f94ed2e2290da
```

Production was already running the exact canonical base, tagged
`legacy-rme-doctor-workspace-1a-inline-legacy-pdf-pages-handwritten-rme-swipe-canvas-go`.

A fresh worktree was created pinned to `BASE_SHA`. Discovery was deliberately
not performed from the primary checkout, which was parked on an unrelated CI
branch (`ci-evidence/cicd-ctrl-3-db-guard-matrix`).

---

## 2. Foundation authority ledger

All 27 programme GO tags were resolved and compared local-vs-remote. **Every tag
peels identically on both sides and every one is an ancestor of the canonical
base.** No historical tag was moved, forced or rewritten.

| Foundation | GO tag | GO SHA | Revalidation |
|---|---|---|---|
| 1A schema / date / permission | `legacy-rme-pdf-1a-schema-permission-date-rules-go` | `0b37ce4b` | PASS |
| 1B private upload / rendering | `legacy-rme-pdf-1b-private-upload-rendering-go` | `6b9ed7aa` | PASS |
| 1C controlled publish / history | `legacy-rme-pdf-1c-controlled-publish-patient-history-go` | `cee66f75` | PASS |
| 1D VOID / clinical read / print | `legacy-rme-pdf-1d-void-clinical-read-print-go` | `61806ffe` | PASS |
| HISTORY-1 patient-centric history | `legacy-rme-pdf-history-1-…-go` | `7963fec7` | PASS |
| HISTORY-1A read/write separation | `legacy-rme-pdf-history-1a-…-go` | `781b43e6` | PASS |
| HISTORY-1B doctor branch context | `legacy-rme-pdf-history-1b-…-go` | `c947b94d` | PASS |
| ROLL-1 flag / runtime readiness | `legacy-rme-pdf-roll-1-…-go` | `9e90c7bb` | PASS |
| ROLL-2 controlled pilot enablement | `legacy-rme-pdf-roll-2-…-go` | `3e9a06d9` | PASS |
| FIX-ROLL2-1 eligibility / multidate / RM branch | `legacy-rme-pdf-fix-roll2-1-…-go` | `f3730111` | PASS |
| ROLL-3 multi-branch controlled migration | `legacy-rme-pdf-roll-3-…-go` | `6d4850f4` | PASS |
| ROLL-4 migration operations scale-up | `legacy-rme-pdf-roll-4-…-go` | `88e880b4` | PASS |
| ROLL-4-WAVE-1 controlled production wave | `legacy-rme-pdf-roll-4-wave-1-…-go` | `2ffe00c1` | PASS |
| ROLL-4-WAVE-2 controlled production wave | `legacy-rme-pdf-roll-4-wave-2-…-go` | `45c7d11b` | PASS |
| **ROLL-4-WAVE-3** | **NONE — and none is required** | — | **SKIPPED / NOT REQUIRED** |
| OPS-CLI-1 lifecycle CLI / abort recovery | `legacy-rme-ops-cli-1-…-go` | `3cf57ea7` | PASS |
| SOD-1 mandatory separate publisher | `legacy-rme-sod-1-…-go` | `9c803fe5` | PASS |
| MASTERDATA-1 RM 27541 integrity | `legacy-rme-masterdata-1-…-go` | `431d5af9` | PASS |
| SOURCE-RM-BINDING-1 patient binding | `legacy-rme-source-rm-binding-1-…-go` | `32342650` | PASS |
| STEADY-STATE-OPS-1 routine operations | `legacy-rme-steady-state-ops-1-…-go` | `c5e9fe6f` | PASS |
| DOCTOR-WORKSPACE-1 embedded PDF | `legacy-rme-doctor-workspace-1-…-go` | `60f5e890` | PASS |
| DOCTOR-WORKSPACE-1A inline pages | `legacy-rme-doctor-workspace-1a-…-go` | `f246cb31` | PASS |
| DATE-TZ-1 clinical timezone | `legacy-rme-date-tz-1-…-go` | `b8038c56` | PASS |
| INFRA-SEC-ENV-1 secret file permissions | `infra-sec-env-1-…-go` | `19d18a41` | PASS |
| INFRA-SEC-RUNTIME-1 runtime identity | `infra-sec-runtime-1-…-go` | `acf1e224` | PASS |
| DEPLOY-HARDEN-1 immutable entrypoint | `deploy-harden-1-…-go` | `b11bbbcf` | PASS |
| DEVFLOW-FIX-BASE-REF-1 base resolution | `devflow-fix-base-ref-1-…-go` | `6089cbb3` | PASS |
| CICD-BASELINE-REVERIFY-1 baseline zero | `cicd-baseline-reverify-1-…-go` | `126ea761` | PASS |

---

## 3. Product invariants — revalidated in code

Each invariant was verified against the delivered source at `f246cb3`, not
against prose. Full citations are in the audit trail; the verdicts:

| # | Invariant | Verdict |
|---|---|---|
| A | Archive boundary — never creates native clinical state | **PASS** |
| B | Source RM binding — exact-unique, no fuzzy matching | **PASS** |
| C | Branch authority — RM-derived, fails closed, no override | **PASS** |
| D | Date rules — strict `<` both bounds, ClinicalClock | **PASS** |
| E | Separation of duties — account-level, Super Admin not a bypass | **PASS** |
| F | Published immutability — VOID, never edit | **PASS** |
| G | Doctor read scope — treating relationship ∧ practice branches | **PASS** |
| H | Private PDF — policy-gated stream, no public URL | **PASS** |
| I | Inline workspace — one sequence, legacy read-only by construction | **PASS** |
| J | Ops CLI — thin adapter over the canonical service | **PASS** |
| K | Architecture — Controller → Service → RepositoryInterface | **PASS** (1 minor deviation, §7) |

Selected proofs worth recording because they are the ones most likely to be
quietly weakened later:

- **Archive boundary.** `LegacyRmePublishService` writes only
  `trx_rme_legacy_records`, `trx_rme_legacy_record_pages` and the two staging
  tables. A module-wide scan found no `Odontogram`, `Prescription`,
  `RmeInvoice`, `RmePayment`, `LabOrder`, `LabCaseCandidate` or `Satusehat`
  symbol anywhere in `app/Modules/LegacyRme/`. The only `ClinicVisit` /
  `MedicalRecord` references are reads.
- **No fuzzy matching on the write path.** `levenshtein()` appears exactly once
  in the module, inside the read-only CLI diagnostic, on rows explicitly
  stamped `bindable => false`. The binding service refuses unless
  `count($matches) === 1`.
- **Strict cutoff.** Both bounds are expressed as `! $latest->lessThan(...)`,
  so equality *fails* — the overlap case is rejected, not admitted.
- **SOD cannot be bypassed.** `SeparatePublisherGuard::violates()` is a pure
  account-id comparison with no role, `Gate` or permission call, so the global
  Super Admin `Gate::before` cannot reach it. It runs inside the transaction,
  under the row lock, *before* the idempotency short-circuit.
- **Immutability.** `update` and `delete` are hard-wired `false` on the policy,
  and `LegacyRmeRecordRepository` exposes no update method at all — `markVoided`
  is the single permitted state change.
- **Doctor scope has no fallback.** The doctor path short-circuits to
  `DoctorClinicalBranchResolver`, which intersects practice branches with active
  RME-enabled branches and returns `[]` for a missing/inactive doctor master.
  An empty list denies everything. The listing path applies the same gate as the
  viewer, so the timeline is not a weaker door.
- **Legacy pages are read-only by construction.** `RmeWorkspacePage::legacy()`
  hard-codes `readonly: true` — it is not a parameter. The legacy partial
  contains no `<canvas>` and no `<form>`.

### Date-bound hardening — audited, resolved as safe

The audit flagged that the three date switches in `config/legacy_rme.php` are
plain booleans rather than fail-closed resolvers like the SOD flag. This was
run down rather than accepted:

- production reports all three `true`, plus `cutoff_invariants.require_medical_record = true`;
- the config values are **plain literals with no `env()` call**, and production
  carries **zero** environment overrides for them.

There is therefore no runtime surface on which to weaken the cutoff — only a
reviewed code change could, which is exactly the control we want. That property
(the *absence* of env plumbing) is now pinned by
`LegacyRmeProgramClosureContractTest` so it cannot be introduced silently.

Production is also **stricter than the repository default**: it enforces
`require_separate_approver = true` in addition to `require_separate_publisher`.

---

## 4. Production steady state

Captured on `srv1730088` before and after the synchronization deployment.

```
APP_ENV            = pilot
APP_DEBUG          = OFF
MAINTENANCE        = OFF
Clinical timezone  = Asia/Makassar (canonical)
Clinical date      = 2026-08-18

SOD separate publisher = true
SOD separate approver  = true

CAPABILITY   = OFF
ADMISSION    = EMPTY  (no branch admitted)
ACTIVE_BATCH = NONE   (no wave registered)

legacy-rme:rollout-readiness = GO
legacy-rme:ops-readiness     = GO / AT_REST / READY_FOR_ROUTINE_BATCH = YES

/login 200 · /health/live 200 · /health/ready 200 · /health/lb 200
nginx active · php8.3-fpm active · queue worker active · failed jobs 0
```

`READY_FOR_ROUTINE_BATCH = YES` means *safe to begin with a fresh approval*. It
does **not** mean a migration is currently open — none is.

All four RME branches (ATG3, LDK2, SUN4, TKM1) report `NOT_READY` because none
is approved or admitted. That is the closed, pre-wave resting state, not a
defect.

---

## 5. Governance gates

Run in the closure worktree. All read-only.

| Gate | Result |
|---|---|
| `foundation:devflow-check --strict` | GO |
| `foundation:shared-service-audit --strict` | PASS (exit 0) |
| `foundation:ci-runtime-control-check --strict` | GO |
| `foundation:security-compliance-check` | GO |
| `foundation:cicd-enterprise-gate-check` | GO |
| `foundation:enterprise-documentation-check` | GO |
| `foundation:enterprise-closure-check` | GO |
| `foundation:deployment-entrypoint-check` | GO |
| `foundation:roadmap-check --strict` | GO |
| `architecture:ui-governance-check --strict` | PASS (exit 0) |

> **Environment note worth recording.** On first run every DB-touching gate
> failed with a Postgres authentication error. That was **not** a code or
> governance failure: the fresh worktree's `.env` had been seeded from the
> example file and carried no local credentials. Once wired to the local dev
> database all ten gates returned GO. A gate that fails loudly on an
> unconfigured environment is behaving correctly.
>
> The same effect explains why `legacy-rme:ops-readiness` and
> `legacy-rme:rollout-readiness` return NO_GO *locally* — the dev laptop has no
> legacy tables, no seeded permissions, no approved pilot scope and no backups.
> Both are GO on production, where those things exist. **These commands fail
> closed on an unprepared deployment, which is the designed safety behaviour.**

---

## 6. Residual inventory

Every canonical source — `CLAUDE.md`, 44 `.cursor/rules/*.mdc`,
`docs/sprints/*`, `docs/runbooks/*`, `docs/adr/*`, `.sprint/current.yml` — was
swept for residual markers and each hit classified.

```
OPEN_BLOCKERS = 0
```

| Classification | Count |
|---|---|
| BLOCKER | **0** |
| NON_BLOCKING_KNOWN_LIMITATION | 8 |
| SEPARATE_FUTURE_WORKSTREAM | 4 |
| HISTORICAL_ONLY | ~55 |
| RESOLVED_STALE_TEXT | 0 |

The ~55 historical items are past-tense evidence inside immutable sprint
records, each already superseded by a later GO (for example the Wave-2 WATCH
attempts superseded by the `WAVE-2R` GO). They are evidence, not open work, and
were deliberately left untouched.

Two candidate residuals were chased specifically and both resolved clean:

- **The retired nine-failure baseline.** Only two occurrences exist repo-wide,
  both explicitly labelled historical. Current guidance asserts `0` in two
  places, and `FullSuiteBaselineContractTest` pins the *mechanism* — that no
  expected-failure allowance token exists in the CI or governance surface.
- **The "Graphify unavailable" claim.** It does not exist anywhere in the
  repository; it lived only in assistant session memory. `CLAUDE.md` already
  carries the retraction. Graphify v0.8.35 was confirmed on PATH and used for
  this closure.

---

## 7. Known non-blocking limitations

Recorded truthfully. None blocks closure.

1. **SOD is account separation, not human separation.** The system proves the
   filing account is not the publishing account. It cannot prove two distinct
   humans; that remains an operational control requiring attestation. Rows with
   `uploaded_by IS NULL` (pre-attribution) are exempt. Both limits are
   documented in the guard's own source.
2. **Architecture deviation.** `LegacyRmeMigrationOperationsController:202,206`
   hydrates `LegacyRmeWaveBranch` and `User` directly rather than through a
   repository. It contains no business logic, is guarded against cross-wave
   IDOR, and affects wave-operator assignment only — never the
   import/publish/read path. Low severity; recorded rather than silently fixed,
   because a closure sprint is not the place to refactor a working control.
3. **Rule-file numbering collisions.** Three rules share the `92-` prefix and
   two share `97-`. Cosmetic only; renumbering would churn cross-references for
   no safety gain, so it was left alone.
4. **Full Suite warning-summary phenomenon.** Classified
   `KNOWN_NON_BLOCKING_CI_OBSERVABILITY_DEBT` — see §9. The cause remains
   unidentified and is deliberately **not** guessed at here.
5. **Wave-2 refusal-on-overflow and pause/resume were not exercised live** in
   the GO run; both rest on existing automated coverage.
6. **Live browser swipe was not exercised** with real doctor credentials.
   Automated coverage plus the production render/presenter path are the
   evidence.
7. **No live routine migration batch was executed** during closure. No genuine
   owner-approved candidate existed, and nothing was fabricated:
   `LIVE_ROUTINE_BATCH = NOT_EXERCISED`.

---

## 8. Separate future workstreams

Real work, owned elsewhere, explicitly **not** held against this closure:

- **Pest warning-downgrade root cause** — CI/test-infra.
- **Patient master-data hygiene (RM 27541 / patient 23)** — the RM 27541
  disposition remains `DOCUMENT_NOT_ELIGIBLE` and was not reopened. No patient,
  RM, visit or paid invoice was touched by this sprint.
- **TKM1 / ATG3 / SUN4 routine migration** — operational events under
  STEADY-STATE-OPS-1, requiring fresh approval. Not engineering backlog.

---

## 9. CI baseline

```
CURRENT_VALID_EXPECTED_FULL_SUITE_FAILURE_BASELINE = 0
```

The historical nine-failure baseline is retired and survives only as historical
evidence. It must never be reintroduced as an allowance — any current
unexpected Full Suite failure fails closed.

The Full Suite output phenomenon (large warning counts alongside a small
"passed" summary while tens of thousands of assertions execute) is classified
**`KNOWN_NON_BLOCKING_CI_OBSERVABILITY_DEBT`**, and only on the conditions the
closure criteria require: canonical CI result `success`, zero failure markers,
expected baseline `0`, and assertion coverage demonstrably present. No cause is
invented here. If evidence ever shows it masking real failures, that is a
blocker and this classification is void.

---

## 10. Zero business side effects

This sprint changes no application behaviour. Production counts were captured
before the synchronization deployment as the attribution baseline:

```
legacy_imports 15 · legacy_records 10 · legacy_record_pages 12
clinic_visits 28 · medical_records 28 · odontograms 28
rme_invoices 21 · rme_payments 30 · lab_orders 13 · satusehat_candidates 3
```

Sprint-attributable delta target for every one of these: **0**. Ordinary clinic
traffic is not attributed to this closure.

---

## 11. What this closure does and does not mean

**Does mean:** every defined programme acceptance criterion is met, no
unresolved blocker exists, and steady-state operational support is established
and authoritative.

**Does not mean:** that no known limitation exists anywhere in DaengtisiaMS.
Section 7 lists the ones that remain, and section 8 the work that continues
under other owners.

Future bugs or features create their own workstreams. They do not retroactively
reopen this programme unless a closure invariant is proven invalid.
