# DMS-RME-REVISION-PROGRAM-1 — RME revision programme: visit route keys and failure-log redaction

- **Programme:** RME revision programme under owner policy `DMS-FULL-DEVELOPMENT-PRODUCTION-TESTING-20261104`
  (rule 221 §7), mission `DMS-RME-REVISION-PROGRAM-1` (P0), 2026-11-05.
- **Status:** IN PROGRESS — updated at each gate below.
- **Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `746150d2` (verified from
  `origin` after fetch; production was at `6f22beb7`, three docs-only commits behind, 0 runtime paths).
- **Branch:** `feature/dms-rme-revision-program-1`.
- **Canonical rule:** `.cursor/rules/222-rme-route-key-constraint-and-failure-log-redaction.mdc`.
- **Work category under the Foundation-First Sprint Lock:** hotfix / security / monitoring (allowed before
  Foundation GO). No feature work.
- **GO tag (declared in `.sprint/current.yml`):** `dms-rme-revision-program-1-go`.

## 1. Governance preflight

### 1.1 Foundation-First Sprint Lock

`docs/architecture/foundation-first-sprint-lock-governance.md` still reads **ACTIVE / LOCKED**, `CLAUDE.md` carries
`FOUNDATION-FIRST LOCK ACTIVE`, and `DevflowAiInstructionSystemTest` pins that wording. Evidence that the
foundation work itself is finished exists — the annotated tag `enterprise-foundation-go` ("ENT-1..ENT-16 complete;
ends the initial Enterprise Foundation Freeze") and several sprint records reporting "Combined Foundation GO" — but
no dedicated foundation-governance update has ever declared FOUNDATION GO for *this* lock or changed its status, and
the lock document says it changes only that way.

Finding: the lock is **canonically active**, not stale wording. It was not bypassed. The owner decided to keep it
(OD-B below), so this programme ran only allowed-category work.

### 1.2 Scope authority

Rule 221 §7 says each RME revision "follows explicitly approved requirements". None were recorded anywhere:
repository (rules, governance, sprint docs, `.sprint/`), GitHub (0 open issues, 0 open PRs), and the owner's private
operations records were searched. The mission brief also named no specific revision and forbade inventing clinical
behaviour. So the programme began with a targeted audit and asked the owner to approve its scope.

### 1.3 Owner decisions (2026-11-05, recorded per CG-22)

| Id | Question | Owner answer |
|---|---|---|
| OD-A | What should this programme implement? | **Fix R-01 + R-02.** Narrow release: tests first, CI, merge, deploy on Biznet via the runner, verify, GO tag. Product decisions stay owner items, not built. |
| OD-B | What should happen to the Foundation-First lock? | **Keep it, record it.** Allowed-category work only; changing the lock stays a separate owner decision. |

### 1.4 DEVFLOW

Run in the mission worktree against a migrated PostgreSQL 16.15 test database (CI's critical-gate engine):
`devflow:base-ref-check --strict`, `sprint:manifest-check`, `sprint:scope-audit --strict`, `sprint:test-plan`,
`foundation:devflow-check --strict` and `foundation:shared-service-audit --strict` all exit 0 (manifest GO, scope GO,
one application module). The canonical remote base won over a stale local ref, as DEVFLOW-FIX-BASE-REF-1 intends.
`sprint:test-plan` fails closed to the full required suite (unmatched paths plus the `access_control` and
`branch_context` categories); under CG-18 that is reported as **deferred**, and a dependency-aware focused
regression ran instead (§6).

## 2. RME revision inventory

Source-of-truth order: code, migrations and tests; then canonical rules; then owner-approved requirements; then
docs; then sprint history. "Already implemented" rows cite the canonical rule that governs them and its tests;
this audit did not re-verify each line by line.

### 2.1 Requires correction (approved, implemented here)

| Id | Domain | Finding | Rule broken | Evidence |
|---|---|---|---|---|
| R-01 | Clinical workflow, cashier | None of the 44 `{clinicVisit}` routes (`rme/visits/*`, `rme/cashier/*`) constrained the segment. On PostgreSQL a non-integer segment raised SQLSTATE 22P02 and a number beyond bigint 22003, so the request answered HTTP 500 with an ERROR line. Four legacy-archive routes had `whereNumber`, which still admits a number beyond bigint. | CG-05 (an out-of-scope or absent record answers 404); CG-14 (an ERROR line holds monitoring at WATCH) | Reproduced on PostgreSQL 16 (6 of 9 non-key segments → 500); production log held three such lines |
| R-02 | Cashier → Lab integration | The post-payment lab-candidate failure warning logged `$e->getMessage()` raw. A QueryException message carries interpolated bindings and PostgreSQL DETAIL rows. | CG-11 (no patient data in logs) | `RmePaymentService` catch block; reproduced with synthetic values |

### 2.2 Already implemented (verified in code during this audit)

| Item | Result |
|---|---|
| `ClinicVisitController::patientVisitOptions` lacked a branch check (recorded in an earlier sprint as "not fixed") | **Fixed already** by AUDIT-RME-CLINIC-VISIT-BRANCH-AUTHORITY-1: requires the registration ability, re-asserted in the service; registrar cross-branch reach is the owner's OD-1 decision. |
| `branchIdsFor(null)` answers every RME branch (recorded residual) | **Not exploitable**: both callers sit behind `auth`, so a null user cannot reach them. Defence-in-depth residual, kept as WATCH. |

### 2.3 Already implemented (governed by canonical rules; not re-verified line by line)

Patient identity and visit registration (rules 100-new-visit, 101-new-visit, 184, 199, 200), consent and print
ownership (100-rme-consent), consent-gated authoring and "Selesai Pemeriksaan" (109, 134, 135), odontogram history and
stabilisation (109, 110, 134), room gate and doctor room isolation with print denial (138), RME print composition
(127), visit detail and invoice UX (185), billing prerequisites, invoice correction, disputes, refunds and Rp0
closure (189, 190 and the 191–217 range), performed treatments (219), legacy RME and odontogram archives (90–106,
111, 131, 132, 168–174), RME reports on the clinic calendar (129). Handwriting, multi-page RM and prescription
behaviour are recorded in the archived sprint records (`docs/sprints/claude-md-archive/01-rme-clinical.md`), not in
a numbered rule.

### 2.4 Requires an owner product decision (not built)

- Rp0 **issuance** (D21g/D22), discount policy (D21c), vouchers, `paid_at` backdating (D24).
- A correction that settles a PARTIAL invoice leaves no LUNAS receipt; whether a pre-payment approval reference
  becomes mandatory.
- Invoice-correction pilot closure (owner case approval, human separation), post-payment correction activation
  (PP-10), refund settler designation, Rp0 closure flag activation, NO_CHARGE blocking by doctor evidence (staged).
- Doctor-device: Half B apply, `branch_lock` re-arm, tablet policy.
- Patient master-data branch scoping; the typed NIK on the edit form (recorded open finding).
- Per-person branch binding for unpinned front-desk accounts.
- Background services (queue worker, scheduler, KTP timer) and clinic reopening.

### 2.5 Blocked by an external dependency

WhatsApp prescription delivery (Meta credentials and an approved template); SATUSEHAT submission (off by policy).

### 2.6 Recorded follow-up (same defect class as R-01; needs its own approval)

Other integer-bound RME route parameters are equally unconstrained: `rmeInvoice` (15 routes), `medicalRecord` (7),
`rmePrescription` (4), `consent` (3), `odontogram` (3) and others. The five `oversight/rme/visits/{visit}` routes
keep `whereNumber('visit')` with an `int $visit` controller signature, so a number beyond the integer range still
fails there (found by the independent review, §5). The owner approved R-01 for `{clinicVisit}`; extending it is
mostly one line in `DatabaseRouteKey::CONSTRAINED_PARAMETERS` plus tests.

### 2.7 Static-analysis blind spot (architecture note)

The lab hook resolves `RmeLabIntegrationService` through `app()` with no import, so the code graph shows **no
path** from `RmePaymentService` to it (`graphify path` returns none). The R-02 test therefore binds its failing stub
through the container, the same seam production uses.

## 3. Dependency map

| | R-01 | R-02 |
|---|---|---|
| Files | `app/Support/Routing/DatabaseRouteKey.php` (new), `app/Providers/AppServiceProvider.php`, `routes/web.php` | `app/Modules/RmeInvoice/Services/RmePaymentService.php` |
| Reaches | every `{clinicVisit}` route (44): visit detail, RM, odontogram, consent, prescription, room, transition, print, cashier billing, payment, receipts, corrections, disputes, refunds, Rp0 closure, legacy archive | the lab hook after `pay()`, control-visit and visit-allocation payments (6 call sites, one private method) |
| Depends on | Laravel global route patterns; `route:cache` compiling `wheres` | `Observability\Support\TelemetryRedactor` → `SensitiveValueMasker` |
| Schema / permission / flag | none | none |
| Deploy | route cache rebuilt by the runner; no migration, no seed | none beyond the code |
| Conflict risk | `routes/web.php` is a hot file | `RmePaymentService` constructor (container-resolved only; never constructed by hand) |

R-01 and R-02 are independent and share no code. Both were implemented sequentially in one worktree.

## 4. Implementation

- **R-01.** `DatabaseRouteKey::PATTERN = '[1-9][0-9]{0,17}'` (1–18 digits, no sign, no leading zero; 18 nines is
  below the bigint ceiling, so PostgreSQL can always cast an accepted value). `register()` runs first in
  `AppServiceProvider::boot()`, before the route files load. The four `->whereNumber('clinicVisit')` overrides were
  removed: a route-level constraint overrides the global pattern, and `[0-9]+` still let a 20-digit number through.
  A non-key segment now answers 404 before any middleware, binding or query runs. That includes guests, who used to
  be redirected to login for a URL that cannot name a visit.
- **R-02.** The warning logs `exception` (the class name) and
  `error` = `TelemetryRedactor::exceptionMessage($e)`, which drops the ` (Connection: …, SQL: …)` tail, the `DETAIL`
  and `Failing row contains` lines, quoted literals, NIK/phone digit runs, Nomor RM and dates, and keeps the
  driver's error text with its SQLSTATE. Failure handling is otherwise unchanged: the payment is still never rolled
  back.

## 5. Security review

An independent reviewer read the diff (not this record's conclusions), statically and with a read-only application
boot. **No CRITICAL, HIGH or MEDIUM finding.** Confirmed with evidence:

- `AppServiceProvider::boot()` always runs before the route files load (the route provider is registered in a
  `booting` callback and loads routes in its `booted` callback), so `Router::addWhereClausesToRoute` applies the
  pattern at definition time. All 44 `{clinicVisit}` routes carry it, including the resource routes.
- Live and compiled (`route:cache`) collections agree on 17 edge segments: `1`, 18 nines and `%31` match; `0`, `01`,
  19 digits, bigint max, `1%0A`, fullwidth `１`, `+1`, leading or trailing encoded space, `-1`, `1.0`, `1e3` and a
  23-digit value answer 404.
- No `Route::bind`, group `where` or remaining per-route constraint overrides the parameter; URL generation does not
  validate `wheres`; no previously shadowed route becomes reachable.
- The redaction is complete for every real throw site under `RmeLabIntegrationService::generateForPaidInvoice`
  (a ValidationException reduced to field keys; the insert, whose free-text field can only appear in the SQL tail or
  a DETAIL row, both cut). The exception class name is safe to log; no external error-tracking package is installed.
- The constructor change is safe: `RmePaymentService` is only container-resolved.
- Authorization and branch isolation are unchanged. A guest now gets 404 instead of a login redirect for a malformed
  id, which reveals nothing.

Two LOW findings, both handled:

1. **Test flaw, fixed.** The R-02 test forbade the substring `4242` anywhere in the log context, which includes
   `rme_invoice_id`; PostgreSQL sequences survive `RefreshDatabase`, so a long run could fail with no leak. The scan
   now covers only the fields the failure contributes, and the context's key set is pinned.
2. **Same defect, out of approved scope, recorded.** `oversight/rme/visits/{visit}` (5 routes) keeps
   `whereNumber('visit')` with an `int $visit` signature; a number beyond the integer range still fails there. Added
   to the follow-up list (§2.6).

INFO notes acted on: a test name that claimed "any failure message" was corrected (the redactor cannot recognise an
unquoted free-text name; rule 222 §2.5 now states that limit), and a trivially true assertion was replaced with the
fullwidth-digit and `+1` cases.

## 6. Tests

All local runs used a throwaway PostgreSQL 16.15 container (CI's critical-gate engine) unless stated; counts are
from JUnit, so a skip would show as a skip.

| Run | Tests | Failed | Errors | Skipped | Assertions |
|---|---|---|---|---|---|
| Red, before any production change (both new suites) | 27 | **19** | 0 | 0 | — |
| New suites after the fix, PostgreSQL | 29 | 0 | 0 | 0 | 142 |
| New suites after review fixes, PostgreSQL and SQLite (each) | 29 | 0 | 0 | 0 | — |
| Regression batch 1: DEVFLOW focused filters over AccessControl, Branch, Inventory, RME (233 files) | 3,058 | 0 | 0 | 0 | 12,685 |
| Regression batch 2: every `tests/Feature/Rme*` billing module, ClinicOversight, CI governance (`tests/Feature/Cicd`), legacy visit-bound ingestion, route-structure suites, lab-candidate E2E (98 files) | 1,980 | 0 | 0 | 0 | 17,037 |
| Governance re-run on the final rule text (instruction system, critical-gate coverage, closure contract, production command guard) | 422 | 0 | 0 | 0 | — |

The red run is the mutation evidence for each fix:

- **Pattern not registered.** Both structural cases fail, and all 9 guest cases receive 302 instead of 404.
  Six signed-in cases get a PostgreSQL 500. `-1`, `0` and `007` cast cleanly, so they are covered by the guest leg.
- **Per-route `whereNumber` kept.** The structural and compiled-collection cases fail, naming exactly the four
  legacy-archive routes.
- **Raw `getMessage()` kept.** Both privacy cases fail, with the synthetic patient name in the logged context.

Quality: whole-repository `pint --test` passes; `git diff --check` clean. Full Suite: **deferred** under CG-18
(the GLOBAL TEMPORARY FULL-SUITE POLICY is active), not run and not claimed. Dedicated-device testing does not apply
(no device-specific behaviour changed); clinic devices were not touched.

## 7. Foundation rule synchronisation

- New canonical rule `.cursor/rules/222-rme-route-key-constraint-and-failure-log-redaction.mdc`.
- Domain index lines: `AIR-RMC-37` in `.claude/rules/rme-clinical.md`, `AIR-RMB-45` in `.claude/rules/rme-billing.md`,
  plus a canonical-rules table row in each.
- Superseded behaviour: per-route `whereNumber('clinicVisit')` on the legacy-archive routes (rule 222 §1.4). No
  earlier rule required it; it was an implementation choice.
- `CLAUDE.md` unchanged: no critical global rule changed (CG-24). The Foundation-First lock wording is unchanged by
  owner decision OD-B.
- CI: token `ClinicVisitRouteKey` added to BOTH critical-gate filter variants; both suites declared in
  `config/ci_runner.php` `critical_gate_mandatory_suites`. Selection proven with `pest --list-tests` using each exact
  CI filter string: 29 of 29 tests selected in each variant.

## 8. CI, merge, deploy and production verification

(Filled in from measured results.)

## 9. Residuals and follow-ups

- §2.6 same-class route-key weakness on other RME parameters and on `oversight/rme/visits/{visit}` (owner
  approval needed).
- The redactor cannot recognise an unquoted free-text name (rule 222 §2.5); no current throw site produces one.
- The Foundation-First Sprint Lock remains ACTIVE; lifting it needs a dedicated governance update and an owner
  decision (OD-B).
- `branchIdsFor(null)` defence-in-depth residual (WATCH).
- `.claude/rules/rme-billing.md` is at about 29.2K of its 30K character budget; the next addition should move
  history out first.
- Full Suite deferred under CG-18, not passed.
