# REVISION-RME-MANUAL-PRICING-AUTHORITY-1 — owner D21 revised to manual cashier pricing

| Field | Value |
|---|---|
| Task id | `REVISION-RME-MANUAL-PRICING-AUTHORITY-1` |
| Type | `FOUNDATION_SPRINT` (financial authority across RmeInvoice + Tariff + Treatment + cashier UI; audit level 3) |
| Base branch | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (never `main`) |
| Base authority | `1b7337488f0243e6c0fc7caf9d2e0a0a4d8c011e` (canonical remote; the local base ref was stale at `9eabff07` and was not used) |
| Parent GO | `feature-rme-branch-tariff-provisioning-1-foundation-go` |
| Durable rule | `.cursor/rules/194-rme-manual-pricing-authority.mdc` |
| Decision register | `docs/decisions/rme-billing-correction-owner-decisions.md` — D21 (CURRENT) |
| Migration / seeder / permission change | none / none / none |
| GO tag | `revision-rme-manual-pricing-authority-1-go` — only if every §16 criterion passes |

## 1. Owner decision (revised D21)

The owner supplied, as task-supplied decision evidence on **2026-10-20 (WITA)**, a
revision of D21: **there is no mandatory official treatment tariff**. The authorized
cashier types the price of every line of the ORIGINAL invoice; a price may differ
between branches and between patients; the tariff master is optional; the original
invoice price is the immutable financial snapshot; an issued invoice changes only
through the Billing Correction workflow (maker-checker, branch authorization, audit,
version check, balance validation, immutable snapshots, payment concurrency).

It supersedes the 2026-10-17 D21 ("prices come from the branch tariff master",
tariff equality, correction only within tariff authority). D1, D7 and D10 are
unchanged; D1a (who applies a correction) is still pending and was not invented.

The register now carries a chronology table, the revised D21 as CURRENT, the old D21
renamed `SUPERSEDED 2026-10-20` (text kept as the historical record) and a
reconciliation matrix for D21a–D21i — nothing marked approved that the owner did not
decide (D21c discount policy and D21g intrinsically-free treatments stay
`PENDING_SUB_DECISION`; the rest are `NOT_APPLICABLE`).

## 2. What the tariff mandate actually did on production (before this change)

Measured from code at the base authority and the previous sprint's production
evidence — not assumed:

| Surface | Before | Risk under the revised policy |
|---|---|---|
| Original invoice pricing | Every branch TRANSITIONAL: cashier price kept, but a tariff was resolved, compared and stamped (`TRANSITIONAL_TARIFF_MATCHED` / `_DEVIATION` / `_NO_TARIFF`) | A manual price was labelled with tariff vocabulary; "transitional" implied a coming mandate |
| Strict mode | Reachable from an environment key (`RME_TARIFF_STRICT_BRANCH_CODES`) | One host edit away from refusing every non-tariff price |
| Cashier form | `data-tariff` on every option; selecting a treatment **prefilled the tariff price**; tariff hints; transitional banner | TLK1's 11 tariffs are unverified seeder defaults — the form proposed them as prices |
| Correction submit | Refused a proposed price differing from the tariff (`CORRECTION_TARIFF_*`) | A branch with no tariff could not correct at all |
| Correction approve/apply | `TARIFF_AUTHORITY_CHANGED` made a correction STALE when an optional tariff changed | Unrelated master-data edits invalidated approved work |
| Readiness | Reported every branch `BRANCH_TRANSITIONAL` WATCH | Permanent WATCH for the intended state |

## 3. Design

* **One pricing path, MANUAL for every branch.** `RmeInvoicePricingService::price()`
  keeps the cashier price after server validation; it reads no tariff (proven by a
  zero-`mst_tariffs`-query test). The only master-data read is
  `TreatmentRepositoryInterface::activeIds()` — one bounded query that answers "is
  every named treatment real, not deleted and active".
* **Strict mode is `LEGACY_SUPERSEDED`, not deleted.** The code path is retained and
  still unit-covered through in-memory config, but `strict_branch_codes` is a
  source-controlled empty list with no environment read (`global_permitted`
  precedent). Re-imposing a mandatory tariff needs a new owner decision AND a
  reviewed change of that file.
* **Correction is tariff-independent.** `RmeInvoiceCorrectionService` has no tariff
  dependency at all (constructor, imports, staleness, apply — pinned by a source
  scan). Corrected lines are stamped `CORRECTION_MANUAL_ENTRY` / `MANUAL`.
