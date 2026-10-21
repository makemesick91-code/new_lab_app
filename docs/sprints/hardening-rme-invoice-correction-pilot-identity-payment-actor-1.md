# HARDENING-RME-INVOICE-CORRECTION-PILOT-IDENTITY-PAYMENT-ACTOR-1

Security hardening of the live SPN4 pre-payment invoice correction pilot (owner
decision **D1e**) and of the RME payment service (explicit payment actor).
Rule: `.cursor/rules/197-rme-invoice-correction-pilot-identity-payment-actor.mdc`.
Decision register: `docs/decisions/rme-billing-correction-owner-decisions.md` (D1e).

**Scope.** Security hardening only. This sprint does NOT perform or claim a real
correction, a refund, a post-payment correction, an Rp0 adjustment, a role redesign
or any branch other than SPN4. Its GO tag certifies the hardening; it does not
certify `PILOT_LIVE`, `AUTHENTICATED_VERIFIED`, `FINANCIAL_CORRECTION_VERIFIED` or
`FULL_ACTIVATION_GO` (rule 196).

## 1. Baseline

| Item | Value |
|---|---|
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Base SHA (canonical remote) | `1dd247a5` (docs-only on top of the runtime) |
| Production runtime before | `61a29795` / tree `148f6e1f`, tag `activation-rme-invoice-correction-prepayment-2-pilot-readiness-go` |
| Production flag before | `FEATURE_RME_INVOICE_CORRECTION=true`, `STATE=PILOT_ACTIVE` |
| Corrections in production | 0 (no status of any kind) |
| Invoices / payments | 7 / Rp 7.548.000 · 8 / Rp 5.548.000 (receivable Rp 2.000.000) |
| `role_has_permissions` | 514 |
| Correction permission holders | Super Admin 1 (request/approve/apply); Front Office 7, 8, 16, 17, 29, 30, 31, 32 (request/approve); 0 direct grants |

All figures measured read-only on production on 2026-10-21 ~08:10 UTC
(16:10 WITA) with `default_transaction_read_only=on`.

## 2. Exposure assessment (before any code)

The verification sprint had already measured, and this sprint re-confirmed, that
**all 8 Front Office accounts** held both `request_rme_invoice_correction` and
`approve_rme_invoice_correction` and were eligible for SPN4. Maker ≠ checker was
enforced per account, so any two of them could file and approve a correction
between themselves — the pilot was not confined to the two D1d accounts.

`PRE_HARDENING_EXPOSURE` = 7 non-designated Front Office accounts + any future
Super Admin could move money through a correction in SPN4.

## 3. Temporary mitigation (production, before the code change)

The brief's prescribed fail-closed mitigation: switch the correction capability
OFF until the identity guard is deployed.

| Step | Evidence |
|---|---|
| Precondition | 0 corrections in production → switching OFF strands no invoice (D10 is NOT flag-gated; reject/cancel ARE, so an active correction would have been stranded) |
| Edit | exactly one line `FEATURE_RME_INVOICE_CORRECTION=true` → `false`; written through a 0600 `/dev/shm` temp and `cat >` so the inode (796156), mode `640` and owner `root:daengtisiams` are preserved; line count 110 → 110; temp shredded; no backup copy of the secret file kept |
| Hash | before `1071b268…`, after `c0e075a9…` (prefixes) |
| Cache | `runuser -u daengtisiams -- php artisan config:cache` (config file `daengtisiams:daengtisiams 644`) |
| Verify | probe → `STATE=DORMANT`, `FLAG_ENABLED=false env_resolution=env`; `/login`, `/health/live`, `/health/ready` 200 |
| Time | 2026-10-21 08:14 UTC (16:14 WITA) |
| Effect on billing | none — the flag gates only correction mutations; invoice creation and payment are untouched |
| Rollback of the mitigation | the same one-line edit back to `true` + `config:cache` as `daengtisiams` (performed only after the hardening is verified, §9) |

## 4. Correction mutation paths (all gated)

| Path | Route / entry | Flag | Permission (route + service) | Working-branch scope | Branch allowlist (SPN4) | Self-decision | **Identity (new)** |
|---|---|---|---|---|---|---|---|
| Submit | `POST …/corrections` → `submit()` | yes | request | yes | yes | — | **maker list** |
| Approve (= apply, D1a) | `POST …/approve` → `approve()` | yes | approve | yes | yes | `SELF_APPROVAL` | **checker list** |
| Reject | `POST …/reject` → `reject()` | yes | approve | yes | no (rule 195 §17) | `SELF_APPROVAL` | **checker list** |
| Recovery apply | `POST …/apply` → `apply()` | yes | apply | yes | yes | `SELF_APPLICATION` | **checker list** |
| Cancel (own) | `POST …/cancel` → `cancel()` | yes | request | yes | no | `NOT_THE_MAKER` | **not gated (release path)** |
| Readiness probe | `rme:invoice-correction-readiness` | read-only | — | — | — | — | reports + FAILs |

