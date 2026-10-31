# HARDENING-RME-OWNER-REFERENCE-REGISTRY-1

| | |
|---|---|
| Rule | `.cursor/rules/214-rme-owner-reference-registry.mdc` |
| Branch | `hardening/rme-owner-reference-registry-1` (cut from the remote base) |
| Canonical base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `f60d8232` |
| Production runtime (read-only, 2026-10-31) | `fb3726c5`, tag `post-audit-f4bc-release-1-go`; PostgreSQL 16.15, READ COMMITTED |
| Runtime change | **yes** — one additive table, one service, one repository, two hooks in the correction service, one readiness probe |
| Activation | **none** — `rme.invoice_postpayment_correction` stays OFF; PP-10 is unchanged; refund is not built |
| Security analysis | kept in a private security record; this public record names the guarantee only |

## 1. Why

Owner decision PP-2 says a case-specific Owner approval reference authorises **one** post-payment
correction decision: the same invoice may repeat it on a resubmission, any other invoice may not. That rule
was enforced by an application check alone. This hardening adds the database guarantee, and it is
sequenced **before** any activation of post-payment correction (task instruction 2026-10-31; refund
decision package RF-3 step 2). It is also the shared registry the refund workflow (Sprint 4/7) will consume
as its second user — that consumer is **not** built here.

## 2. What was built

| Part | File |
|---|---|
| Table `trx_rme_owner_approval_references` (folded key UNIQUE; scope type + id; first consumer; append-only) with PostgreSQL CHECKs and a backfill that refuses conflicting data | `database/migrations/2026_10_31_100001_create_trx_rme_owner_approval_references_table.php` |
| Model (refuses update and delete; nothing mass-assignable) | `app/Modules/RmeInvoice/Models/RmeOwnerApprovalReference.php` |
| Repository + interface (`INSERT … ON CONFLICT (reference_folded) DO NOTHING`; integrity probe) | `app/Modules/RmeInvoice/Repositories/RmeOwnerApprovalReferenceRepository.php`, `…/Interfaces/RmeOwnerApprovalReferenceRepositoryInterface.php` |
| Registry service (`fold`, `consume`, `belongsTo`; fails closed outside a transaction and outside READ COMMITTED) | `app/Modules/RmeInvoice/Services/RmeOwnerApprovalReferenceRegistry.php` |
| Submit: consume in the invoice scope, last lock of the transaction | `RmeInvoiceCorrectionService::submit()` |
| Approve: re-verify the claim under the locks (`CORRECTION_OWNER_APPROVAL_REFERENCE_INVALID`) | `RmeInvoiceCorrectionService::assertTypeEnabled()` |
| Readiness: `OWNER_REFERENCE_REGISTRY present / claims / unregistered_post_payment_corrections`, FAIL findings | `RmeInvoiceCorrectionReadinessService`, `rme:invoice-correction-readiness` |
| Binding | `RepositoryServiceProvider` |

The existing application check (`postPaymentReferenceUsedOnOtherInvoice()`) is unchanged and still gives
the first, clear message.

## 3. Production data (read-only, 2026-10-31)

| Fact | Value |
|---|---|
| Corrections | 1 (`PRE_PAYMENT`, `APPLIED`, no reference) |
| Post-payment corrections | **0** — the backfill has nothing to copy |
| References recorded on more than one invoice | 0 |
| Registry table | absent (created by this release) |
| Overpayment obligations | 0 |

## 4. Tests

| Suite | Cases | Where |
|---|---|---|
| `tests/Feature/RmeOwnerReferenceRegistry/RmeOwnerReferenceRegistryTest.php` | 25 — a reference spent on an applied correction is refused on the same invoice (with a control on a new reference); PP-2 resubmission after STALE; a rejected correction's claim refuses another invoice through the registry alone; registration in the invoice scope; PP-2 resubmission (case variant, after cancel and reject); refusal by the registry alone; cross-workflow refusal both ways; refusal leaves no claim; rollback releases nothing it did not commit; pre-payment untouched; fail-closed outside a transaction and on malformed input; append-only; approval verifies the claim (missing / mis-scoped); backfill; conflicting data refuses the migration; `down()` refuses with claims; readiness GO and FAIL | SQLite and PostgreSQL 16 |
| `tests/Feature/RmeOwnerReferenceRegistry/RmeOwnerReferenceRegistryConcurrencyTest.php` | 8 — G1 first commits → second refused, and the loser is shown to have WAITED on the shared key (only the registry insert can make it wait); G2 first rolls back → second wins; G3 refusal is an application exception carrying `…_REUSED` (not the unique-violation translation), connection keeps working (no 25P02); G4 REPEATABLE READ refused; G5 CHECKs (unfolded key, unknown scope, zero id) and UNIQUE | PostgreSQL 16 only, REAL service in a second session (`race_child.php`, new step `submit_correction`) |

