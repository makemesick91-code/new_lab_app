# FIX-RME-INVOICE-BRANCH-SCOPE-EXPLICIT-ACTOR-1

Security bugfix of the RME invoice service: branch authorization is decided from
the EXPLICIT actor the caller passes, never from the ambient session.
Rule: `.cursor/rules/198-rme-invoice-branch-scope-explicit-actor.mdc` (closes rule
197 §30 for `RmeInvoiceService`).

**Scope.** Technical security fix inside existing authority. No owner decision,
no migration, no permission, no role, no route, no feature-flag change. It does not
touch the invoice correction service, the SPN4 pilot, the pilot identities
(maker 29 / checker 1), D10, invoice-version binding or manual pricing. Its GO tag
is a SCOPED security GO; it never certifies the pilot (rule 196 ladder).

## 1. Baseline

| Item | Value |
|---|---|
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Base SHA (canonical remote) | `3d7ca206` (docs-only evidence on top of the runtime), tree `5158026f` |
| Production runtime before | `f66c6c2a` / tree `c39680bc`, tag `hardening-rme-invoice-correction-pilot-identity-payment-actor-1-go` |
| Previous post-merge CI `39625376276` (`f66c6c2a`, push) | **CANCELLED** — superseded by the #502 evidence push (concurrency `cancel-in-progress`); its Critical Gate and Selective Module Gate were cancelled mid-run. The replacement run `39631689169` (`3d7ca206`) is `success` but docs-only: its critical test step was SKIPPED. The runtime `f66c6c2a` therefore has candidate-SHA CI evidence (run `39603057762`) and no completed post-merge test run. |
| Open PRs | 0 |
| Production deploy lock | free (`flock -n` succeeds); last runner status `exit=0` |
| Production working tree | clean except pre-existing untracked `storage/runtime-home/` (not task-owned) |
| Invoices / payments | 7 / Rp 7.548.000 (PAID 5, PARTIAL 1, UNPAID 1) · 8 / Rp 5.548.000 |
| Invoice items | 8 / Rp 7.548.000 |
| Corrections | 0 |
| Receivable remaining | Rp 2.000.000 |
| `sys_audit_logs` | 1601 rows, max id 1623 |

All production figures measured read-only on 2026-10-22 with
`default_transaction_read_only=on` (psql as `postgres`, statements on stdin).

## 2. Root cause (reproduced on the unmodified base)

`RmeInvoiceService::cashierScopeBranchIds()` returned
`RmeWorkingBranchScope::branchIdsFor(Auth::user())`. It fed the cashier
pending-billing list (`paginatePendingVisits`) and the doctor→cashier handoff
queue (`cashierHandoffQueue`).

- It judged whoever was logged in, not the actor the caller meant.
- `branchIdsFor(null)` returns `BranchService::rmeEnabledIds()` — every active RME
  branch. With no session both lists failed OPEN.

Reproduction on `3d7ca206` (temporary test, deleted before commit), two RME
branches A and B with one cashier-pending visit each:

```
REPRO_NO_SESSION_PENDING=[1,3]        # visit 1 = branch A, visit 3 = branch B
REPRO_NO_SESSION_HANDOFF=[1,2,3,4]    # both branches
REPRO_AMBIENT_FOREIGN_PENDING=[3]     # follows whoever is logged in
```

**Reachability.** Measured, not assumed: the two read methods have exactly one
caller each, `RmeInvoiceController::index` and `::handoff`, behind `auth`,
`permission:manage_rme_billing` and `authorize('viewAny')`. Over HTTP the session
user is the actor, so the defect was LATENT, not exploited. It was a real defect
for any non-HTTP caller (none exist today).

