# FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1

Program `DAENGTISIAMS-RME-BILLING-CORRECTION` — **supporting hardening after
Sprint 2**. Not a main sprint, not a Full Prepayment Pilot GO.

Rule: `.cursor/rules/205-rme-payment-submission-idempotency-financial-integrity.mdc`.
Origin: rule 196 §28 — the 2026-10-24 read-only audit that named this sprint.

## 1. Status

| Item | Status |
|---|---|
| F1 — duplicate partial payment / replay | `FIXED` (payment intent + submission ledger) |
| F3 — decision on a pre-lock summary | `FIXED` (declared plan + balances, decided under the locks) |
| Security review | `PASS` — first review 0 CRITICAL / 0 HIGH / 0 MEDIUM, 3 LOW fixed; re-review of those fixes found 1 MEDIUM introduced by the L3 fix (R1), fixed; final 0 open (§9) |
| Scoped GO tag | `fix-rme-payment-submission-idempotency-1-go` — created only on the deployed runtime SHA (§13) |
| Invoice-correction pilot | unchanged: SPN4, maker 29 / checker 1 — `FULL_PREPAYMENT_PILOT_GO: NO_GO` |

This GO is supporting-hardening GO only. It does not authorize real production
payments, refunds, debt forgiveness, cancellation, manual PAID, price changes,
history rewrites, pilot expansion, or disabling D10 / dispute holds.

## 2. Root cause

**F1.** None of the four payment paths in `RmePaymentService`
(`pay`, `allocateControlPayment`, `payControlInvoiceOnly`, `allocateVisitPayment`)
carried a request identity. Each locked the invoice, checked `amount ≤ remaining`
and inserted. A form posted twice — double click, browser retry after a lost
response, back-and-resubmit — recorded a second partial payment, because the
second request still saw enough remaining balance. A replayed FULL payment was
refused by state (nothing left to pay), but as an error rather than as the
payment that already happened; `RmeInvoicePolicy::pay()` does not look at the
status, so the replay reached the amount cap.

**F3.** `allocateControlPayment` and `allocateVisitPayment` read the payable
summary (`carry_over_invoices` / `selected_receivables`, `total_payable`) and
capped the amount BEFORE taking any row lock. Under the locks, an origin or
receivable that had stopped being payable was skipped (`continue`) and every
allocation was `min()`-capped:

- **silent truncation** — 300.000 submitted, 100.000 recorded (the origin was
  settled meanwhile; the rest was capped to the Kontrol remaining);
- **silent redistribution** — 80.000 meant for a ticked receivable landed on the
  current invoice once the receivable was gone.

The controller's dispatch (`has_carry_over`, `selected_remaining`) was pre-lock
too. `payControlInvoiceOnly` already validated its amount under the lock.

## 3. Design

| Piece | What it does |
|---|---|
| `RmePaymentIntent` | Server-issued `nonce.signature` per page render; HMAC-SHA256 over `rme-payment-intent\|v1\|nonce\|invoice` with the app key; constant-time compare; required |
| `trx_rme_payment_submissions` | One immutable row per COMMITTED submission, inserted in the payment transaction after the payment rows; `UNIQUE(intent_key)`; no status column |
| Fingerprint | SHA-256 over a canonical, versioned payload (cashier, invoice, scope, plan, method, amount in cents, paid-at, reference, notes, selection, versions, balances) |
| Replay | Same intent + cashier + invoice + fingerprint ⇒ original result, nothing written |
| Reuse | Same intent, anything else different ⇒ `PAYMENT_INTENT_REUSED` |
| `RmePaymentDeclaredView` | The page declares `payment_plan` and `invoice_balances[id]`; both are verified against the LOCKED rows |
| Under the lock | Summary read inside the transaction; every plan invoice locked in the existing order; plan / balance conflict refuses the whole payment; amount cap from the locked balances; full allocation asserted in cents |
| Audit | `RME_PAYMENT_SUBMISSION_REPLAYED`, `RME_PAYMENT_REFUSED_INTENT_REUSED`, `RME_PAYMENT_REFUSED_STALE_BALANCE` — ids only, de-duplicated per 15 min |
| Page | Hidden intent / plan / balances on both forms; double-submit guard ("Memproses pembayaran…"); retry guidance; stale-view refusals never restore the old amount |

