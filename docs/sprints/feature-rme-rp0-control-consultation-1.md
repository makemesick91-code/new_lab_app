# FEATURE-RME-RP0-CONTROL-CONSULTATION-1 — Konsultasi & Kontrol Rp0 (Sprint 6/7)

**Program:** DAENGTISIAMS-RME-BILLING-CORRECTION · **Rule:** `.cursor/rules/217-rme-rp0-consultation-control.mdc`
**Owner decisions:** S6-D1 … S6-D8 (2026-11-01) — `docs/decisions/rme-rp0-control-consultation-owner-decision-package.md`
**ADR:** `docs/adr/0014-rme-zero-value-closure.md` · **SOP:** `docs/operations/rme-rp0-selesai-tanpa-biaya-sop.md`

> **Forward pointer (2026-11-01):** hardened by `HARDENING-RME-RP0-CLOSURE-OWNER-DECISIONS-AND-PAID-TREATMENT-1`
> (`docs/sprints/hardening-rme-rp0-closure-owner-decisions-and-paid-treatment-1.md`, rule 217 §9). S6-Q1/S6-Q2 were
> approved; S6-Q3 was revised, which superseded the S6-D1 sub-decision "every free line of a Kontrol". This record
> is kept as written.

## 1. Priority and tracker

The Owner deferred Sprint 2 and asked for Sprint 6. That is a prioritisation, not an approval of D21g,
D22 or D21c, and it closes nothing.

| Sprint | Status |
|---|---|
| 1 | GO |
| 2 | `NO_GO_OPERATIONAL_BLOCKED` (deferred, not closed) |
| 3 / 4 / 5 | `IMPLEMENTATION_GO`, flag OFF |
| 6 | this sprint — implementation, flag **OFF** |
| 7 | pending |

Main operational closure: **1/7** (unchanged).

## 2. Baseline (measured 2026-11-01, read-only)

