# VERIFICATION-RME-INVOICE-CORRECTION-PREPAYMENT-PILOT-2

Second verification run of the live pre-payment RME invoice correction pilot
(SPN4 — Cabang Sunu, maker user 29, checker user 1). Rule 196 is the verification
contract; this run adds rule 196 §7 (re-verification runs) and changes no
runtime behaviour.

**Outcome: `PILOT_ACTIVE`, probe Decision WATCH, full closure NO_GO.** No runtime
defect found, no runtime change, no deploy (`NO_RUNTIME_DELTA`), no financial
mutation, no tag. The two blockers recorded by PILOT-1 are unchanged and neither
may be removed by automation:

1. **No eligible real case.** SPN4 holds 0 positive UNPAID invoices with zero
   payments. The only UNPAID invoice is #36 at Rp0 (D21g PENDING, untouched); the
   only PARTIAL invoice (#41) has a payment and is out of the pre-payment scope.
   No SPN4 invoice has been created since 2026-10-17, so no case could have arisen.
2. **Operators not in session.** No session row exists for user 29 or user 1;
   user 29's working context was last seen 2026-10-17 07:25:46 UTC; the probe
   reports `LIVE_NOW=no` (`PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT`). The run started at
   06:27 WITA on a Friday, before the clinic opens.

Because there is no case, there is nothing for the owner to authorize:
`CASE_SPECIFIC_AUTHORIZATION=PENDING (no candidate exists)`.

All times UTC unless marked WITA (UTC+8).

## 1. Repository and production baseline (measured 2026-10-22 22:27 UTC = 2026-10-23 06:27 WITA, read-only)

| Item | Value |
|---|---|
| Canonical base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `da9fb98c` (PR #508, docs only) |
| Production HEAD / tree | `b76b0ed2f21dbdd51ff292184ad3d1f8ed184ece` / `5fb0131c12193c36e7671c1cdfcaa2055a496c29` |
| Production tag (exact match) | `hardening-rme-active-user-patient-selection-1-go` |
| Runtime delta base vs production | none — `git diff b76b0ed2 da9fb98c -- app config routes database resources bootstrap composer.lock` is empty |
| Production working tree | 1 untracked pre-existing dir `storage/runtime-home/` (2026-09-05, not created by this task) |
| Deploy lock | free (`flock -n` succeeded); no deploy process |
| Open PRs | 0 |
| CI in flight at start | 1 — run `39863637819` (post-merge push of PR #508), pre-existing, not task-owned, not cancelled |
| Health over `https://daengtisia.online` | `/login`, `/health/live`, `/health/ready`, `/health/lb` → 200 |
| Services | nginx, php8.3-fpm, queue worker → active |
| `laravel.log` | 1 437 201 bytes, 162 ERROR lines, last modified 2026-10-09; byte-identical after every probe in this task |
| Flag | `FEATURE_RME_INVOICE_CORRECTION=true`, `env_resolution=env`; environment file 640 `root:daengtisiams` |
| Failed jobs / pending jobs | 0 / 0 |

### Financial fingerprint (no patient data)

| Measure | PILOT-1 (2026-10-21) | PILOT-2 (2026-10-23) |
|---|---|---|
| Invoices (incl. trashed) | 7 / Rp 7.548.000 | 7 / Rp 7.548.000 |
| Invoice items | 8 / Rp 7.548.000 | 8 / Rp 7.548.000 |
| Payments | 8 / Rp 5.548.000 | 8 / Rp 5.548.000 |
| Payment batches | 1 | 1 |
| Receivable (UNPAID + PARTIAL) | Rp 2.000.000 | Rp 2.000.000 |
| Invoices with `correction_version` ≠ 0 | 0 | 0 |
| Corrections / lines / events / document versions | 0 / 0 / 0 / 0 | 0 / 0 / 0 / 0 |
| `sys_audit_logs` (rows / correction actions) | 1601 / 0 | 1601 / 0 |
| `role_has_permissions` / `model_has_permissions` | 514 / 0 | 514 / 0 |

Every invoice is SPN4: PAID 5 (Rp 3.548.000), PARTIAL 1 (Rp 4.000.000, paid
Rp 2.000.000), UNPAID 1 (Rp 0). Latest SPN4 invoice 2026-10-17 07:24:57; latest
payment 2026-10-17 07:24:00; latest SPN4 visit date 2026-10-13. **Nothing has
moved since PILOT-1.** Two open SPN4 visits exist (1 `cashier_pending`, 1
`registered`); neither has an invoice, so neither is a correction candidate, and
neither was touched.

## 2. Owner decisions in force (register `docs/decisions/rme-billing-correction-owner-decisions.md`)

| Decision | State | Verified by |
|---|---|---|
| D1 maker ≠ checker | enforced per account, in the service | identity matrix §4; lifecycle tests |
| D1a approve auto-applies, one transaction | enforced (`AUTO_ON_APPROVAL`) | probe line; PG suite "applies atomically" |
| D1b existing role permissions | Front Office request+approve, Kasir request, Admin Klinik approve, Super Admin all | production `role_has_permissions` |
| D1c SPN4 only | `declared=[SPN4]`, `admitted_ids=[5]` | in-process `admits()` §4 |
| D1d / D1e pilot accounts | maker `[29]`, checker `[1]`, valid | in-process `allows()` §4 |
| D7 immutable versions | v0 frozen at apply, v1 issued | lifecycle + PG suites (no production version exists yet) |
| D10 payment blocked during an active correction | under the invoice row lock on all 5 locked payment reads | source §5; payment-block + PG suites |
| D21 manual pricing | in force; correction service has no tariff dependency | `RmeInvoiceCorrectionTariffAuthorityTest` |
| D21c discount cap | **PENDING** (`0 ≤ discount ≤ line gross` only) | not decided here |
| D21g Rp0 | **PENDING** (no new Rp0 correction) | invoice #36 untouched |
| OD-1 / OD-1a | deployed at `b76b0ed2` | `CrossBranchKontrolFinancialOwnershipTest` |

No decision was made, changed or inferred by this run.

## 3. Pilot readiness probe

Command (refuses root): `runuser -u daengtisiams -- env HOME=/tmp php artisan rme:invoice-correction-readiness --maker=29 --checker=1`

```
STATE=PILOT_ACTIVE
FLAG_ENABLED=true env_resolution=env
PILOT_DECLARED=SPN4 ADMITTED=SPN4
PILOT_IDENTITIES valid=yes maker=29 checker=1 (owner D1e, rule 197)
APPLICATION=AUTO_ON_APPROVAL (owner D1a)
POSITIVE_UNPAID_UNSETTLED_INVOICES_IN_PILOT=0
PAIR maker=29 checker=1 distinct=yes designated=yes maker_ok=yes checker_ok=yes shared=SPN4 VALID=yes LIVE_NOW=no
[WATCH] PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT 29 is eligible for SPN4 but holds no working context there now
Decision: WATCH   (exit 0)
```

Executed as `daengtisiams` at 2026-10-22 22:27 UTC. User 29's online-context row
still reads `status=online` with `last_seen_at` 2026-10-17 07:25:46 — rule 196 §4
(the status column is not "on shift"); the probe read it without writing.

## 4. Pilot scope and identity (production, in-process, DB session forced read-only)

Script piped over SSH stdin as `daengtisiams`, first statements
`SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY`; no tinker/psysh.

| Check | Result |
|---|---|
| Feature guard | enabled |
| Declared / admitted | `[SPN4]` / `[5]` |
| `admits()` | SPN4#5 true; TLK1#1, LDK2#2, ATG3#3, MAIN#4, SYN4A#6 false; forged 999999 / 0 / −5 false |
| Identity list | valid, `maker_user_ids=[29]`, `checker_user_ids=[1]` |

Every **active** holder of any correction permission (9 accounts), judged by the
same `allows()` the service calls under the lock:

| Account | Role | submit | approve | reject | apply |
|---|---|---|---|---|---|
| 29 | Front Office | **Y** | – | – | – |
| 1 | Super Admin + Owner | – | **Y** | **Y** | **Y** |
| 7, 8, 16, 17, 30, 31, 32 | Front Office | – | – | – | – |

Users 29 and 1: active, not deleted. User 29 holds the Front Office role only; user
1 holds Super Admin + Owner. Neither account has a session row; `users.last_login_at`
is written by no code path (0 of 35 accounts non-NULL) and is not login evidence.

## 5. Mutation paths (Graphify + source)

The canonical graph (`graphify-out/`, 2026-10-13) predates the correction module
and contains 0 `RmeInvoiceCorrection` nodes, so it was **not** rewritten. A scoped
AST graph of `app/Modules/RmeInvoice` + `routes/web.php` +
`config/rme_invoice_correction.php` was built in the session scratchpad
(`graphify update --no-cluster`: 797 nodes, 1997 edges). Findings:

- `applyLocked()` (the only path that writes items, totals, `correction_version`
  and the v0/v1 document versions) is called only by `approve()` and `apply()`.
- `assertActor()` (permission + working-branch scope from the persisted row) is
  called by `submit()`, `decide()` (approve/reject), `cancel()` and `apply()`.
- The D1e identity guard runs inside each transaction after the row lock:
  `submit` (service L327), `decide` (L776), `apply` (L580). `cancel()` is
  maker-only (`isRequestedBy`) and deliberately identity-ungated: it is the D10
  release path and can only withdraw.
- D10: `RmePaymentService::assertNoActiveCorrection()` runs under the invoice
  lock in all five locked reads — standard payment (L128), control invoice
  (L187), carry-over parent (L212), visit current invoice (L343), each selected
  receivable (L369). Stale-form protection: `assertFormVersion()` (L551).

## 6. Regression (local; `app/` byte-identical to production)

Run in a task-owned worktree at `da9fb98c` on local PHP 8.5.4 (CI and production
run 8.3 — no runtime code changed, so this is a re-run of shipped behaviour, not
CI evidence). Counts are taken from JUnit output, because the worktree has no
application environment file and Pest therefore labels every test a warning
(the framework's optional environment-file read; CI provisions an empty file).
Creating that file is outside this session's permissions, and was not attempted
any other way.

| Engine | Scope | Passed | Skipped | Failed | Assertions |
|---|---|---|---|---|---|
| SQLite (in-memory) | `RmeInvoiceCorrection`, `RmeManualPricing`, `ClinicOversight`, `ActiveAccountSession`, payment/invoice explicit actor, `RmePayment`, clinic-visit branch authority, patient-selection authority, OD-1 cross-branch Kontrol ownership, `CashierBilling`, Kontrol workflow, both receivable carry-over suites | 700 | 10 | 0 | 3529 |
| PostgreSQL 16.15 (throwaway container, removed) | `RmeInvoiceCorrection` (all, incl. the 10 concurrency cases), invoice-creation race, clinic-visit registration race, payment/invoice explicit actor, OD-1 ownership | 323 | 0 | 0 | 1349 |

The 10 SQLite skips are exactly `RmeInvoiceCorrectionConcurrencyTest`, which
skips off PostgreSQL by design (SQLite compiles `lockForUpdate()` to nothing) and
passes all 10 on PostgreSQL.

Race scenarios (prompt §33) mapped to evidence:

| Scenario | Evidence |
|---|---|
| A payment first vs correction | PG: "a payment that commits first makes a waiting submission refused"; "waits on a payment mid-flight, then marks STALE" |
| B correction first vs payment | PG: "waits on a submission mid-flight, then refuses the payment and records nothing" |
| C two simultaneous approvals | PG: "serializes two simultaneous approvals" |
| D apply idempotency | PG: same (second finds APPLIED, applies nothing); SQLite lifecycle "applies idempotently" |
| E version mismatch | PG: mid-apply → stale page refused, reloaded page accepted |
| F cancellation vs payment | PG: "waits on a cancellation mid-flight, then accepts the payment" |
| G rejection vs payment | SQLite only ("resumes payment after REJECTED"); reject shares `decide()`/`lockPair()` with approve, whose race is proven on PG. **No dedicated concurrent PG case** — recorded, not claimed |
| H multi-invoice atomicity | SQLite: visit batch with a receivable under correction refused with no partial allocation; control batch refused whole |
| I cross-branch | SQLite: branch scope + OD-1/OD-1a suites; production `admits()` matrix §4 |
| J actor deactivation | SQLite `ActiveAccountSession`; the identity guard re-reads `is_active` inside the transaction. **No concurrent PG case** for a deactivation committing mid-transaction (a sub-transaction TOCTOU the READ COMMITTED read cannot see) — recorded as INFO |

## 7. Independent security review

A separate read-only reviewer (no tests, no artisan, no SSH) answered seven
questions against `da9fb98c` with file:line evidence. **0 CRITICAL, 0 HIGH,
0 MEDIUM, 2 LOW, 4 INFO.**

| # | Question | Result |
|---|---|---|
| 1 | Any writer of corrections, `correction_version`, correction items or document versions outside the guards? | None. Only `RmeInvoiceCorrectionService`, called only from `RmeInvoiceCorrectionController` (web.php 1386–1414); no command/job/observer. `correction_version` is not mass-assignable; one repository writer. |
| 2 | Maker ≠ checker under the lock, immune to `Gate::before`? | Yes. Identity compares account ids (maker `[29]` at L327, checker `[1]` at L776); overlap voids the list; self-approval L763 and self-application L567 checks. |
| 3 | Branch from the persisted row, never the request? | Yes. Submit reads `branch_id` from the locked invoice; the request refuses `branch_id`; out-of-scope ids 404 and the service re-checks. |
| 4 | Races (submit/apply/double approve/payment between approve and apply)? | No window found. Invoice-then-correction lock order; D1a approve+apply is one transaction; double approve and re-apply are no-ops; DB allows one active correction per invoice; `applyLocked` re-asserts no payment and the approved total. |
| 5 | D10 on every payment path? | Yes — standard, control (control + each parent), visit (current + each receivable); the only payment insert is reached only from those paths. |
| 6 | Rp0 / discount today | New Rp0 line or total refused. See LOW-2 and INFO-3. |
| 7 | PII in payloads/messages? | None: ids, amounts, codes, `reason_length`; snapshots carry `patient_id` only. |

Findings:

- **LOW-1 (pre-existing, latent).** Approve and apply authorize against
  `correction.branch_id`, not the live invoice branch, and the fingerprint does not
  cover `branch_id`. Safe today — nothing rewrites `trx_rme_invoices.branch_id`
  after creation — but a future branch-move feature must mark pending corrections
  stale.
- **LOW-2 (pre-existing; verified by me in source).** `prePaymentIneligibility()`
  does not refuse a Rp0 ORIGINAL, so a correction could raise the Rp0 invoice #36
  to a positive total. That would settle the pending zombie-invoice decision
  (D22/D21g) by an operator act. Today it is held only by procedure (rule 196 §8)
  and by the two-account gate. Recorded in rule 196 §23 and the decision register;
  **not changed here**, because adding a refusal pre-empts an undecided owner
  question. Owner follow-up below.
- INFO: patient merge rewrites `trx_rme_invoice_corrections.patient_id` under its
  own maker-checker (no money); discount may move anywhere in
  `0 ≤ discount ≤ gross` (D21c PENDING); the idempotent replay runs before the lock
  but only returns the same maker's existing correction and writes nothing;
  `cancel()` is maker-only and deliberately identity-ungated (D10 release path).

## 8. What was NOT done, and why

| Step | Status | Reason |
|---|---|---|
| Maker login | NOT PERFORMED | no session for user 29; only the operator may log in (rule 196 §3) |
| Checker login | NOT PERFORMED | no session for user 1 |
| Case discovery | 0 candidates | no positive UNPAID SPN4 invoice with zero payments |
| Case-specific authorization | PENDING | nothing to authorize |
| Correction submit / approve / apply | NOT PERFORMED | no case, no authorization, no operators |
| D10 live observation | PENDING | needs an active correction |
| Version transition / snapshots / reconciliation | NOT APPLICABLE | no correction |
| Deploy | NOT REQUIRED | `NO_RUNTIME_DELTA` (rule 196 §15) |
| Tag | NONE | rule 196 §14–15, §18 |

## 9. Rules synchronization

- Rule 196 gains **§7 "Re-verification runs"** (items 18–23): one closure tag for
  every run; re-measure instead of inheriting; no clinic activity is a blocker,
  not a defect; the case can only be closed from inside a real clinic session
  and a patient is never held to keep a case alive; only the live session is
  login evidence (`last_login_at` is written by no code path); the ban on
  correcting Rp0 invoice #36 is procedural, not a code refusal (LOW-2). No new
  rule number — rule 196 already owns this contract, and rule 197 (D1e) is
  unchanged.
- The decision register gains a dated clarification under the D21g block: no
  decision changed, but "historical Rp0 invoices are untouched" is a procedure,
  not a code guarantee.
- `CLAUDE.md` gains a PILOT-2 section.

## 10. DataViz

`NOT_NEEDED`. Every financial measure is unchanged and every correction count is
zero; a chart would only draw flat lines. The comparison tables above are the
visualization.

## 11. Operational handoff — how the pilot can be closed

1. During normal SPN4 operation the cashier issues a POSITIVE invoice and an
   operator notices a **genuine** error before the patient pays (wrong treatment,
   quantity or price). Nobody creates an error to open a case.
2. The cashier tells the owner at once. The owner authorizes **that invoice** in
   writing: invoice number, reason, the approved line changes, original and
   expected total, maker 29, checker 1.
3. If the owner cannot authorize while the patient is present, the patient is
   **not** held: the cashier follows the existing pre-pilot procedure and the case
   is lost to the pilot (rule 196 §21).
4. User 29 logs in personally and selects SPN4; user 1 logs in personally.
5. Run the probe as `daengtisiams`: required `LIVE_NOW=yes`, Decision GO.
6. Capture a fresh baseline (rule 196 §10) including the target invoice version.
7. User 29 submits the correction through the normal UI.
8. Observe D10 by state on the payment page (block shown, action disabled) —
   never by posting a payment (rule 196 §12).
9. User 1 reviews and approves through the normal UI (approval applies it).
10. Reconcile (rule 196 §11): one correction APPLIED, version +1, v0 unchanged,
    v1 matching the approved lines and total, no payment changed.
11. Only then create `verification-rme-invoice-correction-prepayment-pilot-1-go`
    on the deployed runtime SHA.

## 12. Owner follow-ups

1. **Close the pilot opportunistically** with the §11 procedure — nothing in this
   repository can do it.
2. **LOW-2 / D22 + D21g:** should a correction be hard-refused on a Rp0 original
   (invoice #36 and any future Rp0 invoice) while D21g/D22 are pending? Today the
   refusal is procedural only. Recommended: yes — a small fail-closed change with
   its own reason code, through the full lifecycle. Not done here because it
   decides part of a pending owner question.
3. **LOW-1:** if a branch-move feature is ever built, it must mark pending
   corrections stale.

## 13. Results

| Field | Value |
|---|---|
| Implementation | PARTIAL — every safe step done; operator and financial steps not performable |
| Production runtime SHA | `b76b0ed2f21dbdd51ff292184ad3d1f8ed184ece` (unchanged) |
| Feature flag | ON |
| Pilot branch | SPN4 |
| Pilot identity configuration | PASS (maker `[29]`, checker `[1]`, valid) |
| Maker / checker authenticated | NO / NO (no session) |
| Maker working branch | NONE now (context last seen 2026-10-17) |
| Pilot readiness | WATCH (`PILOT_ACTIVE`, `VALID=yes`, `LIVE_NOW=no`) |
| Branch isolation | PASS |
| Maker-checker | PASS |
| Eligible real invoice | NO (0 candidates) |
| Genuine correction needed | UNKNOWN (no candidate to judge) |
| Case-specific authorization | PENDING |
| Real request / approval / apply | NOT_PERFORMED |
| D10 live verification | PENDING (source + suites PASS) |
| Version / snapshot / audit verification | PENDING |
| Financial reconciliation | NOT_APPLICABLE (fingerprint identical to PILOT-1) |
| Security review | PASS (0 CRITICAL/HIGH/MEDIUM; 2 LOW, 4 INFO recorded) |
| PostgreSQL regression | PASS (323 / 0 skipped / 0 failed) |
| Rules sync | PASS (rule 196 §7, register clarification, CLAUDE.md) |
| Graphify | PASS (scoped AST graph; canonical graph left untouched) |
| DataViz | NOT_NEEDED |
| Authoritative CI | docs-only PR gates (no runtime CI required) |
| Full Suite | SKIPPED (docs-only; temporary Full-Suite policy) |
| Deploy required | NO (`NO_RUNTIME_DELTA`) |
| Full GO tag | NONE |
| Final decision | **NO_GO for full closure**; pilot stays `PILOT_ACTIVE` |