**Check order (pinned by tests):** authority → replay → collectable → version
(rule 191) → D10 / dispute hold → plan → balances → amount cap → allocate →
full-allocation assertion. A version or hold refusal is never masked by a
balance refusal; a replay is answered before any state check, but only to an
account that still has the authority to collect.

**Missing intent:** refused fail-closed with a reload instruction
(`PAYMENT_INTENT_MISSING`) — the rule-191 precedent. A form rendered before the
deploy records nothing; the cashier reloads.

## 4. Files

New: `RmePaymentSubmission` (model), `RmePaymentSubmissionRepository(+Interface)`,
`RmePaymentSubmissionService`, `RmePaymentIntent`, `RmePaymentDeclaredView`,
`RmeInvoiceBalanceConflict`, `RmePaymentIntentRefused`,
`RmePaymentSubmissionReplayed`, migration `2026_10_25_100001_create_trx_rme_payment_submissions_table`.

Changed: `RmePaymentService`, `CreateRmePaymentRequest`, `RmePaymentController`,
`RmeFinancialAuditEvent`, `RepositoryServiceProvider`,
`resources/views/rme/cashier/payment/create.blade.php`, `config/ci_runner.php`.

No permission, no route, no seeder, no JS/CSS asset (Blade + inline Alpine only;
no `npm run build`).

## 5. Migration safety

Additive only. On a disposable PostgreSQL 16 database: migrate → rollback (empty
table) → migrate all succeed. Constraints present: 5 CHECKs (path list, key and
fingerprint shape, amount > 0, count ≥ 1), `UNIQUE(intent_key)`, 4 foreign keys
`ON DELETE RESTRICT` (same posture as `trx_rme_payments`). `down()` refuses while
the table holds a row (test M21). Nothing historical is backfilled.

## 6. Tests

New suites (both declared in `config/ci_runner.php` `critical_gate_mandatory_suites`
and selected by the existing `RmePayment` filter token):

- `tests/Feature/RmePaymentIdempotency/RmePaymentSubmissionIdempotencyTest.php` —
  F1/F3 red-first cases, the idempotency matrix M01–M21, security follow-ups L1–L3.
- `tests/Feature/RmePaymentIdempotency/RmePaymentSubmissionConcurrencyTest.php` —
  PostgreSQL races A–V with a child process running the REAL services
  (`payment_race_child.php`); every case asserts the parent genuinely waited
  (`> 1 s`) on a lock or index entry the child held.

TDD: the first nine cases were written before any production code and failed
**7 / 9** on both SQLite and PostgreSQL 16 against the base code.

| Race | Proves |
|---|---|
| A / B / C | same intent: commit ⇒ replay; rollback ⇒ one payment; full ⇒ replay, never overpayment |
| D / E | same intent, different amount or cashier ⇒ reuse refused, no receipt leak |
| F / G | two pages: stale balance refused after a commit; accepted after a rollback |
| H / S / T | Kontrol and visit batches: replay of the whole batch; retry after rollback records once |
| I / J / K | origin / receivable settled or partly paid while waiting ⇒ whole refusal, never truncated or redistributed |
| L | Kontrol-only replay; disputed origin untouched |
| M / N / O | dispute report and correction submission still hold a fresh payment; a replay moves no money |
| P | commit backstop: a lookup that missed waits on `UNIQUE(intent_key)` and loses — no second write |
| Q | two full payments at once settle exactly once |
| R | a replay waiting on the deactivation of its cashier is refused, not shown the receipt |
| U | a genuine deadlock (`pg_stat_database.deadlocks` increases) is retried and settles once |
| V | a retried attempt still completes its visit — never reuses the rolled-back model (R1) |

**Updated existing tests (behaviour, not just plumbing):**

- `CrossBranchKontrolFinancialOwnershipTest` — the two "even if discovery leaked
  it" cases pinned silent truncation (300.000 submitted, 100.000 recorded) as the
  safe outcome; they now expect a whole refusal (`PAYMENT_PLAN_CHANGED`) with no
  payment anywhere. The branch-isolation property is asserted unchanged.
- Race suites (`RmeBillingDisputeConcurrencyTest`,
  `RmeBillingDisputeBranchReviewerConcurrencyTest`,
  `RmeInvoiceCorrectionConcurrencyTest`,
  `RmeInvoiceCorrectionRejectionDeactivationRaceTest`) — hand-built forms now
  carry the intent, plan and balances (`rmeDeclaredPaymentForm()`), otherwise the
  payment was refused before taking any lock and the race never happened (24
  PostgreSQL failures with `waited ≈ 0 s` until fixed); their cleanup lists now
  include the new table.