- Repository base `e3e81b13`; production runtime `a550e00d` (tag `feature-rme-refund-settlement-1-implementation-go`).
- Production: 9 invoices (7 PAID, 1 PARTIAL, **1 UNPAID Rp0 = #36**), 10 payments. #36 is a Kontrol
  (TRT-CONS Rp0) whose visit was completed at 2026-10-11 15:23:34 UTC only because the patient paid an
  unrelated receivable (payment #47, invoice #38) in the same batch.
- Code: a Rp0 invoice is created `UNPAID`; a payment needs `amount ≥ 0.01`; every visit-completion path
  needs money received. A Rp0 Konsultasi on a new visit had **no** completion path.

## 3. What was built

| Area | Change |
|---|---|
| Status | `RmeInvoice::STATUS_NO_CHARGE` ("Tanpa Biaya"), terminal; `isNoCharge()`; not payable, not active, not paid |
| Schema | migration `2026_11_01_100001`: `zero_value_category`, `zero_value_closed_by` (FK users, RESTRICT), `zero_value_closed_at`; PG CHECKs `no_charge ⇔ provenance`, `no_charge ⇒ grand_total = 0`, category vocabulary; `down()` refuses while NO_CHARGE rows exist |
| Eligibility | `RmeZeroValueEligibility` (pure, stable codes) + `config/rme_zero_value_closure.php` (no env) |
| Transaction | `RmePaymentService::closeZeroValueInvoice()` beside `pay()` — same invoice lock, `assertCashierMayCollect`, `assertNoPaymentHold`, visit lock, eligibility, `closeAsNoCharge`, visit → completed, audit `RME_INVOICE_ZERO_VALUE_CLOSED`; idempotent |
| HTTP | `POST rme/cashier/{visit}/billing/{invoice}/zero-value-closure` (`rme.cashier.zero-value-closure.store`), thin controller, FormRequest (acknowledgment only, 404 while off), policy `closeZeroValue` (same scope as `pay`) |
| UI | cashier detail: "Selesai Tanpa Biaya" card replaces "Bayar Sekarang" for an eligible Rp0 invoice; refusal reason shown for an ineligible one; "Diselesaikan Tanpa Biaya" panel after closure; "Tanpa Biaya" label in every invoice status map; handoff board marks Rp0 invoices |
| Reporting | Owner KPI `rp0_visits` / `rp0_consultations` / `rp0_controls` + card; doctor performance `no_charge_item_count` + "Tanpa Biaya" filter; DQ1 accepts the status |
| Flag | `rme.zero_value_closure` default OFF, risk high, captured at config build |
| CI | token `RmeZeroValueClosure` in both critical-gate variants; three suites declared mandatory |

No new permission, no role or seeder change, no change to invoice issuance, payment, correction (D28),
dispute, refund or lab-candidate logic.

## 4. Evidence

Filled from measured runs only.

- New suites (after the security-review fixes): `RmeZeroValueClosureTest` (41),
  `RmeZeroValueClosureReportingTest` (8), `RmeZeroValueClosureConcurrencyTest` (6, PostgreSQL only).
  SQLite: 49 passed, 6 skipped (the PG-only races). PostgreSQL 16.15: Rp0 suites + `ClinicOversight` +
  `DataQuality` = **157 passed / 1678 assertions**, races A–F included.
- TDD note: the tests were written before the implementation, but the first test RUN happened after the
  implementation existed, so no red run was observed for the first version; the guards are instead proven
  non-vacuous by the mutation campaign (§5). The security-review fixes were each pinned by a test that the
  matching mutant fails (§5, M15–M22).
- DEVFLOW / governance, against a migrated PostgreSQL 16 database: `sprint:manifest-check` GO,
  `sprint:scope-audit --strict` GO (3 modules: ClinicOversight, Reporting, RmeInvoice),
  `foundation:devflow-check --strict` GO, `foundation:shared-service-audit --strict` GO,
  `foundation:ci-runtime-control-check --strict` GO, `foundation:security-compliance-check` GO,
  `foundation:cicd-enterprise-gate-check` GO, `architecture:ui-governance-check --strict` GO,
  `foundation:roadmap-check --strict` GO, `devflow:base-ref-check --strict` GO,
  `foundation:self-hosted-runner-check` GO (11/11, the three new mandatory suites selected).
  `php artisan view:cache` compiles.
- Broad billing regression on PostgreSQL 16: see §4a.

### 4a. Broad billing regression (PostgreSQL 16)

PostgreSQL 16.15 (throwaway container), counted from the junit log, not the console summary:
**3304 tests — 3304 passed, 0 failed, 0 errors, 0 skipped** (11 925 assertions, 1 818 s). Directories:
`RME`, `Owner`, `Reporting`, `Dashboard`, `RmeBillingDispute`, `RmeInvoiceCorrection`, `RmeManualPricing`,
`RmeOwnerReferenceRegistry`, `RmePaymentIdempotency`, `RmeRefundRequest`, `RmeRefundSettlement`,
`RmeTariffAuthority`, `LabOrder`, plus `LegacyRmeProgramClosureContractTest` (sprint-manifest contract).
Repo-wide `pint --test` passed; `git diff --check` clean.

## 5. Mutation campaign

Copy-based revert with a sha256 check after every mutant; zero `.mutbak` left behind. Each mutant was
run against `RmeZeroValueClosureTest` + `RmeZeroValueClosureReportingTest`.

| # | Mutant | Result |
|---|---|---|
| M01 | discount no longer refused (unit price only) | KILLED (after adding a defence-in-depth evaluator test — issuance can never write a discount on a zero-priced line) |
| M01b | whole free-line check removed | KILLED |
| M02 | voucher allowed | KILLED |
| M03 | Kontrol without a parent | KILLED |
| M04 | dispute/correction hold skipped | KILLED |
| M05 | cashier guard skipped | KILLED |
| M06 | idempotent early return removed | KILLED |
| M07 | visit status not checked | KILLED |
| M08 | corrected invoice allowed | KILLED |
| M09 | flag ignored in service | KILLED |
| M10 | any category on a new visit | KILLED |
| M11 | payment rows ignored | KILLED |
| M12 | total not checked | KILLED |
| M13 | visit not completed | KILLED |
| M14 | UNPAID check removed | KILLED (after adding a VOID/DRAFT Rp0 test — the first run SURVIVED, a real gap) |
| M15 | Kontrol parent not checked | KILLED |
| M15b | parent status not checked | KILLED |
| M15c | parent patient not checked | KILLED |
| M16 | lab line allowed | KILLED |
| M17 | "Bayar" hidden on every Rp0 again | KILLED |
| M18 | refusal not re-keyed to `zero_value` | KILLED |
| M19 | refusal alert not rendered | KILLED |
| M20 | audit line snapshot dropped | KILLED |
| M21 | DQ1 ignores NO_CHARGE payment rows | KILLED |
| M22 | oversight drops NO_CHARGE | KILLED |

**25 / 25 killed, 0 survivors.** The first campaign left two survivors (M01, M14); both were closed with
tests, not argued away.

## 6. Security review

Independent adversarial review (separate agent, reviewing the saved diff, not the mutating worktree).
Verdict: **no CRITICAL, no HIGH**; 2 MEDIUM, 6 LOW, 1 info. Threat table: fake zero-value invoice, D28
bypass, correction bypass, false visit completion, false revenue, receivable write-off, cross-branch and
cross-patient access, payment race, duplicate completion, audit manipulation, Super Admin business-rule
bypass, mass assignment and lock ordering — all SAFE.

| Finding | Disposition |
|---|---|
| MEDIUM-1 — price and the Kontrol link are operator-controlled, so one branch account can label a non-consultation treatment as a free Kontrol | Partly fixed: a Kontrol must now follow a real examined visit of the same patient (S6-Q1, fail closed). The rest is policy under D21 / S6-D3 — recorded as S6-Q3 for the Owner, not decided |
| MEDIUM-2 — with the flag on, every UNPAID Rp0 invoice lost "Bayar", stranding an ineligible Kontrol with carry-over | **Fixed**: only an eligible invoice trades "Bayar" for the closure; the handoff marker no longer promises eligibility |
| LOW-1 — oversight room-treatment view dropped NO_CHARGE | **Fixed** (`BILLED_INVOICE_STATUSES`) |
| LOW-2 — a free Kontrol lab line would close with no lab candidate | **Fixed fail-closed** (`ZERO_VALUE_LAB_LINE_EXCLUDED`, S6-Q2 pending owner confirmation) |
| LOW-3 — audit row could not reconstruct the decision after master-data edits | **Fixed**: line snapshot + visit type + parent in the audit row, no free text |
| LOW-4 — page and service could disagree on soft-deleted rows | **Fixed** (panel reloads with `withTrashed()`) |
| LOW-5 — refusals from inherited guards were invisible | **Fixed**: every refusal re-keyed to `zero_value`, rendered as `data-zero-value-error` |
| LOW-6 — NO_CHARGE is irreversible; "no payment row" not enforced at table level | DQ1-DATA-004 now counts a NO_CHARGE invoice with any payment row; irreversibility recorded as a residual (rule 217 §8) |
| Info — reporting additions render while the flag is off | Documented in rule 217 §7 (read-only, 0 until a closure exists) |

## 7. CI, merge, deploy

- PR #574, candidate `816bdd1c` (tree `3171daf7`). CI run `41248090496` on that exact SHA: **success** —
  CICD-CTRL Gate Classifier, NSF-R012 Quality Gate, CICD-CTRL Selective Module Gate, NSF-R011 Critical Test
  Gate (7413 passed, `critical_test_exit_status=0`; the log shows `PASS` for `RmeZeroValueClosureTest`,
  `RmeZeroValueClosureReportingTest` and `RmeZeroValueClosureConcurrencyTest`), NSF-9 Release Safety &
  Automated Smoke Gate, NSF-10 Release Evidence Gate, Phase 3 Android Clinic App Gate. The second NSF-R011
  Critical row is the inactive runner variant (exactly one runs by design).
- **NSF-R011 Full Suite Gate: SKIPPED** on the pull request under the standing temporary policy. Skipped is
  **not** a pass; no full-suite result is claimed.
- Squash-merged as **`4520a276`**; merge tree `3171daf7` is byte-identical to the CI-validated candidate.
- Deployed **inside the VPS** with `bash scripts/deploy-vps-runner.sh start` (detached, pid 500161, log
  `deploy-20261031-232655`): `exit=0`, `DEPLOY OK: 20261031-232657`,
  `DEPLOY_HEAD_TARGET_MATCH=YES (4520a276…)`, migration `2026_11_01_100001` DONE (32 ms), deploy gates GO,
  runtime isolation 107 GO / 0 FAIL / 0 SKIP.

## 8. Production verification (read-only, 2026-11-01)

| Check | Result |
|---|---|
| Production HEAD / tree | `4520a276` / `3171daf7` = merge commit; only untracked path is the preserved `storage/runtime-home/` (2026-09-05) |
| Migration | `2026_11_01_100001` Ran, batch 91; 0 pending |
| Schema | `trx_rme_invoices_no_charge_chk`, `_no_charge_zero_chk`, `_zero_value_category_chk`, `_zero_value_closed_by_fk` present |
| Flag | `rme.zero_value_closure` **enabled=false** (default) |
| Sprint 3 / 4 / 5 flags | `rme.invoice_postpayment_correction`, `rme.billing_refund_request`, `rme.billing_refund_settlement` all **false** — unchanged |
| Sprint 2 pilot flag | `rme.invoice_correction` enabled via env — unchanged (not touched) |
| Financial fingerprint | 9 invoices / Rp24.548.000 (PAID 7, PARTIAL 1, UNPAID 1); 10 payments / Rp22.548.000 — **identical to the pre-deploy baseline** |
| Historical invoices | #36 UNPAID Rp0, #42 PAID Rp5.000.000, #43 PAID Rp12.000.000 — untouched |
| NO_CHARGE rows / provenance columns set | **0** (no closure was performed; no fake Rp0 consultation created) |
| Refund settlements | 0 |
| Route | `rme.cashier.zero-value-closure.store` registered; a guest POST answers 419 (CSRF), never 500 |
| Health over `https://daengtisia.online` | `/login`, `/health/live`, `/health/ready`, `/health/lb` all **200** |
| Services | php8.3-fpm, nginx, queue worker **active**; `queue:failed` — no failed jobs |
| Application log | `laravel.log` **1 443 432 bytes before and after** the deploy — zero new bytes, zero new errors |

The capability is **deployed but inert**: with the flag off the route and FormRequest answer 404, the service
refuses, and every cashier page renders as before. Activation is
`ACTIVATION-RME-RP0-CONTROL-CONSULTATION-1`, a separate Owner decision.

## 9. Residuals

- The Kontrol batch path can still complete a Rp0 Kontrol visit through an unrelated parent payment,
  leaving the invoice UNPAID (#36 pattern) — rule 217 §8; owner decision needed to change it.
- Rp0 issuance governance (who may type Rp0 at issuance), vouchers, and any Rp0 correction path stay
  PENDING (D21g/D22 outside the closure scope; D28 unchanged).
- S6-Q1 / S6-Q2 (examined-parent and lab-line refusals) are engineering narrowings awaiting owner
  confirmation; S6-Q3 (operator-controlled price and Kontrol link — security review MEDIUM-1) is open.
- `NO_CHARGE` is terminal with no in-app undo; a mistaken closure needs an owner-approved data correction.
- Production visit #63 (SPN4, 2026-10-13, `cashier_pending`, no invoice) was observed during the audit;
  unrelated to Rp0, not touched.