**Found in the same audit.** `create(ClinicVisit, User $cashier, …)` already took
an explicit cashier and checked its working branch (rule 194 F1), but performed
NO service-level usability or permission check. Reproduced red on the base: an
inactive account, a deleted account, an unsaved object carrying the Super Admin id,
and an active account without `manage_rme_billing` could each create an invoice
through a direct service call ("The invoice was created"). The unsaved-object case
wrote `cashier_id` = the Super Admin id. `findInBranch()` read `BranchContext`
(which reads `Auth::user()`) and had ZERO callers.

## 3. Caller inventory

| Caller | Operation | Actor source (after) | Authorization (after) | Reachability | Change |
|---|---|---|---|---|---|
| `RmeInvoiceController::index` | `paginatePendingVisits` | `$request->user()` | route `auth` + `permission:manage_rme_billing`, policy `viewAny`, service guard + scope | HTTP, authenticated | pass actor |
| `RmeInvoiceController::handoff` | `cashierHandoffQueue` | `$request->user()` | same; request `branch_id` only narrows | HTTP, authenticated | pass actor |
| `RmeInvoiceController::create` | `findInvoiceForVisit` | `$request->user()` | policy `create` on the visit, service discloses only in-scope invoice | HTTP, authenticated | pass actor |
| `RmeInvoiceController::store` | `create` | `$request->user()` (already) | policy `create`, service guard (new) + working branch | HTTP, authenticated | service guard added |
| tests / browser smoke | `create` | fixture cashier | same | test only | none (fixtures already hold `manage_rme_billing`) |
| — | `findInBranch` | `BranchContext` (ambient) | none | **0 callers** | **removed** |
| jobs / commands / listeners / schedule | — | — | — | **none exist** | n/a |

Out of scope (recorded in rule 198 §30–31): the shared
`RmeWorkingBranchScope::branchIdsFor(null)` null contract, and
`ClinicVisitService` (`:94`, `:143`) which still reads `Auth::user()` for the visit
domain.

## 4. Design

- **Explicit actor contract.** `paginatePendingVisits(User $actor, …)`,
  `cashierHandoffQueue(User $actor, …)`, `findInvoiceForVisit(ClinicVisit, User $actor)`
  and `create(ClinicVisit, User $cashier, …)`: non-nullable, required. Null is a
  `TypeError` at the boundary.
- **One billing-actor authority.** New `RmeBillingActorGuard`
  (`resolveUsable`, `isUsable`, `resolveBillingActor`) re-reads the account from the
  database (persisted, key present, `is_active`, not soft-deleted) and evaluates
  `manage_rme_billing` on the persisted account. `RmePaymentService`'s private
  `cashierIsUsable()` now delegates to it — identical semantics, one definition.
- **Fail closed.** Read lists: unusable or non-billing actor ⇒ empty scope
  (`whereIn([])` ⇒ no rows). Lookup: invoice disclosed only when its branch is in
  the actor's scope. Creation: refused with `AuthorizationException` inside the
  transaction, after the visit lock, before any branch read, pricing, invoice
  number or audit row; the working-branch check then runs on the persisted account.
- **No ambient authority.** `RmeInvoiceService` has no `Auth::`, `auth()`,
  `app('auth')`, `request()`, `Request::`, `session()` or `BranchContext` in code;
  the unused `BranchContext` dependency and `findInBranch()` are removed.
- **Payment consistency (security review F2).** `RmePaymentService::assertInvoicePayable()`
  resolves the persisted account once through the guard and asks the billing
  permission and the working branch of THAT account (previously both were asked
  of the passed object, so a stale in-memory role could let payment accept what
  invoice creation refused). Error messages and the `rme_invoice_id` error key are
  unchanged.
- **Billing form 500 (security review F1, pre-existing since Sprint 20).**
  `RmeInvoiceController::create()` was declared `: View` but redirects when the
  visit already has an active invoice — a `TypeError`/500 on an ordinary path (a
  stale "Buat Tagihan" link after a colleague billed, back + reload, a direct URL).
  Now `View|RedirectResponse`; reproduced red, then green.

## 5. Tests