- `RmeInvoiceCorrectionPaymentVersionBindingTest`, `RmeBillingDisputeControlOnlyPaymentTest`,
  `RmeBillingDisputePaymentHoldTest`, `RmeInvoiceCorrectionPaymentBlockTest` —
  the guard under test still runs first; forms carry the new fields.

## 7. Mutation testing

26 mutants, applied and restored by copy with a checksum check after each.

| Result | Mutants |
|---|---|
| Killed (23) | M01 service replay check · M02b FormRequest early + late replay · M03 HMAC not verified · M04 fingerprint ignored · M05b cashier ignored + absent from fingerprint · M06 invoice ignored (race P) · M07 balance never compared · M08 ledger row not written · M09 audit de-dup removed · M10 missing intent silently replaced · M11 replay message dropped · M12 unique backstop rethrows · M13 balance before version · M14b origin skipped + plan check off · M15 origins not locked (race I) · M16 replay check (race A) · M17 L1 client id trusted · M18 L2 not lossless · M19 L3 no retry (race U) · M20 L3 exhausted conflict as 500 · M21 / M22 / M23 R1 retry reuses the rolled-back visit model (Kontrol, visit, Kontrol-only — race V) |
| Equivalent by redundancy (3) | M02 (late re-lookup still answers), M05 (cashier is in the fingerprint), M14 (plan check catches the skip) — each combined mutant removing BOTH layers is killed |
| Real survivors | **0** |

Two survivors of the first round were real test gaps and were closed: M01
(the direct-service test only asserted "something was thrown") and M10 (no test
called the service without an intent; HTTP is also covered by the `required` rule).

## 8. Performance (PostgreSQL 16, measured)

| Path | Queries on `trx_rme_payment_submissions` | Row locks |
|---|---|---|
| New payment, service | 2 (indexed lookup + insert) | unchanged |
| New payment, HTTP | 3 (+ FormRequest early lookup) | unchanged |
| Replay, HTTP | 1 — whole request 12 queries / ~14 ms vs 80 / ~101 ms for a real payment | none |

The summary read moved inside the transaction (not added). The multi-invoice
paths now lock every plan invoice, not only those the amount reached — see L3.

## 9. Security review

Independent adversarial review of the full diff: **0 CRITICAL, 0 HIGH, 0 MEDIUM**.
Three LOW findings, all verified and fixed with a load-bearing test (mutants
M17–M20 killed):

- **L1** — a stale-view refusal audit looked up a client-supplied invoice id
  unscoped, so a crafted request could plant audit rows on another branch's
  invoice. Now audited on that invoice only when it is the route invoice or one
  of the same patient at the same branch; otherwise on the route invoice without
  the foreign id.
- **L2** — the fingerprint collapsed to `sha256('')` for any payload with invalid
  UTF-8 (`json_encode` returned false). Now `JSON_THROW_ON_ERROR`, and free text
  is encoded by its exact bytes with an injective tag.
- **L3** — locking the whole plan made a cross-locking pair of payments able to
  deadlock into a 500. The payment transaction is retried (3 attempts) and then
  refused with `PAYMENT_CONCURRENT_RETRY`; any other database error keeps its
  meaning. Race U produces a real deadlock and proves the retry.

**Re-review of the three fixes** (same reviewer, read-only): L1 and L2 sound; one
**MEDIUM introduced by the L3 fix**:

- **R1** — the Kontrol, Kontrol-only and visit closures captured their VISIT model
  from outside the retried transaction. A rolled-back attempt could leave that
  object at `completed`, so the retry skipped visit completion: the payment
  committed and the visit stayed `cashier_pending` for good. Each attempt now
  re-derives its visit from the invoice it just locked (as `pay()` already did).
  Race V fails the first attempt AFTER the visit was completed in it and proves
  the retry still completes it, on all three paths (mutants M21–M23 killed).

Clean: intent forgery, replay authority (FormRequest authorize runs before the
replay; the service re-checks the account before answering), fingerprint
coverage, IDOR, TOCTOU, unique-violation handling, holds, audit PII, redirects and
session data, migration, backward compatibility (the controller is the only
caller of the four methods).

## 10. Regression

The local runs below are on the final code, after the R1 retry fix. Counts come from the JUnit logs, not
the console summary, because without an application-environment file Pest prints every result as a
warning.