* **Provenance is honest.** New values `MANUAL_CASHIER_ENTRY`, `MANUAL_FREE_TEXT`,
  `CORRECTION_MANUAL_ENTRY`; `tariff_id` / `tariff_unit_price` stay NULL on manual
  lines. A manual price equal to a tariff is still MANUAL — coincidence is not
  authority. Historical rows (`NULL`, `TARIFF_*`, `TRANSITIONAL_*`) are never
  rewritten and render with historical labels.
* **The tariff master stays** — rows, approval provenance (rule 193), the readiness /
  template / import commands and the master-data CRUD — as optional reference.
  Readiness now reports `PRICING_AUTHORITY=MANUAL_CASHIER_ENTRY` and no longer emits
  `BRANCH_TRANSITIONAL`.

## 4. Files changed

| Area | Files |
|---|---|
| Pricing | `RmeInvoicePricingService`, `RmeInvoiceService`, `RmeInvoicePriceSource`, `RmeInvoicePricingRefusal` (+`INVOICE_TREATMENT_INVALID`), `TariffPricingMode` (+`MANUAL`), `BranchTariffAuthorityService` (default mode MANUAL), `config/rme_tariff_authority.php` (no environment read) |
| Treatment | `TreatmentRepositoryInterface` / `TreatmentRepository::activeIds()` |
| Correction | `RmeInvoiceCorrectionService` (tariff gate, tariff staleness and tariff dependency removed), `RmeInvoiceCorrectionProposalBuilder` (docblock) |
| Branch boundary / qty (review F1, F2) | `RmeInvoicePolicy::create(User, ?ClinicVisit)`, `RmeInvoiceController::create/store` (authorize with the visit), `RmeInvoiceService::create()` (scope re-check on the locked visit), `CreateRmeInvoiceRequest` (`qty max`), `RmeInvoicePricingService::MAX_QTY` |
| Readiness | `RmeTariffAuthorityReadinessService`, `RmeTariffAuthorityReadinessCommand` |
| UI | `RmeInvoiceController::create()`, `resources/views/rme/cashier/create.blade.php`, `resources/views/rme/cashier/show.blade.php` |
| Governance | rule 194 (new); supersession markers in rules 188, 190, 191, 192, 193; decision register; CLAUDE.md; `.sprint/current.yml`; `config/ci_runner.php` + both critical-gate filter variants (`RmeManualPricing`) |
| Tests | 4 new suites (53 tests); 5 superseded suites revised in place with `REVISED by` notes |

## 5. Server-side money validation

Integer cents throughout (`decimal(15,2)`, `MAX_CENTS = 999 999 999 999 999`). The
unit price is **required**; `amountToCents()` refuses with
`[INVOICE_AMOUNT_INVALID]` — never silently zero — for: empty string, `null`,
whitespace, exponent (`1e5`), thousands separators (`50,000`, `1.500.000`),
currency text (`Rp 50000`), three decimals (`12.345`), letters, non-finite. Negative
price/discount ⇒ `INVOICE_AMOUNT_INVALID`; discount above the line gross ⇒
`INVOICE_LINE_NEGATIVE`; overflow (checked before multiplying) ⇒
`INVOICE_TOTAL_OVERFLOW`. Submitted totals are ignored and recomputed. The FormRequest
keeps its shape rules, so ordinary failures are 422s.

## 6. Rp0 boundary

* Original invoice: unchanged pre-revision behaviour — an Rp0 line is accepted and
  reported as `zero_value_lines` in the audit. No new Rp0 privilege, no new Rp0 block
  (D21g / D11 / D22 still pending). Production invoice #36 untouched.
* Correction: every NEW Rp0 line or total is still refused
  (`CORRECTION_RP0_NOT_PERMITTED`), consultation included.
* The approved Rp0 ADJUSTMENT scope (Kontrol / Konsultasi Dokter, rule 188 §12) is a
  separate workflow that is not built — `RP0_ADJUSTMENT = NOT_ACTIVE`.

## 7. Activation posture (unchanged)

| Item | State |
|---|---|
| `FEATURE_RME_INVOICE_CORRECTION` | OFF |
| Correction operational permissions | granted to no role |
| Tariff exception permissions | granted to no role; `price_exceptions_enabled` source-controlled `false` |
| Strict tariff pricing | unreachable (`strict_branch_codes => []`, no environment read) |
| Post-payment correction / refund / Rp0 adjustment | `NOT_ACTIVE` |
| Finance Klinik / Data Analyst | read-only (pinned) |
| Laboratory Finance | untouched |

