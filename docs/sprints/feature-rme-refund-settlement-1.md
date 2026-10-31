# FEATURE-RME-REFUND-SETTLEMENT-1 — Refund settlement (Sprint 5/7)

Durable rule: `.cursor/rules/216-rme-refund-settlement.mdc`. Design, threat model and reconciliation
contract: `docs/architecture/rme-refund-settlement-design.md`. Operator SOP (Indonesian):
`docs/runbooks/rme-refund-settlement-sop.md`. Owner decisions: decision package §7.4 and §7.7.

Base: `c96366b9` (Sprint 4 implementation GO `feature-rme-billing-refund-request-approval-1-implementation-go`
plus its evidence commit). Target posture: `SPRINT5_IMPLEMENTATION_GO / FLAG_OFF / PRODUCTION_VERIFIED` —
never "full feature GO": the settler designation is pending the Owner and activation is a separate gate.

## Scope delivered

* Settlement record and events (`trx_rme_refund_settlements`, `trx_rme_refund_settlement_events`) with the
  state machine DISBURSING → SETTLED | OUTCOME_UNKNOWN | ABORTED; request and obligation gain `SETTLED`.
* Bank transfer and cash with method-specific evidence on a private disk (RF-9 C); server time only (RF-11);
  settler ≠ approver in the service and the database (RF-10 B); full amount only (RF-4).
* Deny-all authority: `settle_rme_refund` held by no role (`RoleSeeder::NO_ROLE_PERMISSIONS`), empty
  `pilot.settlers`, flag `rme.billing_refund_settlement` OFF and dependent on the refund request flag.
* RFS-1 net received adopted by every balance reader in one change (§3 of the design).
* Operational screens (`rme.refund-settlements.*`) and read-only oversight (`oversight.billing-corrections.
  refund-settlements.*`) with the RFS-6 gross / outflow / net period summary.
* A PostgreSQL-only defect found by the concurrency suite and fixed: the refund request events CHECK did not
  admit `SETTLED`; migration `2026_10_31_300002` widens it.

## Tests

| Suite | Cases |
|---|---|
| `RmeRefundSettlementLifecycleTest` | 38 (RED first: 38/38 failed before implementation) |
| `RmeRefundSettlementAuthorityTest` | 34 |
| `RmeRefundSettlementLedgerTest` | 16 |
| `RmeRefundSettlementReviewHardeningTest` | 18 (one regression per review finding) |
| `RmeRefundSettlementConcurrencyTest` (PostgreSQL 16 only) | 22 (14 races + 8 database backstops) |

Cross-module regression on PostgreSQL 16 (settlement, refund request, invoice correction, billing dispute,
clinic oversight, payment idempotency, access-control seed snapshot): 976 passed. The exact CI critical-gate
filter on SQLite: 7158 passed before the review fixes; the 7 failures it showed were the superseded `'*'`
oracle (5, now `RoleSeeder::grantsFor()`), the rule-glob check on not-yet-tracked files (1), and a pre-existing
SQLite-only last-day-of-month date comparison in `Dmo3DeferredMetricBacklogClosureTest` (1, not touched by
this diff; CI runs the gate on PostgreSQL).

Mutation testing: 29 mutants over the service, evidence check, ledger, readers, seeder and controller —
29 killed, 0 survivors (one first survived — the pre-input scope check, masked by the in-lock re-check — and
was killed by asserting that a malformed payload from outside the branch is still "not found").

## Independent security review

No CRITICAL or HIGH. Fixed with regression tests: M1 (a second settler could escalate then abort a payout that
was sent — abort is now the starter's alone), M2 (cashier receivable rows, oversight visit and the doctor
report still read gross), L1 (reference re-spelling — normalized key, across methods), L2 (PDF heuristic
bypasses — `#xx` decoding, Flate streams, encryption refused), L4 (pay button followed `Gate::before`), L5
(QueryException bindings could reach the log), L6 (composite FK binding `approved_by` to the decider), L7
(overlapping liability columns now labelled). L3 (flag-off POST) was already a 404 through the FormRequest.

Superseded contracts updated in place, with the reason recorded: `RmeInvoiceCorrectionGovernanceTest`
(oversight route count and refund names) and `RmeRefundRequestAuthorityTest` RR-SEC-11.

## Not done here (by decision)