| Run | Scope | Passed | Skipped | Failed |
|---|---|---|---|---|
| SQLite (worktree) | `tests/Feature/RME`, `RmeInvoiceCorrection`, `RmeBillingDispute`, `RmeTariffAuthority`, `RmePaymentIdempotency`, `Cicd`, the cashier/RME Browser smoke, `ClinicOversightAccessSecurity`, `RmeLabCandidateE2EValidation`, `RmeManualPricingInvoice`, `Sprint39` cashier, cashier/receivable/responsive UIX | 2967 | 79 | **0** |
| PostgreSQL 16.15 | the same scope minus `tests/Feature/Cicd` and `LegacyRmeProgramClosureContract` (282 tests that are contract or DB-independent) | 2764 | 0 | **0** |

All 79 SQLite skips belong to the PostgreSQL-only race suites. All of them ran on PostgreSQL, so none
is hidden. The new suites:

| Suite | SQLite | PostgreSQL 16 |
|---|---|---|
| `RmePaymentSubmissionIdempotencyTest` | 44 passed | 44 passed |
| `RmePaymentSubmissionConcurrencyTest` | 24 skipped (PostgreSQL-only) | 24 passed |

The PostgreSQL run was carried out in a copy of the worktree. That copy's tree was diffed against the
worktree and differed only in `CLAUDE.md`, so the runtime code it tested is identical.

## 11. Rollback

Code rollback: `scripts/rollback-vps.sh hardening-rme-billing-dispute-branch-reviewer-control-payment-1-go`
(the previous runtime). The migration is additive: the old code ignores the new
table and the new form fields. Do NOT drop the table while it holds rows (its
`down()` refuses) — roll forward instead; dropping it would let a replayed form
record the same payment again. No data restore is needed or allowed.

## 12. CI, deploy and production verification

Recorded after the fact. This evidence commit is docs-only, is not tagged, and is not deployed.

**Authoritative CI.** Run `40149798577` (`pull_request`) on the exact candidate
`9733da43e062e038f8a0e68a6dcb59444c961ee7`, tree `058d66ff751ea58800e256bf4e7fe29c8bc81371`,
concluded **success**:

| Job | Result |
|---|---|
| CICD-CTRL Gate Classifier | success (`gate_profile=unknown_high_risk`) |
| NSF-R012 Quality Gate | success |
| Phase 3 Android Clinic App Gate | success |
| CICD-CTRL Selective Module Gate | success (Inventory, Lab, UI/UIX and Permission suites all ran) |
| NSF-R011 Critical Test Gate (GitHub-hosted) | success: `Run critical regression tests` executed, **6624 passed (34557 assertions)**, `critical_test_exit_status=0`, 8874 s |
| NSF-R011 Critical Test Gate (self-hosted variant) | skipped. CICD-CTRL-3 runs exactly one variant |
| NSF-9 Release Safety & Automated Smoke Gate | success |
| NSF-10 Release Evidence Gate | success |
| NSF-R011 Full Suite Gate | **SKIPPED** |

**FULL_SUITE=SKIPPED. It must not be reported as PASS.**

- *Why it was skipped:* the classifier published `temporary_full_suite_policy_active=true`,
  `full_suite_authorized=false` and `full_suite_defer_reason=FULL_SUITE_NOT_ENABLED_FOR_EVENT`.
  `run_full_suite=required` is a risk signal, not an authorization.
- *Post-merge run:* the push run on the merge commit (`40160697653`) deferred the Full Suite as well,
  with `TEMPORARY_FULL_SUITE_POLICY_ACTIVE`.
- *The new suites inside the critical gate:* `RmePaymentSubmissionIdempotencyTest` and
  `RmePaymentSubmissionConcurrencyTest` both PASS. All 24 concurrency races ran on CI's PostgreSQL, each
  taking several seconds, which is a genuine lock wait.

**Merge.** PR #519 was squash-merged as `20303a5b92f7bc85d0b34abfbc1c880ef49d6112` with
`--match-head-commit 9733da43`. Its tree is `058d66ff…`, **identical to the candidate tree**. Its parent
is `7a93b8ff`, which was the unmoved base tip.

**Deploy.** `bash scripts/deploy-vps-runner.sh start` was executed **inside the VPS**. The runner
reported `exit=0` and `DEPLOY OK: 20261025-040833`, and its log records:

- `DEPLOY_TARGET_PINNED=20303a5b…` and `DEPLOY_HEAD_TARGET_MATCH=YES`;
- pre-deploy backup `pre_auto_deploy_20261025-040833.sql` (32 225 046 bytes, `0640 daengtisiams`);
- migration `2026_10_25_100001_create_trx_rme_payment_submissions_table`, done in 88.97 ms;
- `SECRET FILE PERMISSIONS: GO` and `RUNTIME ISOLATION: GO (70 GO / 0 FAIL / 0 SKIP)`;
- `DEPLOY_SNAPSHOT_CLEANED=YES`.

The smoke check reported 6 passed and 1 warning. The warning is the known co-tenant answer on bare
`127.0.0.1/login` (404); the canonical domain is verified below.

**Production verification.** Read-only throughout: `BEGIN READ ONLY` psql, no REPL, no write.

- *Revision:* VPS HEAD/tree `20303a5b…`/`058d66ff…`, equal to the merge. The tracked tree is clean.
  The only untracked path is `storage/runtime-home/`, which is preserved.
- *Migration and environment:* migration `[86] Ran`, no pending migrations. Environment `pilot`, debug
  OFF, maintenance OFF. The config cache is owned by `daengtisiams`.
- *Services:* php8.3-fpm, nginx, the queue worker and postgresql are all active. 0 failed jobs.
  The deploy lock has no holder.
- *Over `https://daengtisia.online`:* `/login`, `/health/live`, `/health/ready` and `/health/lb` return
  200, and every `/health/ready` component reports ok. As a guest, `/rme/cashier`,
  `/rme/cashier/receivables` and `/dashboard` return 302.
- *Financial fingerprint:* byte-identical before and after the deploy.

| Measure | Pre-deploy | Post-deploy |
|---|---|---|
| Payments | 9 / 10 548 000.00 / max id 50 / 0 soft-deleted | same |
| Invoices | PAID 6, PARTIAL 1, UNPAID 1 / max id 42 | same |
| Payment row hash | `6562cc3b26110108680e2ca63453619a` | same |
| Invoice row hash | `701024c17061d185476dc9bdb24a1ad4` | same |
| `trx_rme_payment_submissions` | absent | present, **0 rows**. Nothing was backfilled |

One genuine payment (id 50, invoice 42, branch SPN4) was recorded by the clinic at 11:24 WITA,
while CI was running and before the deploy. This session did not write it. The baseline was taken
again immediately before the deploy, and the comparison above uses that second baseline.

- *New table structure:* the 5 CHECK constraints (amount, count, fingerprint, key, path), 4 foreign
  keys all `ON DELETE RESTRICT` (branch, cashier, invoice, payment) and `UNIQUE(intent_key)` are
  present on production.
- *Logs:* `laravel.log` is byte-identical (1 442 282 bytes) and has no entry dated 2026-10-25. The
  deploy caused **0 new application errors**.
- *Deployed code:* the cashier payment view carries the intent, plan and balance fields, and the
  service carries the retry constants.

**Transition note.** The deploy took place during clinic hours, which is the normal practice; the
deploy has no maintenance window. A payment form opened before the deploy and submitted after it is
refused with `PAYMENT_INTENT_MISSING`. The refusal message says no payment was recorded and that the
page must be reloaded. Nothing is lost and nothing is written twice.

## 13. GO

`fix-rme-payment-submission-idempotency-1-go` is an annotated tag on the deployed runtime SHA
`20303a5b92f7bc85d0b34abfbc1c880ef49d6112`. The local, remote and VPS peels are all equal, and
`git describe --exact-match` on the VPS HEAD resolves to it.

This is a **supporting hardening GO**. It is NOT Sprint 2 Full GO, and it is NOT a Full Prepayment
Pilot GO. The pilot stays SPN4-only, with maker 29 and checker 1. D21c, D21g and D22 remain PENDING.

## Catatan untuk kasir (Bahasa Indonesia)

- Jika tombol simpan ditekan dua kali, pembayaran hanya tercatat **sekali**. Layar
  menampilkan "Pembayaran ini sudah tercatat sebelumnya — tidak ada pembayaran
  baru yang dibuat" beserta nomor kwitansi aslinya.
- Jika muncul pesan bahwa rincian tagihan atau sisa tagihan telah berubah, **muat
  ulang halaman**, periksa angka terbaru, lalu konfirmasi ulang. Tidak ada uang
  yang tercatat dari percobaan yang ditolak.
- Halaman pembayaran yang dibuka sebelum pembaruan aplikasi harus dimuat ulang.