## 8. Test evidence (local)

New suites (`tests/Feature/RmeManualPricing`, token `RmeManualPricing`, declared in
`critical_gate_mandatory_suites`):

| Suite | Tests | Covers |
|---|---:|---|
| `RmeManualPricingInvoiceTest` | 25 | manual price with/without tariffs, per-patient / per-branch prices, free text, tariff edits never rewrite an invoice, historical rows untouched, server totals, 10 malformed-money inputs, negatives, discount bound, overflow (incl. refusal BEFORE multiplying), inactive / forged / soft-deleted treatment, Rp0 original, audit without patient data, environment cannot enable strict, zero tariff queries, form without autofill, tariff-less HTTP billing, input kept on error, no edit route, only the correction service mutates items |
| `RmeManualPricingCorrectionTest` | 14 | submit/approve/apply in a tariff-less branch, prices no tariff carries, discount within line, free-text invoice, tariff change is not STALE, server recompute, Rp0 refusal, malformed money / discount / inactive treatment, paid invoice refused, Super Admin self-approval refused, D10 payment block, genuine content STALE, idempotent apply + v0/v1, no tariff dependency |
| `RmeManualPricingGovernanceTest` | 6 | register + chronology + matrix, rule 194 + markers, flag OFF + permissions, strict/exceptions source-controlled, oversight read-only |
| `RmeManualPricingBranchBoundaryTest` | 8 | review F1: cross-branch GET/POST 403 with nothing written, service refusal for a direct caller, no-context Kasir fails closed, same-branch Kasir bills, whole-estate role unaffected; review F2: qty beyond storage refused at the form and in the service, largest storable qty accepted |

Superseded tests REVISED in place (each with a `REVISED by` note, none deleted):
`RmeTariffAuthorityResolutionTest`, `RmeTariffAuthorityInvoicePricingTest`,
`RmeTariffAuthorityCommandsTest`, `RmeTariffProvisioningProvenanceTest`,
`RmeInvoiceCorrectionTariffAuthorityTest`.

| Run | Result |
|---|---|
| RED before implementation | 26 failed (new suites) |
| `RmeManualPricing` + `RmeTariffAuthority` + `RmeInvoiceCorrection` | 303 passed, 0 failed (1265 assertions) |
| `RmeManualPricing` + `RmeTariffAuthority` (after the config comment fix) | 163 passed, 0 failed (693 assertions) |
| Financial regression #1 (18-token filter: `CashierBilling|RmePayment|RmeInvoiceRedesign|RmeBillingRefund|ClinicOversight|PatientOutstandingReceivableCarryOver|RmeDoctorCashierCompletionGate|RmeReceiptOnePage|Tariff|Treatment|RmeVisitConsent|Receivable|RmeInvoice|VisitDetailClinicalSectionOrder|RmeLabCandidate|LabIntegration|StressSeed|PilotBackupImport`) | **843 tests, 0 failed** (4219 assertions, 692.95 s), exit 0 |
| Financial regression #2 (after F1/F2; filter #1 + every suite that calls invoice creation directly: `RmeManualPricing|RmeExamConsent|RmePatientFormatConsent|RmePdfPrintHardening|RmeLabWorkflowPolish|RmePilotDataEntryHardening|DoctorCashierSyncQueue|CashierPayment|CashierReceivable|RmeSmokeTestRoute|Sprint59EditableWorkflow|ObservabilityConsole|Dmo3DeferredMetric|CriticalGateSuiteCoverage|DevflowSprintTooling`) | **1203 tests, 0 failed** (5588 assertions, 1119.40 s), exit 0 — no test relied on the cross-branch gap |
| Final local sweep (`RmeManualPricing` + `RmeTariffAuthority` + `RmeInvoiceCorrection` + `tests/Feature/Cicd` + `LegacyRmeProgramClosureContractTest` + `DevflowSprintToolingTest`) | **632 tests, 0 failed** (3064 assertions), exit 0 |
| Critical-gate selection (`pest --list-tests` with the CI filter; both runner variants carry an identical filter) | `RmeManualPricing{Invoice 34, Correction 14, Governance 6, BranchBoundary 8}` selected |
| Governance gates (`sprint:manifest-check`, `sprint:scope-audit --strict`, `foundation:devflow-check --strict`, `foundation:shared-service-audit --strict`, `foundation:ci-runtime-control-check --strict`, `foundation:security-compliance-check`, `foundation:cicd-enterprise-gate-check`, `foundation:enterprise-documentation-check`, `foundation:roadmap-check --strict`) | all **GO** (the DB-touching ones run against the PG16 scratch database — a fresh worktree has no database) |
| `vendor/bin/pint --test --dirty`, `git diff --check`, `php artisan view:cache` | pass |
| PostgreSQL **16.15** (production major.minor), `RmeManualPricing` + `RmeInvoiceCorrection` + `RmeTariffAuthority` | **309 tests, 0 failed** (1472 assertions), exit 0, **0 skipped** — the 8 `RmeInvoiceCorrectionConcurrencyTest` cases (D10 races, single active correction, mid-apply payment) and the 2 provisioning concurrency cases executed with real row locks (3–8 s each) |