New suite `tests/Feature/RME/RmeInvoiceExplicitActorTest.php` (39 tests): no-session
fail-open closed for both lists; ambient Super Admin cannot widen; ambient cashier
cannot replace the explicit actor; inactive / deleted / unsaved-forged / unknown-id
/ stale-in-memory / no-billing-permission actors fail closed; off-shift
context-bound cashier stays empty; Super Admin governance scope kept; request
branch filter only narrows; reflection pins non-nullable actor parameters; null is
a `TypeError`; `findInBranch` gone; service source has no ambient reads; creation
refuses every unusable actor and writes no invoice / number / audit; explicit
cashier recorded even when the session differs; existing-invoice lookup
actor-scoped; HTTP index/handoff scoped, crafted `branch_id` cannot widen,
request-supplied `cashier_id` ignored, wrong-branch POST 403, guest redirected;
the billing form redirects to an existing invoice instead of a 500 (F1); a stale
in-memory Super Admin role revoked in the database is refused by BOTH invoice
creation and payment (F2).

TDD evidence: on the base the first 36 cases ran **24 failed / 12 passed** (the 12
are the preservation cases and the type-boundary cases); after the fix **36
passed**. The F1 case was added after the security review and reproduced red on
the fixed service (`TypeError … Return value must be of type View,
RedirectResponse returned`) before the controller fix. Final: **38 passed**, with
the payment explicit-actor suite **18 passed**. A 39th case (demoted account
scoped by its persisted role, not a stale loaded Super Admin role) was added
after mutation M10 survived; see §7. Final: **39 passed / 99 assertions**.

CI selection: no existing critical token matched `RmeInvoiceExplicitActorTest`; the
`RmeInvoiceExplicitActor` token was added to BOTH critical-gate variants and the
file declared in `config/ci_runner.php` `critical_gate_mandatory_suites`.

## 6. Regression and PostgreSQL

| Run | Scope | Result |
|---|---|---|
| SQLite targeted regression (before the F1/F2 follow-up) | `tests/Feature/{RME,RmeInvoiceCorrection,RmeManualPricing,RmeTariffAuthority,ClinicOversight}` + every other Feature/Unit file referencing the invoice/payment services, `rme.cashier.*`, `RmeInvoice::`, corrections or `manage_rme_billing` (115 targets) | **3108 passed / 15 skipped / 0 failed**, 11925 assertions, exit 0 |
| PostgreSQL **16.15** (throwaway container, production major) | both explicit-actor suites, `RmeBillingRefundPhase0InvoiceRaceConcurrencyTest`, `RmeBillingRefundPhase0FinancialPrerequisitesTest`, `CashierBillingTest`, all of `RmeInvoiceCorrection` (incl. `…ConcurrencyTest`, `…PaymentBlockTest`), all of `RmeManualPricing`, `RmeTariffProvisioningConcurrencyTest` | **434 passed / 0 skipped / 0 failed**, 1811 assertions — the concurrency suites ran for real (they skip off PostgreSQL) |
| `tests/Feature/Cicd` | critical-gate token / mandatory-suite registry, CI contracts | **272 passed**, 1562 assertions |
| Pint (whole repository) | | passed |
| `git diff --check` (incl. new files) | | clean |
| DEVFLOW | `sprint:manifest-check` GO · `sprint:scope-audit --strict` GO (1 module, RmeInvoice) · `foundation:devflow-check --strict` GO · `foundation:shared-service-audit --strict` GO · `devflow:base-ref-check --strict` (canonical remote `3d7ca206`; local base ref stale and ignored) | |

The PostgreSQL concurrency coverage proves the actor check runs inside the
existing per-visit `FOR NO KEY UPDATE` transaction without changing the race
outcome (one invoice per visit), the payment-vs-correction and approval-vs-payment
races (D10 under the invoice lock) and the invoice-version binding.

## 7. Mutation testing (copy-based harness, sha256-verified restore)

