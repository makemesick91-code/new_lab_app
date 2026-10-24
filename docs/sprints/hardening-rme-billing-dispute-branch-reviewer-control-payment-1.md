# HARDENING-RME-BILLING-DISPUTE-BRANCH-REVIEWER-CONTROL-PAYMENT-1

Program `DAENGTISIAMS-RME-BILLING-CORRECTION`, **Sprint 1A** — an extension of
Sprint 1/7 (`IMPLEMENTATION-RME-BILLING-DISPUTE-ESCALATION-OPS-OQ-1`, rule 203). It
is not counted as a new main sprint.

Rule: `.cursor/rules/204-rme-billing-dispute-branch-reviewer-control-payment.mdc`.
Architecture: `docs/architecture/rme-billing-dispute-escalation.md` §9–§10.
Operator SOP (Bahasa Indonesia): `docs/operations/rme-billing-dispute-sop.md` §7–§12.

## 1. Status

| Item | Status |
|---|---|
| OPS-OQ-1a (branch reviewer) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` |
| OPS-OQ-1b (Kontrol-only payment) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_DEPLOYED` |
| Deployment | `DEPLOYED` — runtime `9e37b855`, `DEPLOY_EXIT_CODE=0`, `DEPLOY_OK`, `DEPLOY_HEAD_TARGET_MATCH=YES` (§14) |
| Scoped GO tag | `hardening-rme-billing-dispute-branch-reviewer-control-payment-1-go` → `9e37b855` (scoped to this hardening; not a pilot GO) |
| Real operator acceptance | `NOT_PERFORMED` — no designation exists and none is fabricated; no production payment is created to prove behaviour |
| Invoice-correction pilot | unchanged: SPN4, maker 29 / checker 1, D28 — `FULL_PREPAYMENT_PILOT_GO: NO_GO` |

Provenance of both owner decisions: the owner's instruction in the brief of this
sprint. No signed artefact, approval reference or timestamp exists and none was
created.

## 2. Owner decisions (substance)

- **OPS-OQ-1a.** An explicitly designated branch responsible person may review and
  resolve branch-scoped billing disputes with legitimate resolution evidence. It must
  not propagate to Front Office / Kasir through the Admin Klinik role union; Super
  Admin keeps its authority; the Owner stays read-only; the reviewer is limited to
  the assigned branch and gains no invoice edit, correction maker/checker, refund,
  debt forgiveness, cancellation, manual PAID or guard override.
- **OPS-OQ-1b.** A legitimate Kontrol invoice may be paid independently while its
  originating visit invoice is under an active billing dispute; the payment is
  allocated only to the Kontrol invoice; the origin stays financially unchanged
  (receivable outstanding); the origin is not settled, reduced, reallocated,
  rewritten, waived or unlocked; D10 / version / branch rules are not bypassed; a
  combined payment containing the disputed origin is still refused atomically.

## 3. Root cause

**OPS-OQ-1a.** `review_rme_billing_disputes` could only be granted through a role,
and the Front Office role is the exact union of Admin Klinik and Kasir
(`App\Support\AccessControl\FrontOfficeRole`, `RoleSeeder`). Granting it to Admin
Klinik would have reached all eight Front Office accounts, including the counters
that report disputes. The only safe answer is an authority that is not a role.

| Account | Report / note | Review / escalate / resolve | Designatable |
|---|---|---|---|
| Super Admin | yes (`'*'`) | yes (`'*'`, rule-203 path unchanged) | no (redundant) |
| Owner | read only | no | **no** (read-only, checked at grant and use) |
| Admin Klinik | yes (`manage_rme_billing`) | only if explicitly designated for the branch | yes |
| Front Office | yes | **no** | **no** (counter role) |
| Kasir | yes | **no** | **no** (counter role) |
| Supervisor RME | yes | only if explicitly designated | yes |
| Designated reviewer | as per own role | yes — its designated branch, while working there | — |
| Kepala Cabang | no | no | no — lacks RME workspace access (recorded gap, §13) |