No job, listener, observer or other controller reaches a correction transition
(the service is the only writer; verified by search).

## 5. Pilot identity architecture

- `App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPilotIdentity` — the single
  authority. `allowlist()` validates `config('rme_invoice_correction.pilot.identities')`;
  `designates($action, $id)`; `allows($action, ?User)`; `assertAllowed()` throws
  `RmeInvoiceCorrectionDenied` with `PILOT_IDENTITY_NOT_AUTHORIZED` or
  `PILOT_IDENTITY_ACTOR_UNUSABLE`.
- Config: `pilot.identities = ['maker_user_ids' => [29], 'checker_user_ids' => [1]]`,
  source-controlled, **no env read** (a governance test pins the exact values and
  the absence of `env(`).
- Fail-closed: missing key / non-array / non-list / string id / zero / negative id
  → whole allowlist void; overlapping lists → void. An EMPTY role list stops that
  role only; SUBMIT additionally requires a designated checker.
- The actor is re-read from the database: persisted, not soft-deleted, `is_active`.
- Placement: inside the transaction, after the row lock, after permission →
  working-branch scope → branch allowlist → self-decision. Refusals are audited by
  the existing `audited()` wrapper (`RME_INVOICE_CORRECTION_REFUSED`, code only).
- Courtesy layers: policy (approve/reject/apply); controller 403 on the request
  form/store AFTER the branch-scope 404; `actionsFor()` drives the detail-page
  controls (a bare `@can` is true for any Super Admin); cashier panel `can_request`.