| # | Mutant | Result |
|---|---|---|
| M01 | scope ignores the guard | KILLED (8) |
| M02 | unusable actor falls back to every RME branch | KILLED (8) |
| M03 | guard drops `is_active` | KILLED (4) |
| M04 | guard trusts an unsaved object | KILLED (3) |
| M05 | guard drops the billing permission | KILLED (5) |
| M06 | guard includes soft-deleted accounts | KILLED (3) |
| M07 | `create()` skips the actor guard | KILLED (6) |
| M08 | `create()` skips the working-branch check | KILLED (2) |
| M09 | lookup discloses regardless of scope | KILLED (1) |
| M10 | `create()` keeps the passed object for scope | **SURVIVED first** → real gap (a demoted account carrying a stale loaded Super Admin role could bill any branch); test added → KILLED (1) |
| M11 | payment trusts the passed object | KILLED (5) |
| M12 | controller passes a different actor | KILLED (1) |
| M13 | payment scope asked of the passed object | KILLED (1) |
| M14 | payment permission asked of the passed object | KILLED (1) |
| M15 | billing form return type back to `View` | KILLED (1) |

`TOTAL_MUTANTS=15 KILLED=15 SURVIVED=0 EQUIVALENT=0 NOT_APPLIED=0`.

## 8. Independent security review

An independent reviewer (read-only, no edits) found **0 CRITICAL, 0 HIGH**.

| ID | Severity | Finding | Disposition |
|---|---|---|---|
| F1 | MEDIUM, pre-existing (Sprint 20) | `RmeInvoiceController::create()` `: View` returns a redirect when an invoice exists → TypeError/500 | **fixed** (`View|RedirectResponse`), test added |
| F2 | LOW | payment asked permission and scope of the passed object, invoice creation of the persisted account | **fixed**, tests + M13/M14 |
| F3 | LOW/INFO | rule 198 §30 overclaimed "no billing-domain path can pass null"; `is_active` not enforced per request | **rule corrected** (§30, §30a) |
| F4 | INFO | governance pin did not cover `app('auth')`, `Request::`, `session()` | **pin extended** |
| F5 | INFO | pricing-refusal audit uses the caller's object (same id) | accepted, no misattribution |
| F6 | INFO | ~3 extra queries per guard resolution, no N+1 | accepted, documented (rule 198 §25a) |
| F7 | INFO | `RmeInvoiceRepository::findInBranch` now uncalled | accepted (explicit-parameter repository method, not ambient) |
| F8 | INFO | a hand-built `exists=true` object with a real id resolves to that account | inherent; the caller is the trust boundary (controllers pass `$request->user()`) |
| F9 | INFO, pre-existing | online-context expiry write from read paths | unchanged |
| F10 | INFO | wording of "before any branch read" | **rule corrected** (§14) |

## 9. Authoritative CI (exact candidate)

Run `39691774035` (`pull_request`) on candidate `dfcac520` / tree `adad24bf` — **success**.

| Job | Id | Result |
|---|---|---|
| CICD-CTRL Gate Classifier | 119539432760 | success (`gate_profile=unknown_high_risk`) |
| NSF-R012 Quality Gate | 119539433132 | success |
| Phase 3 Android Clinic App Gate | 119539432904 | success |
| NSF-R011 Critical Test Gate | 119540502417 | success — **6172 passed / 32423 assertions, 0 failed, 0 skipped**, `critical_test_exit_status=0`, duration 8398 s; warning contract `expected=0 observed=0 unexplained=0`; `RmeInvoiceExplicitActorTest` and `RmePaymentExplicitActorTest` both PASS in the log |
| NSF-R011 Critical Test Gate (other runner variant) | 119540504466 | skipped by design (exactly one variant runs) |
| CICD-CTRL Selective Module Gate | 119540502474 | success |
| NSF-9 Release Safety & Automated Smoke Gate | 119590259710 | success |
| NSF-10 Release Evidence Gate | 119592002199 | success |
| NSF-R011 Full Suite Gate | 119590261177 | **SKIPPED** — `full_suite_authorized=false`, `full_suite_defer_reason=FULL_SUITE_NOT_ENABLED_FOR_EVENT`. Not a pass. |

