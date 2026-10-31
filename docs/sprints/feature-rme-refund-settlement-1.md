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

## Evidence

Recorded after merge and deploy in the evidence commit (CI run, merge SHA, deploy result, production
verification, GO tag).
