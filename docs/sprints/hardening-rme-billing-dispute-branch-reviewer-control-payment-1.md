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
| OPS-OQ-1a (branch reviewer) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_IMPLEMENTED` |
| OPS-OQ-1b (Kontrol-only payment) | `OWNER_POLICY_APPROVED`, `REPOSITORY_POLICY_RECORDED`, `TECHNICAL_ENFORCEMENT_IMPLEMENTED` |
| Deployment | recorded in the post-merge evidence commit (merge SHA, deploy run, production verification) |
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

Recorded in the post-merge evidence commit: candidate SHA, CI run and job ids, merge
SHA and tree identity, VPS deploy run (`scripts/deploy-vps-runner.sh` inside the VPS),
production verification (read-only), financial fingerprint and the scoped GO tag
`hardening-rme-billing-dispute-branch-reviewer-control-payment-1-go`.