Local runs print one warning per test because a fresh worktree has no application
environment file (CI provisions an empty one — CICD-CRITICAL-GATE-FILE-GET-CONTENTS-WARN-1).
Warnings are not failures and are not counted as passes of anything else.

## 8a. Independent security review

An independent adversarial reviewer read the whole diff against a 13-point threat
model (money validation, client totals, treatment validity, provenance, branch
isolation, invoice immutability, correction maker-checker / D10 / staleness /
idempotency, flags and permissions, audit PII, XSS / client price authority, Rp0,
concurrency, reachability of the retained strict code).

**Introduced by this diff: CRITICAL 0, HIGH 0, MEDIUM 0, LOW 0.** Two PRE-EXISTING
findings were raised; the brief requires MEDIUM to be fixed, and both were fixed
here because manual pricing raises their weight:

| # | Severity | Finding | Fix |
|---|---|---|---|
| F1 | MEDIUM (pre-existing) | `RmeInvoicePolicy::create()` checked only `manage_rme_billing`; view / pay / viewReceipt already applied the working-branch scope. A Kasir working at branch A could open the billing form of a branch-B visit (sequential ids) and ISSUE its invoice at prices they typed. Under manual pricing the typed price is the authoritative snapshot, and the old tariff-deviation label that would have flagged it is gone. | Policy `create(User, ?ClinicVisit)` applies `RmeWorkingBranchScope`; both controller actions authorize with the visit; **and** `RmeInvoiceService::create()` re-checks `allows($cashier, $visit->branch_id)` on the LOCKED visit (`AuthorizationException` ⇒ 403). Context-bound role with no working branch fails closed; whole-estate roles unaffected. |
| F2 | LOW (pre-existing) | `items.*.qty` had no upper bound; qty `2147483648` reached the PostgreSQL `integer` column and answered 500 (`SQLSTATE 22003`), contradicting "validation failures are 422s". | `RmeInvoicePricingService::MAX_QTY = 2 147 483 647` — the **storage** bound, not a business cap (none decided): FormRequest `max:` and an in-service `[INVOICE_QTY_OVERFLOW]` refusal for direct callers. |

Regression suite `RmeManualPricingBranchBoundaryTest` (8): RED 6 failed / 2 control
cases passing before the fix; 8 passing after.

Notes recorded, not changed (pre-existing, outside the brief): `show.blade.php`
prints `unit_price` with `number_format(…, 0)`, so a fractional manual price such as
175 000.50 displays as "Rp 175.001" (the stored value is exact); applying a
correction overwrites a changed line's historical `price_source`, and the frozen
document snapshot does not carry provenance (correction is OFF). The correction
builder's own `MAX_QTY = 10 000` is unchanged — harmonising it with the original
invoice is a product decision, not taken here.

## 8b. Mutation testing

Copy-restore harness (each mutant applied by exact substitution, the file restored
by copy and its SHA-256 asserted identical; never `git checkout --`). Run against
the targeted suites, not the whole repository.

