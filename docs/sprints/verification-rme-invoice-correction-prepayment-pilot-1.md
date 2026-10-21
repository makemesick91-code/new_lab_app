# VERIFICATION-RME-INVOICE-CORRECTION-PREPAYMENT-PILOT-1

Verification of the live pre-payment RME invoice correction pilot (SPN4 — Cabang
Sunu) activated by `ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2`. Rule 196
(new) records the verification contract; rule 195 remains the activation contract.

**Outcome: `PILOT_ACTIVE`, Decision WATCH. No runtime defect found, no runtime
change, no deploy, no financial mutation, no tag.** The pilot cannot be closed
yet, for two reasons that no automation may remove:

1. **No eligible real case exists** — the only UNPAID SPN4 invoice is Rp0
   (D21g PENDING); 0 positive UNPAID invoices.
2. **The operators were not on shift** — user 29 held no live working context and
   neither pilot account had a session during the verification window.

All times UTC unless marked WITA (UTC+8).

## 1. Baseline (measured 2026-10-21 ~06:04 UTC, read-only)

| Item | Value |
|---|---|
| Canonical base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `8bb9e033` (PR #499, docs only) |
| Production HEAD / tree | `61a297959367cffd2f9c5b1ca93b4a017b123962` / `148f6e1f…` |
| Production tag (exact match) | `activation-rme-invoice-correction-prepayment-2-pilot-readiness-go` |
| Runtime delta base vs production | none (`8bb9e033` adds docs only) |
| Production working tree | 1 untracked pre-existing dir `storage/runtime-home/` (not created by this task) |
| Deploy lock | free (`flock -n` succeeded); no deploy process |
| Open PRs / running CI | 0 / 0 |
| Health over `https://daengtisia.online` | `/login`, `/health/live`, `/health/ready`, `/health/lb` → 200 |
| Services | nginx, php8.3-fpm, postgresql, queue worker → active |
| Disk | 87 G free of 96 G |
| `laravel.log` | 1 437 201 bytes, unchanged by every probe in this task |
| Flag | `FEATURE_RME_INVOICE_CORRECTION=true`, `env_resolution=env`; environment file 640 `root:daengtisiams` |
| Failed jobs | 0 |

### Financial baseline (no patient data)

| Measure | Count | Sum |
|---|---|---|
| Invoices (incl. trashed: identical) | 7 | Rp 7.548.000 |
| Invoice items | 8 | Rp 7.548.000 |
| Payments | 8 | Rp 5.548.000 |
| Payment batches | 1 | — |
| Receivable (UNPAID+PARTIAL, grand total − paid) | — | Rp 2.000.000 |
| Corrections / lines / events / document versions | 0 / 0 / 0 / 0 | — |
| `sys_audit_logs` | 1601 | — |
| `role_has_permissions` | 514 | — |
| `model_has_permissions` | 0 | — |

Per branch: every invoice is SPN4 — PAID 5 (Rp 3.548.000), PARTIAL 1
(Rp 4.000.000), UNPAID 1 (Rp 0); every `correction_version` = 0. Identical to the
PREPAYMENT-2 post-activation fingerprint: nothing moved since activation.

## 2. Pilot configuration (production, in-process, DB session read-only)

| Check | Result |
|---|---|
| Feature guard | enabled |
| Declared / admitted codes | `[SPN4]` / branch id 5 |
| `admits()` per branch | SPN4 true; TLK1, LDK2, ATG3, MAIN, SYN4A false |
| Forged ids 999999 / 0 / −5 | false |
| Declared `sun4` (alias) | canonicalized to SPN4 (id 5) — rule 195 §16 |
| Declared `SPN` (prefix) / `MAIN` / empty | admits nothing |
| `apply_rme_invoice_correction` holders | user 1 only |

Route posture (all `web,auth` + permission middleware): index/show/document
versions need any correction permission; `approvals`, `approve`, `reject` need
`approve_…`; `create`, `store`, `cancel` need `request_…`; `apply` needs `apply_…`.
Mutations are POST only. Guest GET on index/approvals/show/document-version → 302
to `/login`.

## 3. Accounts (D1d) and RBAC

| Account | Exists/active | Roles | Pinned | Eligible pilot branches | On shift now |
|---|---|---|---|---|---|
| 29 (maker) | yes / yes | Front Office | SPN4 | SPN4 | **no** |
| 1 (checker) | yes / yes | Super Admin, Owner | governance | SPN4 (live scope = all 4 RME branches) | yes (governance scope) |

User 29's online-context row reads `status=online` with `last_seen_at`
2026-10-17 07:25:46 — the probe applies the expiry and reports off shift, and left
the row untouched. No session row exists for user 1 or 29 (nobody logged in).

Effective correction permissions by role (production, every role): Kasir
request; Admin Klinik approve; Front Office request + approve; Super Admin all
three; **every other role none**, including Owner, Supervisor RME, Doctor,
Perawat, Finance Klinik, Data Analyst, Finance (lab), Admin Lab. No direct
user→permission grant exists. Finance Klinik and Data Analyst hold only
`view_billing_correction_oversight` + `export_oversight_data` (read-only).

**Reach finding (informational, consistent with D1d):** 8 active Front Office
accounts (7, 8, 16, 17, 29, 30, 31, 32) + user 1 hold request/approve, and the
probe (`--user=` for each) reports **all 8 Front Office accounts eligible for SPN4**:
only user 29 is branch-pinned; 30/31/32 are not pinned in production. D1d already
records that the grant reaches every Front Office account working in SPN4; maker ≠
checker is enforced per account in the service. Rule 196 §13 records this and
forbids inferring reach from a planned pin order.

## 4. Readiness probe

`runuser -u daengtisiams -- env HOME=/tmp php artisan rme:invoice-correction-readiness --maker=29 --checker=1 --json`
(06:04:02 UTC) →

```
state: PILOT_ACTIVE      flag: enabled (env)      pilot: [SPN4]
pair: maker 29 / checker 1, distinct, valid=true, shared [SPN4], live_now=false
positive_unpaid_unsettled_invoices_in_pilot: 0      active corrections: none
finding: WATCH PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT (29 eligible for SPN4, no working context now)
decision: WATCH (exit 0)
```

Re-run at the end of the task: see §11.

## 5. Eligibility (rule 195 §23) and case authorization

| Requirement | SPN4 reality |
|---|---|
| UNPAID, zero payments, active visit, no lab candidate | invoice #36 only: UNPAID, 0 payments, visit `completed`, 0 lab candidates |
| Positive total | **Rp 0 — not eligible** (new Rp0 lines/totals refused; D21g PENDING; historical Rp0 untouched) |
| Positive UNPAID invoices in the pilot | **0** |
| Case-specific owner authorization | **PENDING** — no case exists to authorize |

Invoice #36 was not modified. No invoice, visit, patient, payment or correction
was created to manufacture a case (rule 196 §8).

## 6. What was NOT performed, and why

| Step | Status | Reason |
|---|---|---|
| Authenticated login of user 29 + SPN4 selection | NOT_PERFORMED | operator not on shift; never impersonated |
| Authenticated login of user 1 as checker | NOT_PERFORMED | no correction to approve; a login alone proves nothing the probe does not |
| Correction submit / approve / auto-apply | NOT_PERFORMED | no eligible case; no case authorization |
| D10 live state, v0/v1, reconciliation of a real correction | NOT_APPLICABLE | nothing submitted |
| Deploy | NOT_REQUIRED | no runtime delta (`NO_RUNTIME_DELTA`) |

## 7. Regression (local; same code as production `app/`)

| Run | Result |
|---|---|
| SQLite, filter `RmeInvoiceCorrection\|RmeManualPricing\|RmePayment\|CashierBilling\|ClinicOversight\|FrontOfficeRole\|RmeTariffAuthority\|RmeInvoiceRedesign\|RmeBillingRefund` | **581 passed, 15 skipped (PostgreSQL-only), 0 failed** — 3344 assertions, 502 s |
| PostgreSQL 16.15 (throwaway `postgres:16` container, migrated), filter `RmeInvoiceCorrection` | **204 passed, 0 skipped, 0 failed** — 1023 assertions, 321 s |

Local PHP is 8.5 (production pool and CI are 8.3); the authoritative runtime CI is
the PREPAYMENT-2 evidence (PR #498 candidate, 6072 critical tests passed).

PostgreSQL race coverage: two simultaneous submissions (exactly one wins); payment
commits first → waiting submission refused (D10); two simultaneous approvals
serialize, the second is a no-op; approve commits items + totals + version + v0/v1
atomically; STALE when a payment exists / the invoice changed / the version moved
(nothing applied); payment blocked for SUBMITTED and APPROVED on standard, control,
carry-over-parent and visit-receivable paths; payment resumes after REJECT, CANCEL
and STALE; self-approval refused for Front Office and for Super Admin; repeated
approval idempotent; submit/approve/apply refused outside the pilot while
reject/cancel stay available; the probe never writes a stale online context.

## 8. Graphify

Canonical `graphify-out/graph.json` (2026-10-13) predates the correction module (0
correction nodes), so an AST-only graph was rebuilt in the task worktree with
graphify 0.8.35 (`graphify update .`, ~61 s): 39 048 nodes, 57 018 edges, 71
correction nodes. Callers of the correction mutations (`submit/approve/apply/
reject/cancel/markStale`) are only the correction controller, policy and service.
`grep` confirms the only writer of invoice totals + `correction_version` outside
invoice creation is `RmeInvoiceRepository::applyCorrectionTotals()`, called only
from `RmeInvoiceCorrectionService`. No unexpected mutation path. The canonical
graph was not refreshed: this task changes no code.

## 9. Independent security review

Performed by an independent read-only reviewer against the checkout whose `app/`
equals production `61a29795`; every claim checked against code with line evidence.
**0 CRITICAL, 0 HIGH, 0 MEDIUM.**

| # | Claim | Verdict |
|---|---|---|
| 1 | Self-approval refused in the service under the invoice→correction lock, Super Admin included (`SELF_APPROVAL`; recovery apply `SELF_APPLICATION`) | HOLDS |
| 2 | Approve + apply in one transaction; any refusal leaves no APPROVED row; replay is a no-op; APPLIED is terminal at the model | HOLDS |
| 3 | SPN4 allowlist enforced under the lock for submit/approve/apply; no request `branch_id`; out-of-scope ids answer 404 | HOLDS |
| 4 | D10 on every payment path (pay, control + every parent, visit + every receivable) under the lock, whole batch rolled back | HOLDS |
| 5 | Payment bound to `correction_version` with strict equality on the locked row, all paths | HOLDS |
| 6 | v0 frozen once, v1 created at apply, versions create-only (model guards + unique index + CHECK); no receipt for an unpaid invoice | HOLDS |
| 7 | No new Rp0 line/total; 0 ≤ discount ≤ gross; money/qty/overflow bounds; server-side totals re-asserted at apply | HOLDS |
| 8 | The readiness probe writes no DB row (expiry path in an always-rolled-back transaction) and refuses root | HOLDS |
| 9 | No writer of corrections / invoice totals / `correction_version` outside the correction service | HOLDS |
| 10 | Audit/event payloads carry ids only (free text by length) | HOLDS |

Additional findings (none blocks the pilot; none was changed in this task):

- **LOW — latent fail-open in the payment branch-scope check (pre-existing,
  FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1).** `RmePaymentService::assertInvoicePayable()`
  checks `RmeWorkingBranchScope::allows(Auth::user(), …)` instead of the cashier
  passed in, and `branchIdsFor(null)` returns every RME branch. Verified by direct
  reading. Unreachable today: the only callers are the three authenticated
  `RmePaymentController` actions, where `Auth::user()` is the request user. A future
  job or command calling `pay()` would bypass the cashier's branch scope.
  Recommended follow-up: `FIX-RME-PAYMENT-BRANCH-SCOPE-ACTOR-1` (pass `$cashier`,
  fail closed on null, own tests and release).
- INFO-1 — the probe's root refusal depends on `posix_geteuid`. Verified present on
  production (PHP 8.5.8 CLI, which `php artisan` uses, and php8.3). `can()` may
  write the Spatie permission cache file — the reason the probe runs as
  `daengtisiams` (rule 195 §28a).
- INFO-2 — an ADD line's `doctor_id` is checked for existence only (same as invoice
  creation); re-attributing an existing item to another doctor is refused.
- INFO-3 — approve/apply gate on the correction's stored, immutable `branch_id`; no
  code path moves an invoice between branches.
- INFO-4 — patient merge rewrites `trx_rme_invoice_corrections.patient_id`
  (not money, status, version or fingerprint).
- INFO-5 — refusals raised as `ValidationException`/`LogicException` during approve
  roll back cleanly but write no refusal audit row (only `RmeInvoiceCorrectionDenied`
  is audited).
- INFO-6 — a no-op replayed approval still flashes the "approved and applied"
  message (cosmetic).

Not provable by reading: runtime deadlock freedom (reasoned — every path locks
invoice before correction; payments never lock correction rows; the PostgreSQL
suites in §7 exercise the races).

## 10. DataViz

The `dataviz` skill is now listed as available (it was reported unavailable in
PREPAYMENT-2). It was not used to render a chart: every financial delta in this
task is zero and no correction occurred, so there is no series to plot; the
verification matrix is a table.

## 11. Closing state

Re-measured 2026-10-21 06:31:39 UTC (14:31 WITA), read-only:

- Production HEAD `61a29795`, unchanged; health 200×4; `laravel.log` 1 437 201
  bytes (byte-identical across the whole task — no probe wrote a log line).
- Probe: `STATE=PILOT_ACTIVE`, `VALID=yes`, `LIVE_NOW=no`,
  `PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT`, **Decision WATCH**,
  `POSITIVE_UNPAID_UNSETTLED_INVOICES_IN_PILOT=0`.
- Financial fingerprint identical to §1 (7 / Rp 7.548.000, 8 items, 8 / Rp 5.548.000,
  1 batch, receivable Rp 2.000.000, 0 corrections/lines/events/versions, 1601 audit
  rows, 514 role-permission pairs, 0 direct grants, 0 failed jobs). Classification:
  0 expected-correction mutations, 0 unexpected mutations.
- No deploy (`DEPLOY_REQUIRED=NO`, `NO_RUNTIME_DELTA`), no tag. Rollback readiness
  unchanged from rule 195 §30 (no active correction exists to reject/cancel; the
  flag is an environment kill switch rebuilt by `config:cache` as `daengtisiams`).

| Rung (rule 196) | Reached |
|---|---|
| PILOT_READY | yes |
| PILOT_ACTIVE | **yes** |
| PILOT_LIVE | no — maker off shift |
| AUTHENTICATED_VERIFIED | no — not performed |
| FINANCIAL_CORRECTION_VERIFIED | no — no eligible case |
| FULL_ACTIVATION_GO | no |

**Decision: NO_GO for full closure.** The pilot stays `PILOT_ACTIVE` (WATCH), safe
and unchanged. `verification-rme-invoice-correction-prepayment-pilot-1-go` was
**not** created; no other tag was created either (the runtime SHA already carries
`activation-rme-invoice-correction-prepayment-2-pilot-readiness-go` for the same
technical evidence — rule 196 §15). Full Suite: SKIPPED (temporary policy).

## 12. To close the pilot (operators and owner, not automation)

1. When a real SPN4 invoice is positive, UNPAID, unpaid and contains a genuine
   error noticed before payment, the owner authorizes that specific case (invoice
   reference, reason, approved line changes, original and expected total, maker 29,
   checker 1).
2. User 29 logs in normally and selects SPN4; user 1 logs in normally.
3. `runuser -u daengtisiams -- php artisan rme:invoice-correction-readiness --maker=29 --checker=1`
   → `live_now=true`, Decision GO.
4. Fresh read-only baseline (rule 196 §10). User 29 submits; confirm the payment
   page shows the D10 block (no payment posted). User 1 approves (auto-applies).
5. Reconcile (rule 196 §11). Only then: CI-verified rules/doc evidence, and the tag
   `verification-rme-invoice-correction-prepayment-pilot-1-go` on the deployed
   runtime SHA.