**Design correction found by TDD.** The first version required both lists to be
non-empty. A test proved that emptying the MAKER list (a legitimate "stop new
submissions, drain filed ones" state) would also have removed the checker's REJECT
— a release path. Empty now stops only that role; SUBMIT requires a checker so no
correction can be filed that nobody may decide.

## 6. Payment actor (Objective B)

Root cause: `RmePaymentService::assertInvoicePayable()` called
`RmeWorkingBranchScope::allows(Auth::user(), …)`. Two defects:
1. it judged the **ambient session**, not the cashier the payment is recorded
   under (`cashier_id`) — the wrong person for any non-HTTP caller;
2. `branchIdsFor(null)` answers **every active RME branch**, so with no session the
   branch check **failed open** (reproduced: the red test "refuses a wrong-branch
   cashier when no session exists" was accepted before the fix).

Fix: `assertInvoicePayable(RmeInvoice, User $cashier)` — the explicit cashier on
all three paths (pay, control allocation, visit allocation); the cashier must be a
persisted, non-deleted, active account (re-read). `RmePaymentService` now contains
no `Auth::`, `auth()` or `request()` in code (governance test, comments stripped).
Unchanged by design: carry-over parents stay patient-scoped across RME branches
(Sprint 62.2 contract); Super Admin keeps its governance cashier scope; D10, the
invoice-version binding, `paid_at` bound and batch atomicity are untouched.

## 7. Tests (TDD)

Red first: the two new suites were written before any production code —
**35 failed / 21 passed**; every failure was behavioural (an action allowed that
must be refused, or a missing report key); the 21 passes are the invariants that
must hold before and after.

| Suite | Result |
|---|---|
| `RmeInvoiceCorrectionPilotIdentityTest` + `RmePaymentExplicitActorTest` (new) | **61 passed / 178 assertions** |
| SQLite regression — `RmeInvoiceCorrection`, `RmeManualPricing`, `RmeTariffAuthority`, the RME payment / cashier / carry-over / consent / lab-candidate / receipt / refund-prerequisite suites, `ClinicOversight`, `AccessControl`, `FrontOfficeDevice`, cashier UI suites | **1240 passed / 21 skipped (PostgreSQL-only) / 0 failed / 5702 assertions** |
| PostgreSQL **16.15** (throwaway `postgres:16`, driver asserted `pgsql`) — `RmeInvoiceCorrection` (incl. the race suite), `RmeManualPricing`, both new suites, `RmePayment`, carry-over, `FixClinicOpsWorkingBranchContext`, `CashierBilling`, `LabCaseCandidateConversion` | **471 passed / 0 failed** (race suite 10/10 / 66 assertions after the fixture fix below) |

Two fixture findings while running the regression, both the defect class itself:
- `LabCaseCandidateConversionTest` recorded a payment under `$this->converter` — a
  lab-conversion account with NO billing permission — while the session was a
  billing user. It passed only because the old check judged the session. The
  fixture now passes the billing account as the cashier; the test's subject
  (payment never auto-creates a LabOrder) is unchanged.
- `RmeInvoiceCorrectionConcurrencyTest` builds its actors in `ricCommittedInvoice()`
  without `ricSetup()`, so on PostgreSQL all 8 race tests were refused with
  `PILOT_IDENTITY_NOT_AUTHORIZED` — the fail-closed default working. The fixture
  now designates its race accounts (and the second submitter). The competing
  transactions run raw SQL in a child process, so they never read the allowlist.

Revised (not deleted) existing tests — each now designates its own actors so the
property it asserts is still exercised, with a `REVISED by rule 197` note:
self-approval / self-application tests (Lifecycle ×2, PilotActivation ×4, Manual
Pricing ×1), the D1 role tests (TariffAuthority ×2), the readiness pair tests
(PilotActivation ×4), and the "two Front Office accounts" flow, which now proves a
second Front Office account is REFUSED and the designated checker approves.

## 8. Mutation (copy-based revert, NOT-APPLIED never counted as KILLED)

20 mutants: **KILLED 19 · SURVIVED 1 · NOT-APPLIED 0 · real survivors 0**, no
residue (every mutated file restored byte-identical, verified by SHA-256).

| Survivor | Why it is equivalent |
|---|---|
| M13b — controller `store()` identity 403 removed | the SERVICE still refuses the submit (`PILOT_IDENTITY_NOT_AUTHORIZED`, 403) inside the transaction; the controller line is a courtesy. Compound **M13c** (store gate AND the service submit assert both removed) is **KILLED**, proving the service assert is the load-bearing layer. |

Killed include: checker list ignored, maker list ignored, all three service
asserts removed, `usable()` always true, malformed-id acceptance, list overlap,
submit without a usable checker, payment back to `Auth::user()`, payment cashier
usability removed, payment billing permission removed, every UI/panel courtesy,
the readiness pair designation and the unusable-account finding.

## 9. Security review

0 CRITICAL / 0 HIGH / 0 MEDIUM. Four LOW, all fixed in this change set:
L1 config comment overstated what an empty list does; L2 submit accepted a
designated-but-inactive checker; L3 the payment actor was not required to hold
`manage_rme_billing` (mirrors `RmeInvoicePolicy::pay`); L4 `decide()` mapped an
unknown action to a default instead of throwing. Informational, recorded not
fixed: I1 an idempotency replay is answered before authorization (read-only, the
caller's own row only); I2/I3 the actor re-read is unlocked and costs one query;
I4 `cancel()` accepts an unsaved `User` (requires code execution); **I5
`RmeInvoiceService::cashierScopeBranchIds` still reads `Auth::user()`** — the
same defect class on the invoice side, out of this sprint's scope, recorded in
rule 197 as the residual.

## 10. Local gates

`pint --test` (whole repo) PASS · `git diff --check` clean · `view:cache` OK ·
`sprint:manifest-check` GO · `sprint:scope-audit --strict` GO ·
`foundation:devflow-check --strict` GO · `foundation:shared-service-audit --strict`
GO · `foundation:roadmap-check --strict` GO · `foundation:security-compliance-check`
GO · `foundation:ci-runtime-control-check --strict` GO ·
`foundation:cicd-enterprise-gate-check` GO.

The last three first exited 1 with `QueryException`: the task worktree carries no
database connection. Re-run against the throwaway PostgreSQL 16.15 they return
GO — an environment fault, not a code finding.

## 11. Authoritative CI

Run `39603057762` on the exact candidate `e7444f73` (tree `c39680bc`, event
`pull_request`) — **success**. Verified from the job logs, not the check marks.

| Gate | Result |
|---|---|
| CICD-CTRL Gate Classifier | success — `gate_profile=unknown_high_risk` |
| NSF-R012 Quality Gate | success |
| NSF-R011 Critical Test Gate | success — **6133 passed / 32321 assertions**, `critical_test_exit_status=0`, 0 failure glyphs; both new suites ran and PASSED (43 + 18 = 61 tests, equal to the local count) |
| NSF-R011 Critical Test Gate (other runner variant) | skipped by design — exactly one variant runs |
| CICD-CTRL Selective Module Gate | success |
| NSF-9 Release Safety & Automated Smoke | success |
| NSF-10 Release Evidence | success |
| Phase 3 Android Clinic App Gate | success |
| **NSF-R011 Full Suite Gate** | **SKIPPED** — `temporary_full_suite_policy_active=true`, `full_suite_authorized=false`, `FULL_SUITE_NOT_ENABLED_FOR_EVENT`. The classifier's `run_full_suite=required` is a risk signal, not an authorisation. **Not a pass.** |

The critical gate ran 7922.80s (≈132 min); recent non-docs runs took 133, 111 and
74 min, so this was within range, not a hang.

## 12. Merge

PR #501 squash-merged with `--match-head-commit e7444f73` as **`f66c6c2a`**;
parent `1dd247a5` (the base had not moved); **merge tree `c39680bc` = candidate
tree** — the merged content is exactly what CI tested.

## 13. Deploy

`bash scripts/deploy-vps-runner.sh start` executed ON the VPS (detached, pid
117786), followed to its status file — `exit=0`.

| Evidence | Value |
|---|---|
| Lock | `DEPLOY_LOCK_ACQUIRED=YES` |
| Pin | `DEPLOY_TARGET_PINNED=f66c6c2a…`, snapshot `DEPLOY_SNAPSHOT_TRUSTED=PASS`, executed from `/run/daengtisiams-deploy/…` |
| Backup | `storage/app/backups/deploy/pre_auto_deploy_20261021-152450.sql` (taken before migrate) |
| Migrations | `Nothing to migrate.` |
| Runtime isolation / secret permissions | GO / GO |
| Smoke | 6 passed, 1 known warning (`http://127.0.0.1/login` 404 — the co-tenant `default_server`; the canonical domain is verified separately) |
| Completion | **`DEPLOY OK: 20261021-152450`**, `DEPLOY_HEAD_TARGET_MATCH=YES`, `DEPLOY_SNAPSHOT_CLEANED=YES` |
| Production HEAD / tree | `f66c6c2a` / `c39680bc`, 0 tracked changes, 0 pending migrations |

## 14. Production verification

**With the flag still OFF (15:3x UTC):**

| Check | Result |
|---|---|
| Probe (as `daengtisiams`) | `STATE=DORMANT`; `PILOT_IDENTITIES valid=yes maker=29 checker=1` |
| Designated pair 29/1 | `designated=yes VALID=yes` |
| Non-designated maker 30/1 | `VALID=no`, `[FAIL] PAIR_MAKER_NOT_PILOT_IDENTITY 30` |
| Reversed pair 1/29 | `VALID=no`, FAIL on both halves |
| Deployed payment code | `assertInvoicePayable(RmeInvoice $invoice, User $cashier)`, 3 callers pass `$cashier`, the only `Auth::` occurrence is a comment |
| Financial fingerprint | identical to §1: 0 corrections; 7 invoices / Rp 7.548.000; 8 payments / Rp 5.548.000; `role_has_permissions` 514; 0 direct grants; audit watermark unchanged |
| Health | `/login`, `/health/live`, `/health/ready`, `/health/lb` 200 over `https://daengtisia.online` |
| Runtime | php8.3-fpm, nginx, queue worker active; no failed jobs; `laravel.log` unchanged (no new error) |

**Flag restored ON (2026-10-21 15:44 UTC / 23:44 WITA)** — only after the above.
Same one-line, inode-preserving edit as the mitigation: inode `796156`, mode
`640 root:daengtisiams` and 110 lines preserved; **hash returned to `1071b268`,
the exact pre-mitigation value**, i.e. the environment file is byte-identical to
its state before this sprint; `config:cache` rebuilt as `daengtisiams`.

| After restore | Result |
|---|---|
| Probe | **`STATE=PILOT_ACTIVE`**, `FLAG_ENABLED=true env_resolution=env captured=yes` |
| Pair 29/1 | `VALID=yes`; `LIVE_NOW=no` with `[WATCH] PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT` — correct at 23:44 WITA, user 29 is off shift |
| Health | 200 × 4 |

## 15. Status

| | |
|---|---|
| HARDENING_GO | **GO** — tag `hardening-rme-invoice-correction-pilot-identity-payment-actor-1-go` → `f66c6c2a` (local = remote = VPS) |
| PILOT_STATE | `PILOT_ACTIVE` (WATCH off shift) |
| FULL_SUITE | SKIPPED by policy — not a pass |
| AUTHENTICATED_PILOT | PENDING — no authenticated session was manufactured |
| REAL_FINANCIAL_CORRECTION | PENDING — none performed or claimed |
| FULL_PILOT_GO / FULL_ACTIVATION_GO | NOT CLAIMED |
| Residual | `RmeInvoiceService::cashierScopeBranchIds` still reads `Auth::user()` (rule 197) |