| # | Mutant | Result |
|---|---|---|
| M01 | manual line: treatment-validity check skipped | KILLED (2) |
| M02 | blank unit price becomes zero | KILLED (3) |
| M03 | manual line labelled `TARIFF_MATCHED` | KILLED (5) |
| M04 | `strict_branch_codes` read from the environment | KILLED (2) |
| M05 | correction stamps `TARIFF_MATCHED` | KILLED (1) |
| M06 | correction Rp0-line refusal removed | KILLED (1) |
| M07 | maker ≠ checker removed | KILLED (1) |
| M08 | D10 payment block removed | KILLED (1) |
| M09 | content-fingerprint staleness removed | KILLED (1) |
| M10 | discount above the line allowed | KILLED (2) |
| M11 | per-line overflow guard removed | round 1 **SURVIVED** — the running-total guard emits the same code, so a code-only assertion could not tell which fired. Test added (refusal names the item, BEFORE the int×int product becomes a float) ⇒ **KILLED (1)** |
| M12 | `activeIds()` stops filtering `is_active` | round 1 **INVALID RUN** — the substitution hit the identical clause in `listActive()`. Re-targeted to `activeIds()` ⇒ **KILLED (1)** |
| M13 | manual-pricing notice removed from the form | KILLED (1) |
| M14 | thousands separators accepted as money | KILLED (4) |
| M15 | invoice-create policy branch scope removed (F1) | KILLED (2) |
| M16 | invoice-service branch check removed (F1) | KILLED (2) |
| M17 | in-service qty storage bound removed (F2) | KILLED (1) |
| M18 | FormRequest qty bound removed (F2) | KILLED (1) |

**TOTAL 18 · KILLED 18 · SURVIVED 0 · EQUIVALENT 0** (one real test gap closed,
one invalid run re-executed).

## 8c. Graphify

`graphify update .` (v0.8.35, AST-only) in the worktree: **38 952 → 39 098 nodes
(+146), 56 879 → 56 892 edges (+13), 3 956 → 4 080 communities.**
`RmeInvoicePricingService → TreatmentRepositoryInterface` is a direct 2-hop
import path. Limitation recorded honestly: the rebuilt graph still carries an
`imports` edge from `RmeInvoiceCorrectionService.php` to a `BranchTariffAuthorityService`
node (whose source file the graph attributes to `RmeTariffTemplateCommand.php`),
although the file no longer references the class — `grep` returns only the
`TariffPricingMode` import, and the source-scan test pins the absence. The graph is
navigation, not evidence; the test is the authority.

## 9. Release evidence (filled from real output after deployment)

| Gate | Result |
|---|---|
| Mutation testing | 18/18 KILLED (see §8b) |
| Independent security review | PASS after fixing F1 + F2 (see §8a) |
| PR | #495 → base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| Candidate | `ce3dfc4efce77e6f356b7ffd880cfec026f48616`, tree `b385b3fc180fbdc7bb282bc16f5d8f08b806740e` |
| Authoritative CI on the exact candidate SHA | run `39457174449` — **success**: NSF-R012 Quality, CICD-CTRL Classifier (`gate_profile=unknown_high_risk`), CICD-CTRL Selective Module, **NSF-R011 Critical 6014 passed / 31 867 assertions, `critical_test_exit_status=0`** (all four `RmeManualPricing*` suites PASS in the log), NSF-9 Release Safety & Smoke, NSF-10 Release Evidence, Phase 3 Android gate. The second Critical row is the inactive runner variant (skipped by design). |
| Full Suite | **SKIPPED** — `full_suite_authorized=false`, `full_suite_defer_reason=FULL_SUITE_NOT_ENABLED_FOR_EVENT`, `temporary_full_suite_policy_active=true` (job had 0 steps). `run_full_suite=required` is the classifier's risk signal, not an authorization. `FULL_SUITE_EXECUTION_COUNT=0`. **Not a pass.** |
| Post-merge push run on the merge SHA | run `39478357209` — **success**: Quality, Classifier (`unknown_high_risk`), Selective Module, **NSF-R011 Critical 6014 passed / 31 867 assertions, exit 0**, NSF-9, NSF-10, Android gate. Full Suite **SKIPPED** (`full_suite_defer_reason=TEMPORARY_FULL_SUITE_POLICY_ACTIVE`) — not a pass. |
| Merge | squash `576e8aaeafa29186251da18f47ab2ce84c751c53`, tree `b385b3fc…` **== candidate tree**, parent `1b733748` (base did not move) |
| VPS deploy | `bash scripts/deploy-vps-runner.sh start` run INSIDE the VPS; run `20261020-185122`: **`exit=0`, `DEPLOY OK`, `DEPLOY_HEAD_TARGET_MATCH=YES`**, `DEPLOY_SNAPSHOT_CLEANED=YES`; pre-migrate backup `pre_auto_deploy_20261020-185122.sql` (32 104 166 B, NSF-10 backup-verify GO); **Nothing to migrate**. Production HEAD/tree = `576e8aae` / `b385b3fc`. The deploy log's WATCH lines are pre-existing and equal in count to the previous deploy (4): PgBouncer not installed (non-blocking, routing disabled), release-evidence `local` profile optional artefacts, `SMOKE-HTTP-HEALTH` loopback 404 (co-tenant nginx shadow — the canonical domain answers 200). |
| Read-only production verification | see §9a |
| GO tag | `revision-rme-manual-pricing-authority-1-go` → `576e8aae` (the deployed runtime commit; exact match locally, on origin and at VPS HEAD). This evidence commit is docs-only and is **not** deployed. |