Local final SQLite regression on the final tree (after the F1/F2 follow-up):
**3111 passed / 15 skipped / 0 failed**, 11941 assertions, exit 0.

## 10. Merge

PR #503 squash-merged as **`f0044fd69272f8b89e25baaee0a6e0d9d27261b8`**, parent
`3d7ca206` (base did not move). Merge tree `adad24bf` **==** candidate tree
`adad24bf` — the tested tree is the merged tree.

Post-merge `push` run: `39708364725` (recorded in §15; this evidence commit is
merged only after it reaches a terminal state, so it is not cancelled by
concurrency the way `39625376276` was).

## 11. VPS deployment

Executed **inside the VPS** (`/var/www/asia-dental-lab-v2`) via
`bash scripts/deploy-vps-runner.sh start` (detached pid 132500).

| Item | Value |
|---|---|
| Preflight | deploy lock free, no competing deploy, HEAD `f66c6c2a`, 88 G free, health 200×4, no pending migration |
| Log / status | `storage/logs/deploy-runner/deploy-20261022-015453.{log,status}` |
| Final status | **`exit=0`**, **`DEPLOY OK: 20261022-015454`** |
| Immutable entrypoint | `DEPLOY_LOCK_ACQUIRED=YES`, `DEPLOY_TARGET_PINNED=f0044fd6…`, `DEPLOY_SNAPSHOT_TRUSTED=PASS`, `immutable-exec rc=0`, `DEPLOY_SNAPSHOT_CLEANED=YES` |
| Target match | **`DEPLOY_HEAD_TARGET_MATCH=YES (f0044fd6…)`**; VPS HEAD `f0044fd6`, tree `adad24bf` |
| Backup | `pre_auto_deploy_20261022-015454.sql` (NSF-10 backup-verify GO) |
| Migrations | `Nothing to migrate.` |
| Runtime isolation / secrets | 70 GO / 0 FAIL / 0 SKIP; secret file permissions GO |
| Governance profile | 4 WATCH / 50 GO / 0 FAIL — **identical** to the previous deploy (`deploy-20261021-152449`); the WATCHes are pre-existing (risky flags deliberately on, DBPERF-2, release-safety local-profile evidence, and the known `http://127.0.0.1/login` 404 from the co-tenant nginx shadow) |

## 12. Production verification (read-only)

| Check | Result |
|---|---|
| Canonical domain | `/login`, `/health/live`, `/health/ready`, `/health/lb` 200; guest `/rme/cashier`, `/rme/cashier/handoff` 302 (no 500) |
| Deployed code fingerprint (tokenizer, comments stripped) | `RmeInvoiceService` contains no `Auth::`, `auth()`, `request()`, `app('auth')`, `session()`, `BranchContext` |
| Signatures (reflection, no app boot, no DB) | `paginatePendingVisits`/`cashierHandoffQueue`/`findInvoiceForVisit` → `actor: App\Models\User` non-nullable; `create` → `cashier: App\Models\User` non-nullable; `findInBranch` absent; `RmeBillingActorGuard` present |
| Payment | resolves the persisted account through the guard; scope asked of `$account` |
| Billing form | `create()` returns `View|RedirectResponse` |
| Cashier routes | 8 `rme.cashier.*` routes registered |
| Correction pilot (`rme:invoice-correction-readiness --maker=29 --checker=1`, as `daengtisiams`) | `STATE=PILOT_ACTIVE`, `FLAG_ENABLED=true`, pilot `SPN4`, `PILOT_IDENTITIES valid=yes maker=29 checker=1`, pair `VALID=yes`, WATCH only `PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT` (unchanged); `--maker=30` still FAILs `PAIR_MAKER_NOT_PILOT_IDENTITY` |
| RBAC | `role_has_permissions` 514, direct grants 0 (unchanged) |
| Queue | `failed_jobs` 0, `jobs` 0, worker active |
| Application log | `laravel.log` 1 437 201 bytes / 162 ERROR before and after — **0 new errors** |