* No settler is designated; no role holds the permission; the flag stays OFF.
* No bank API, auto-retry, partial refund, patient credit, cross-invoice offset or payment reversal.
* Activation is `ACTIVATION-RME-REFUND-SETTLEMENT-1`, after the refund request flag is live, the Owner names
  a settler per branch, and the SOP is trained.

## Evidence (2026-10-31, times WITA = UTC+8)

| Item | Value |
|---|---|
| Candidate | `1b59d4dd4e4288c5b5efb3079b504107897bada6`, tree `656ae3a87e7439eeaa05e6b98ce2d54fc2345f5f` |
| CI (pull_request) | run `41199606329` success on the candidate: Classifier, NSF-R012 Quality, NSF-R011 Critical, Selective Module, NSF-9, NSF-10, Phase 3 Android all success; the self-hosted Critical variant skipped by design |
| Full Suite | **SKIPPED** — not run on the PR event; on the post-merge push run `41204004884` the classifier recorded `full_suite_authorized=false`, `TEMPORARY_FULL_SUITE_POLICY_ACTIVE`. No PASS is claimed |
| Review | independent security review (no CRITICAL/HIGH); GitHub approval **not recorded** — the repository has one collaborator and no second reviewer was invented |
| Merge | PR #571 squash → `a550e00d7eef303d6f76a290d493c6ab48a35aa1`, tree `656ae3a8…` = candidate tree; parent `c96366b9` |
| Deploy | inside the VPS, `scripts/deploy-vps-runner.sh start`: `exit=0`, `DEPLOY OK: 20261031-130558`, `DEPLOY_HEAD_TARGET_MATCH=YES`, snapshot cleaned; migrations `2026_10_31_300001` and `300002` ran; runtime isolation 107 GO / 0 FAIL. The one smoke warning (`http://127.0.0.1/login` 404) is the known co-tenant on the loopback; the domain answers 200 |
| Post-deploy | `PermissionSeeder` (as `daengtisiams`, `firstOrCreate` only) + `permission:cache-reset`. `RoleSeeder` **not** run: no role grant changes |
| GO tag | `feature-rme-refund-settlement-1-implementation-go` (tag object `ea5942e4`) → `a550e00d`, exact match at the VPS HEAD |

Production verification (read-only; PostgreSQL in a read-only transaction; no tinker):

* Schema: both tables present, 0 settlements, 0 settlement events; 28 `trx_rme_refund_stl_*` constraints and
  13 indexes; the not-approver CHECK, the composite approver FK, the reference-key CHECK, the SETTLED proof
  CHECKs, the request `(id, decided_by)` unique and the obligation settlement FK all present; one-per-request,
  one-per-obligation, reference and evidence uniques are partial; the request, request-event and obligation
  status CHECKs admit `SETTLED`.
* Authority: 189 permissions; `settle_rme_refund` held by **0 roles and 0 users**; Super Admin still 188 grants;
  `pilot.settlers = []`, `pilot.branch_codes = [SPN4]` → `SETTLEMENT_AUTHORIZATION = DENY_ALL`.
* Flags: `rme.billing_refund_settlement`, `rme.billing_refund_request` and `rme.invoice_postpayment_correction`
  all **OFF** (default).
* Money: the financial fingerprint (payments 9 / 10.548.000,00 / max id 50; invoices 8 / 12.548.000,00 / max id 42;
  PAID 6, PARTIAL 1, UNPAID 1; items, receipts, document versions, corrections, obligations, refund requests) is
  **byte-identical** before and after the deploy.
* Runtime: `/login`, `/health/live`, `/health/ready`, `/health/lb` 200 over `https://daengtisia.online`; settlement
  and oversight surfaces answer a guest 302; `/storage/*` 403; `laravel.log` 1.443.432 bytes / 164 ERROR before and
  after (0 new); no failed jobs; nginx, php8.3-fpm and the queue worker active; env pilot, debug off, maintenance off.
* Not exercised live (no production credentials are used for a smoke test, and no settlement may be created): the
  authenticated flag-off 404 and the settler refusal. They are proven by `RmeRefundSettlementAuthorityTest`.

Posture: `SPRINT5_IMPLEMENTATION_GO / FLAG_OFF / PRODUCTION_VERIFIED`; `SETTLEMENT_ACTOR_ACTIVATION =
PENDING_OWNER_DESIGNATION`. This is not activation and not a full feature GO.