### 9a. Production verification (read-only, 2026-10-20 ~19:05 UTC / 2026-10-21 03:05 WITA)

| Check | Before (16:25 UTC) | After |
|---|---|---|
| Health `/login` `/health/live` `/health/ready` `/health/lb` (https://daengtisia.online) | 200 ×4 | 200 ×4 |
| Guest `/rme/cashier`, `/rme/cashier/1/billing/create` | — | 302 (auth redirect, no 500) |
| Public storage probe | — | 403 |
| `rme:tariff-authority-readiness` mode | 4 × TRANSITIONAL, WATCH `BRANCH_TRANSITIONAL` ×4 | **4 × MANUAL**, `PRICING_AUTHORITY=MANUAL_CASHIER_ENTRY`, no `BRANCH_TRANSITIONAL`; only `TARIFF_PROVENANCE_UNVERIFIED TLK1` (tariff-master hygiene) |
| `strict_branch_codes` / `price_exceptions_enabled` | `(none)` / false | `[]` / false (`config:show`) |
| `rme.invoice_correction` | OFF (`via=default`) | OFF (`via=default`) |
| Environment / debug / maintenance | pilot / OFF / OFF | pilot / OFF / OFF |
| Failed jobs | — | none |
| Invoices (count / Σ grand total) | 7 / 7 548 000.00 | 7 / 7 548 000.00 |
| Payments (count / Σ amount) | 8 / 5 548 000.00 | 8 / 5 548 000.00 |
| Corrections | 0 | 0 |
| Tariffs | 11 | 11 |
| Invoice items (with recorded `price_source`) | 8 (0) | 8 (0) — no historical row rewritten |
| Max invoice id | 41 | 41 |
| `sys_audit_logs` rows | 1601 | 1601 |
| `laravel.log` bytes / ERROR lines | 1 437 201 / 162 (last write Oct 9) | 1 437 201 / 162 — **0 new errors** |
| Deployed source | — | cashier form carries `data-pricing-authority="MANUAL_CASHIER_ENTRY"` and no `data-tariff`; `RmeInvoicePolicy::create` and `RmeInvoiceService::create` carry the working-branch scope; `RmeInvoiceCorrectionService` holds no `BranchTariffAuthorityService` reference |

No invoice, payment, correction, tariff or patient row was created, edited or
deleted; no real correction was executed; no flag or permission changed.
Pre-existing, not task-owned, left untouched: an untracked `storage/runtime-home/`
directory in the production checkout.

**Not proven in production** (no fake data is created to manufacture it): an
authenticated cashier rendering the form and billing a real visit. The manual path
is proven by the automated suites and by the deployed source; the first real
invoice will carry `MANUAL_CASHIER_ENTRY`.

## 10. Rollback

Code rollback to the previous GO (`feature-rme-branch-tariff-provisioning-1-foundation-go`)
with `scripts/rollback-vps.sh` run inside the VPS. The release writes no row, adds no
migration and changes no permission, so there is no data to undo. Invoices created
after the release keep their `MANUAL_*` provenance and are never re-priced. The
previous code does not know those values: its `match` falls through to the default
label "Belum tercatat (sebelum otoritas tarif)" and the neutral tone — a cosmetic
mislabel on the invoice detail page, not a money change. A rollback also restores
the environment-reachable strict mode and the tariff autofill, so it reinstates the
superseded policy and must itself be an owner decision.

## 11. GO criteria

Revised D21 recorded; mandatory tariff dependency removed and verified; money
validation intact; correction compatibility proven; Rp0 safety intact; D1/D7/D10
intact; flag OFF; security review PASS; regression PASS; rules synced; required CI
PASS; merge verified; VPS deploy `exit=0` + `DEPLOY OK`; production verification
PASS. A skipped Full Suite is reported as SKIPPED.