Authenticated runtime exercise was deliberately NOT performed: calling the cashier
lists for a real context-bound account can expire its online-context row (an
UPDATE from a read path, review F9), and no fake patient, invoice or payment is
ever created for evidence. Behaviour is proven by the 39-case suite on SQLite and
PostgreSQL 16 and in CI; production proves the deployed code is that code.

## 13. Financial reconciliation

Captured before (preflight) and after (post-deploy) with
`default_transaction_read_only=on`:

| Metric | Before | After | Class |
|---|---|---|---|
| Invoices (count / grand total) | 7 / 7.548.000 | 7 / 7.548.000 | unchanged |
| Status mix | PAID 5, PARTIAL 1, UNPAID 1 | same | unchanged |
| Invoice items (count / subtotal) | 8 / 7.548.000 | 8 / 7.548.000 | unchanged |
| Payments (count / amount) | 8 / 5.548.000 | 8 / 5.548.000 | unchanged |
| Payment batches | 1 | 1 | unchanged |
| Corrections | 0 | 0 | unchanged |
| `sys_audit_logs` (rows / max id) | 1601 / 1623 | 1601 / 1623 | unchanged |
| Receivable remaining | 2.000.000 | 2.000.000 | unchanged |

`UNEXPECTED_MUTATION=0`. No financial row was written by this release.

## 14. GO tag

Annotated **`fix-rme-invoice-branch-scope-explicit-actor-1-go`** (tag object
`01eb884f`) → **`f0044fd6`**, the deployed runtime (not this evidence commit).
Local = remote = VPS (`git describe --exact-match HEAD` on the VPS). Rollback:
`bash scripts/rollback-vps.sh hardening-rme-invoice-correction-pilot-identity-payment-actor-1-go`
(no schema or data to restore). SCOPED security GO only — `FULL_PILOT_GO` is NOT
claimed; the authenticated pilot and a real owner-authorized correction remain
PENDING (rule 196).

## 15. Post-merge CI and cleanup

Post-merge `push` run **`39708364725`** on `f0044fd6` — **success** (allowed to
finish before this evidence was merged):

| Job | Id | Result |
|---|---|---|
| CICD-CTRL Gate Classifier | 119593601710 | success (`gate_profile=unknown_high_risk`) |
| NSF-R012 Quality Gate | 119593601785 | success |
| Phase 3 Android Clinic App Gate | 119593601434 | success |
| NSF-R011 Critical Test Gate | 119594457787 | success — **6172 passed / 32423 assertions**, `critical_test_exit_status=0`, `observed_warning_count=0`; `RmeInvoiceExplicitActorTest` PASS |
| CICD-CTRL Selective Module Gate | 119594457710 | success |
| NSF-9 / NSF-10 | 119636664570 / 119637943520 | success / success |
| NSF-R011 Full Suite Gate | 119636666211 | **SKIPPED** — `full_suite_defer_reason=TEMPORARY_FULL_SUITE_POLICY_ACTIVE`. Not a pass. |

So, unlike the previous sprint (`39625376276`, CANCELLED), this runtime has a
completed post-merge test run as well as candidate-SHA evidence.

Cleanup (task-owned only): throwaway PostgreSQL 16 container removed; the VPS
read-only fingerprint SQL file removed; the task worktree, its local branches and
its merged remote branches removed after this PR merged; scratch test temp
directory removed; no task-owned test process, CI run, deploy or lock left.
Pre-existing, not touched: `storage/runtime-home/` (untracked) on the VPS, the
stale local base-branch ref in the primary checkout, and the other worktrees.