TDD: the plain suite was run first against the unmodified code (21 failed, 1 passed — the passing one is
the existing approval path, kept as a regression guard).

Every PostgreSQL race suite that writes corrections now deletes the registry first in its cleanup list
(it references `users` with RESTRICT): six files.

CI selection: token `RmeOwnerReferenceRegistry` in **both** NSF-R011 critical-gate variants; both suites
declared in `config/ci_runner.php` `critical_gate_mandatory_suites` (CG-19 — the existing
`RmeInvoiceCorrection` token does not match these class names).

## 4a. Independent security review (2026-10-31)

No CRITICAL or HIGH finding. Fixed in this pull request:

| Severity | Finding | Fix |
|---|---|---|
| MEDIUM | A reference that authorised an applied post-payment correction could authorise another one on the same invoice; rule 207 §10a allows repetition only after STALE/REJECT/CANCEL | `postPaymentReferenceSpentOnInvoice()` under the invoice lock in `submit()`; regression test + mutation (killed) |
| LOW | Two concurrency tests could pass without proving the registry refused | G1 asserts the loser waited; G3 asserts the code |
| LOW | Rule 207 §10a still described the cross-invoice race as unguarded | rewritten, points at rule 214 |
| INFO | Two reuse messages revealed which kind of decision holds a reference | one message for every reuse refusal |
| INFO | The backfill read the source table unlocked | `LOCK TABLE … IN SHARE MODE` inside the migration's transaction (PostgreSQL) |

Recorded as residuals in rule 214 §D2: pre-payment references outside the registry; separator variants as
distinct keys; append-only enforced by the model, not by a database trigger. The detailed analysis stays in
the private security record.

## 5. Rollback

The table is additive and unused while post-payment correction is OFF. A code rollback that drops the
consumer leaves the table harmless. `down()` refuses once a claim exists — a claim is audit evidence and is
never deleted to make a rollback pass.

## 6. Not done

* No activation of post-payment correction; PP-10 unchanged.
* No refund consumer (Sprint 4/7, after its decision package is merged). *Since added: the refund request is the
  registry's second consumer (rule 215, PR #569).*
* No change to the application key, the environment file, custody media or G7-B.

## 7. Deploy evidence (2026-10-31)

| Item | Value |
|---|---|
| Merge | PR #568, squash `61450801`, tree `dacd9104` == candidate `15d98300` |
| CI | run 41175295090 on `15d98300`: all required gates SUCCESS (Critical 7090 passed, 0 failed); Full Suite SKIPPED by the standing policy (not a pass) |
| Deploy | `scripts/deploy-vps-runner.sh start` on the VPS: exit=0, `DEPLOY OK: 20261031-065951`, `DEPLOY_HEAD_TARGET_MATCH=YES` |
| Backup | `pre_auto_deploy_20261031-065951.sql` (32 MB, 0640) before migrating |
| Migration | `2026_10_31_100001` ran (batch 88); 6 constraints (primary key, foreign key to `users`, unique fold, 3 CHECKs) and 3 indexes present; 0 claims (production holds no post-payment correction, so the backfill had nothing to register) |
| Readiness | `rme:invoice-correction-readiness` (as the runtime user): `OWNER_REFERENCE_REGISTRY present=yes claims=0 unregistered_post_payment_corrections=0`, Decision GO |
| Reconciliation | financial fingerprint identical before and after — only the new table and the migration count differ |
| Health | `/login`, `/health/*` 200 over the canonical domain; services active; 0 failed jobs; 0 new application errors |
| GO tag | `hardening-rme-owner-reference-registry-1-go` @ `61450801`, exact match on the VPS |