**OPS-OQ-1b.** `RmePaymentController::store()` forces `allocateControlPayment()`
whenever the Kontrol visit has a same-branch carry-over parent; that path settles the
parents first (FIFO) and refuses the whole batch when a parent is disputed (rule 203
§C.13). The relationship is dynamic (`follow_up_of_visit_id` ancestors, same patient,
`payingBranchIds()` = the Kontrol visit's own branch, OD-1a). The minimal change is a
separate, explicit path that settles the Kontrol invoice alone.

## 4. Design

**Designation** — `mst_rme_billing_dispute_reviewers` (one row per account and
branch; partial unique index on active rows; PostgreSQL CHECKs: revocation triple
complete, `user_id <> granted_by`; `RESTRICT` foreign keys; revoked rows immutable,
deletes refused by the model). `RmeBillingDisputeReviewerService` is the single
authority for grant, revoke, eligibility and use; the dispute service, the policy and
the escalation routing all ask it. New permission
`manage_rme_billing_dispute_reviewers` granted to **no operational role**.

**Kontrol-only payment** — `RmePaymentService::payControlInvoiceOnly()`; explicit
`payment_scope=control_only` + `confirm_control_only` (required by both the
FormRequest and the service); separate form on the payment page; one payment row on
the Kontrol invoice only; audit `RME_PAYMENT_CONTROL_ONLY_ORIGIN_DISPUTED` with
`allocated_to_origin = 0`.

**Lock orders** — dispute action: invoice → dispute → designation `FOR SHARE`;
revocation: designation `FOR UPDATE` only. Kontrol-only payment: Kontrol invoice →
each origin `FOR UPDATE`, the ordinary control path's own order (no new lock edge).

## 5. Decisions preserved

Rule 203 (all-branch hold; whole-batch refusal on every other path), D1, D1a, D1c
(SPN4 only), D1e (maker 29, checker 1), D7, D10, D21 (manual pricing), D28, OD-1,
OD-1a, rule 191 version binding. D21c, D21g and D22 stay **PENDING**. RoleSeeder is
unchanged; no operational role gains a permission.

## 6. TDD evidence

RED captured before implementation: **54 failed / 4 passed** across the two new
suites (the 4 passing were invariants that already held). Baseline before the change:
dispute directory SQLite 77 passed / 10 skipped / 411 assertions; PostgreSQL 16.15
87 passed / 482 assertions.

## 7. Test matrix

| Suite | Tests |
|---|---|
| `RmeBillingDisputeBranchReviewerTest` | 42 |
| `RmeBillingDisputeControlOnlyPaymentTest` | 27 |
| `RmeBillingDisputeBranchReviewerConcurrencyTest` (PostgreSQL) | 19 |

All three are declared in `config/ci_runner.php` `critical_gate_mandatory_suites`
and selected by the existing `RmeBillingDispute` token in both runner variants.

## 8. PostgreSQL concurrency (16.15, real services in a child process)

Races A–N plus the rollback variants, each asserting a real lock wait (> 1 s) and a
terminal outcome, never a database error: **19 passed / 142 assertions**.

| Race | Outcome proven |
|---|---|
| A / A2 designation vs review | committed designation authorizes; rolled back → `PERMISSION_MISSING` |
| B / B2 revocation vs review | waits on the designation `FOR SHARE`; committed revoke → refused; rolled back → allowed |
| C branch transfer vs review | real `BranchChangeApprovalService::approve()` commits → `BRANCH_OUT_OF_SCOPE` |
| D duplicate designation | partial unique index → one row, loser `BILLING_DISPUTE_REVIEWER_DUPLICATE` |
| E / E2 resolution vs Kontrol payment | resolved → `CONTROL_ONLY_PAYMENT_ORIGIN_NOT_DISPUTED`; rolled back → B paid, A unchanged |
| F / F2 origin report vs Kontrol payment | reported → B paid only; rolled back → refused |
| G dispute on B vs B payment | `BILLING_DISPUTE_ACTIVE`, no payment |
| H correction on B vs B payment | D10 refusal, no payment |
| I combined A+B vs report | refused whole, no payment on A or B |
| J two B-only payments | exactly one recorded |
| K / K2 retry vs first attempt | rolled-back first → one payment; committed first → retry refused |
| L origin correction applies vs B payment | B paid; A corrected to its own new version, no payment on A |
| M deactivation vs review | `ACTOR_UNUSABLE` |
| N cashier branch reassignment vs payment | refused (cashier's branch), no payment |

## 9. Financial regression

SQLite: RmeInvoiceCorrection, RmeManualPricing, RmeTariffAuthority, Invoice, the RME
cashier / payment / carry-over / receivable / Kontrol / cross-branch suites,
AccessControl, Auth, Navigation, sidebar and permission-grouping suites —
**1126 passed / 28 skipped / 0 failed / 7139 assertions**. Whole dispute directory
on the final code: see §15. The 28 skips are all
PostgreSQL-only race suites; they were run on PostgreSQL 16.15: **28 passed / 193
assertions**.

## 10. Mutation testing

Applied by content, verified changed, restored by content and checksum.

| Result | Count |
|---|---|
| MUTANTS_TOTAL | 26 |
| KILLED | 25 |
| SURVIVED | 0 |
| EQUIVALENT | 1 (M18, proven) |
| REVIEWED | 26 |

- **M17** (route door `reviewAny` always true) first **SURVIVED** — the controller's
  per-record policy masked the removed layer. A real test gap: test 37 now asserts
  the Gate ability and the route middleware directly → KILLED.
- **M18** (same-branch predicate in `isOutstandingOrigin`) — **EQUIVALENT, with
  proof**: an invoice's branch is set from its visit at creation
  (`RmeInvoiceService.php:208`), no repository rewrites an invoice's `branch_id`, and
  a visit's editable fields are only `chief_complaint`, `initial_treatment_id`,
  `initial_service_note`; discovery already restricts origins to the Kontrol visit's
  branch. Kept as defence in depth, mirroring the ordinary path's identical guard.
- Killed include: designation required, branch match, revocation, inactive actor,
  self-grant, Owner refusal, counter-role refusal, use-time eligibility, designee
  independence, origin dispute required, locked eligibility, allocation to B only, A
  status / A receivable untouched, D10, B dispute hold, version check, combined
  payment denial, routing, refusal audit, service-level confirmation, correction
  actors as non-independent (M26), and the two PostgreSQL lock mutants
  (designation `FOR SHARE`, origin `FOR UPDATE`).

## 11. Independent security review

Read-only adversarial review of the full change set: **CRITICAL 0, HIGH 0,
MEDIUM 1, LOW 1, INFO 10** — both actionable findings fixed before merge:

- **MEDIUM — a designee could certify an invoice they priced, then collect it.**
  Fixed: a designee's `ORIGINAL_CONFIRMED_CORRECT` is refused when they issued the
  invoice or recorded a payment on it (`RESOLVER_NOT_INDEPENDENT`); counter roles
  (Front Office, Kasir) can no longer be designated (brief §13).
- **LOW — "Owner stays read-only" enforced only at grant time.** Fixed: one
  eligibility rule (`isEligibleDesignee()`) is checked at the grant and on every use
  (policy, service, routing).
- INFO adopted: every designation refusal is audited with its stable code; the
  service itself requires the Kontrol-only confirmation.
- **Re-verification** of the fixes by the same reviewer: all four RESOLVED, no new
  fail-open / IDOR / lock-order / PII issue; **one new LOW** — the account that
  requested / decided / applied a correction of the invoice was not treated as
  non-independent. Fixed the same way (any correction actor on that invoice →
  `RESOLVER_NOT_INDEPENDENT`), proven by test 38 and mutant M26. Final:
  **CRITICAL 0, HIGH 0, MEDIUM 0, LOW 0 open**.
- INFO recorded, not changed (pre-existing or availability-only): see §13.

## 12. Rules synchronization

New rule **204** (40 sections). Modified in place, history kept: rule **203** §13 and
§18 (OPS-OQ-1b / OPS-OQ-1a marked ANSWERED), rule **202** §19 (OPS-OQ-1a answered).
Intentionally unchanged: rules **196–201** (no conflict: no correction, pricing,
actor-guard or Rp0 behaviour changed). Also synchronized: CLAUDE.md, the owner
decision register, the architecture document, the dispute SOP, the incident
escalation row T, `config/ci_runner.php`, `.sprint/current.yml`. The repository has
no separate rule-manifest file; CLAUDE.md is the rule index.

## 13. Residuals and open questions

- **No payment idempotency key on any path (pre-existing).** A full-amount retry is
  refused once the invoice is settled; two identical partial amounts that both fit the
  remaining balance are both recorded on every payment path. Not introduced here.
- **Rp0 Kontrol invoice with a disputed origin** cannot be settled (no remaining on B;
  the ordinary path is held) — availability only; belongs to the Rp0 policy work
  (D21g / D22 PENDING).
- **Visit-batch lock order (pre-existing).** `allocateVisitPayment` selecting both an
  origin and the Kontrol invoice as receivables locks them oldest-first, the reverse
  of the control path; PostgreSQL would abort one transaction (fail-safe, nothing
  written). The B-only path adds no new edge.
- **Deactivation does not revoke a designation** — the account is refused while
  inactive; revoke the row when the person leaves the responsibility (SOP §7).
- **Designation immutability is application-level** beyond the two CHECKs; never run
  the migration's `down()` on a database holding designations.
- **Kepala Cabang** lacks RME workspace access and so cannot be designated unless the
  owner grants that access separately — recorded, not changed.
- **Counter accounts as reviewers** — not authorized by OPS-OQ-1a (brief §13); refused.
  A new owner decision would be needed to change that.

## 15. Final local evidence (candidate code)

| Gate | Result |
|---|---|
| Dispute directory, SQLite | 146 passed / 29 skipped (PostgreSQL-only races) / 0 failed / 671 assertions |
| Dispute directory, PostgreSQL 16.15 | **175 passed / 0 skipped / 0 failed / 884 assertions** |
| CI suite-coverage + manifest contracts | 56 passed / 443 assertions |
| `vendor/bin/pint --test` (changed files) | passed |
| `git diff --check` | clean |
| `sprint:manifest-check` / `sprint:scope-audit --strict` | GO / GO (base = remote `d0e638ff`) |
| `foundation:devflow-check --strict`, `foundation:shared-service-audit --strict` | GO / GO |
| `foundation:security-compliance-check`, `foundation:ci-runtime-control-check --strict` | GO / GO |
| `foundation:cicd-enterprise-gate-check`, `foundation:enterprise-documentation-check` | GO / GO |
| `architecture:ui-governance-check --strict`, `foundation:roadmap-check --strict` | GO / GO |
| Graphify | updated (AST) |

The four DB-backed foundation checks first failed on a database connection: this
fresh worktree's environment pointed at a local server it had no credentials for (an
environment fault, not code). Re-run against the isolated PostgreSQL 16 container
they returned GO. Full Suite: governed by the temporary Full-Suite policy — reported
as run or SKIPPED in the post-merge evidence, never claimed passed.

## 14. Authoritative CI, merge, deployment, production verification

### 14.1 Candidate, CI, merge

| Item | Value |
|---|---|
| Base (remote-resolved) | `d0e638ff` |
| Candidate commit / tree | `2e87cdd0` / `31719409` |
| PR | #516 |
| Authoritative CI run (exact candidate SHA) | `40067255805` — **success** |
| Classifier | `gate_profile=unknown_high_risk`, `run_full_suite=required` (risk signal, not authorization) |
| NSF-R011 Critical Test Gate (job `120793308800`) | success — pgsql, **6555 passed / 34175 assertions**, `critical_test_exit_status=0`; the new suites visibly executed |
| CICD-CTRL Selective Module Gate (job `120793308825`) | success — 1586 / 674 (1 skipped) / 899 / 666 passed |
| NSF-R012 Quality, NSF-9 Release Safety & Smoke, NSF-10 Release Evidence, Phase 3 Android | success |
| NSF-R011 Critical Test Gate, self-hosted variant (job `120793309872`) | skipped — exactly one runner variant runs by design |
| NSF-R011 Full Suite Gate (job `120823074108`) | **SKIPPED** — `full_suite_defer_reason=FULL_SUITE_NOT_ENABLED_FOR_EVENT` |
| Merge commit / tree | `9e37b8557d10824d21eba9a1c802867120c9c058` / `3171940928ff77b2edba9081a22794964cae1911` — **tree identical to the candidate** |
| Post-merge push run on the merge SHA | `40078083924` — success; Full Suite **SKIPPED** with `full_suite_defer_reason=TEMPORARY_FULL_SUITE_POLICY_ACTIVE` (`policy_status=ACTIVE`) |

`FULL_SUITE: SKIPPED` on both runs. It is not claimed passed; the substitute evidence
is §15 plus the critical and selective gates above.

### 14.2 Deployment (inside the VPS)

| Item | Value |
|---|---|
| Production before | HEAD `5b1d4262` (rule-203 GO), tracked tree clean |
| Command | `bash scripts/deploy-vps-runner.sh start`, executed on `srv1730088` |
| Pre-deploy backup | `pre_auto_deploy_20261024-082731.sql`, verified by NSF-10 `foundation:backup-verify` |
| Result | `exit=0`, `DEPLOY OK: 20261024-082731`, `DEPLOY_HEAD_TARGET_MATCH=YES`, `DEPLOY_SNAPSHOT_CLEANED=YES` |
| Runtime isolation / secret permissions | 70 GO / 0 FAIL; GO |
| Automated smoke | WATCH — the only warning is `SMOKE-HTTP-HEALTH` on `http://127.0.0.1/login` (404 from the shared VPS's `default_server`; the canonical domain answers 200). Pre-existing, not a regression |
| Migration | `2026_10_24_200001_create_mst_rme_billing_dispute_reviewers_table` — Ran (batch 85) |
| Post-deploy seed (as `daengtisiams`) | `PermissionSeeder` + `permission:cache-reset` — no log growth. `RoleSeeder` deliberately NOT run: the new permission is granted to no role |
| Production after | HEAD `9e37b855`, tree `31719409`, tracked tree clean (only the pre-existing untracked `storage/runtime-home/`) |

### 14.3 Production verification (read-only, `default_transaction_read_only = on`)

Financial state, before == after (fingerprints over id/status/amount/version/branch
columns only — no patient data):

| Measure | Before | After |
|---|---|---|
| Invoices (count / total) | 7 / 7,548,000.00 | 7 / 7,548,000.00 |
| Payments (count / total) | 8 / 5,548,000.00 | 8 / 5,548,000.00 |
| Receivable (UNPAID+PARTIAL) | 2,000,000.00 across 2 invoices | identical |
| Disputes / corrections | 0 / 0 | 0 / 0 |
| `fp_invoices` | `e04fdbc3…` | `e04fdbc3…` |
| `fp_payments` | `3ebed335…` | `3ebed335…` |
| `fp_role_permissions` | `5c5f9319…` | `5c5f9319…` |
| `role_has_permissions` | 517 | 517 |
| `permissions` | 185 | 186 (`manage_rme_billing_dispute_reviewers`) |
| Audit rows | 1601 | 1601 |

The ONLY differences are the new permission row and the new migration row.

- `manage_rme_billing_dispute_reviewers`: exists; **0 role grants, 0 user grants**
  (Super Admin reaches it through `Gate::before`).
- `mst_rme_billing_dispute_reviewers`: present, **0 rows**; CHECKs
  `mst_rme_bd_reviewers_not_self_chk` and `mst_rme_bd_reviewers_revocation_chk` and the
  partial unique index `mst_rme_bd_reviewers_one_active_uq` present.
- In-process checks (no write): a Front Office account is not an eligible designee and
  `reviewAny` is false; a pure Owner account has `viewAny` true and `reviewAny` false;
  an account holding Super Admin keeps its existing authority; the Owner role holds
  only `view_rme_billing_disputes`.
- `RmePaymentService::payControlInvoiceOnly()` and `PAYMENT_SCOPE_CONTROL_ONLY` present;
  all 11 dispute/reviewer routes registered with their middleware.
- Invoice-correction pilot unchanged: SPN4 only, maker 29 / checker 1 (D1c, D1e).
- `https://daengtisia.online` `/login`, `/health/live`, `/health/ready`, `/health/lb`
  → 200; guests get 302 on the new routes; php8.3-fpm, nginx and the queue worker
  active; `rme:billing-dispute-status` verdict GO with 0 active disputes;
  `queue:failed` empty; debug OFF; maintenance OFF.
- **No designation, invoice, dispute or payment was created in production to prove
  behaviour.** `REAL_OPERATOR_ACCEPTANCE: NOT_PERFORMED`.

### 14.4 Residual found during verification (not caused by this sprint)

The application ERROR count moved 162 → 163. The new line is a database disconnect at
06:52 UTC (**14:52 WITA**): `unattended-upgrades` upgraded `zlib1g` at 06:52:29 UTC and
systemd restarted `postgresql@16-main` at 06:52:33–37 UTC — about 1.5 hours **before**
this deploy started (08:27 UTC). It is host maintenance, not this change. It is still
recorded because it restarted the clinical database during clinic hours; whether host
security updates may restart PostgreSQL unattended in that window is an operations
decision for the owner, not something this sprint changed.

### 14.5 Rollback readiness

`ROLLBACK_READY: YES`. `rme:billing-dispute-status` reports 0 active disputes, and the
rule-203 runtime `5b1d4262` is fail-closed for both decisions (§36 of rule 204): it
honours every dispute hold, ignores designations and offers no Kontrol-only path.
Never roll back below rule 203 while a dispute is active, never run the reviewer
migration's `down()` while designations exist, never rewrite a payment.

### 14.6 Scoped GO tag

`hardening-rme-billing-dispute-branch-reviewer-control-payment-1-go` — annotated, tag
object `7418c252`, peels to `9e37b855`; verified on local, origin and the VPS
(`git describe --tags --exact-match HEAD`). It scopes only this hardening and is
**not** a full prepayment-pilot GO (`FULL_PREPAYMENT_PILOT_GO: NO_GO`).
