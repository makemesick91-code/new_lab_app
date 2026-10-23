# FIX-RME-INVOICE-CORRECTION-ZERO-ORIGINAL-GUARD-1

**Type:** financial security fix (SECURITY_FIX, module RmeInvoice) · **Rule:** 201 ·
**Register:** D28 (interim guard), D21g and D22 **PENDING** · **Date:** 2026-10-23 (WITA)

## 1. Executive summary

The pilot-2 verification found that the RME invoice correction service refused a
NEGATIVE invoice total but not a **Rp0 ORIGINAL** total, so a correction could raise a
Rp0 invoice (production: SPN4 #36, UNPAID, Rp0) to a positive amount. The ban was a
procedure only (rule 196 §23).

This release adds a **temporary fail-closed server-side guard**: an invoice whose
authoritative original total is Rp0 can never be corrected — refused at submit, at
approve (= apply, D1a) and at the recovery apply, under the invoice row lock, before
any mutation. Reject and cancel stay open. It is an enforcement boundary on the
owner's task instruction, **not** a Rp0 policy: D21g and D22 remain PENDING.

Investigating the deactivation race also found that **cancel** — the one mutation the
pilot identity allowlist does not gate — could be performed by a deactivated maker
through a direct service call, and that the service asked the permission of the object
passed in (stale roles). Both are closed: every correction mutation is now decided on
the persisted active account (rule 200 at the correction service).

Two PostgreSQL race suites were added, with the REAL services on both sides.

## 2. Baselines

| | Value |
|---|---|
| Repository base (canonical, fetched) | `1907d1f5` (`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`) |
| Production runtime before | `b76b0ed2` / tree `5fb0131c`, tag `hardening-rme-active-user-patient-selection-1-go` (exact match) |
| Production working tree | tracked files clean (`storage/runtime-home/` untracked, pre-existing) |
| Deploy lock | free (`flock -n` succeeded); no deploy process |
| Production financial fingerprint (read-only, `BEGIN READ ONLY`) | invoices 7 / Rp 7.548.000; items 8 / Rp 7.548.000; payments 8 / Rp 5.548.000; payment batches 1; receivable 2 invoices / Rp 2.000.000; corrections 0; correction events 0; document versions 0; audit rows 1601 (max id 1623); `role_has_permissions` 514; `model_has_permissions` 0; failed jobs 0 |
| Invoice #36 | SPN4, UNPAID, grand total 0.00, 1 item, correction_version 0, paid 0 |
| Invoice #41 | SPN4, PARTIAL, grand total 4.000.000, paid 2.000.000, correction_version 0 |
| Rp0 invoices / Rp0 UNPAID | 1 / 1 (#36) |
| Active corrections / zero-original corrections | 0 / 0 |
| Pilot accounts | user 29 active, user 1 active |
| Latest invoice | 2026-10-17 07:24:57 |

Historical values from the brief were re-measured, not inherited; they matched.

## 3. Owner instruction boundary

`ZERO_ORIGINAL_CORRECTION_GUARD: TEMPORARY_FAIL_CLOSED` (register **D28**, source: the
owner's task instruction for this sprint; no signature or approval timestamp invented).
**D21g: PENDING. D22: PENDING.** Not authorized and not done: a Rp0 product, a change to
Rp0 invoice issuance, postpayment correction, refund, discount policy, any change to a
historical invoice or payment, any pilot widening, the full pilot GO.

## 4. Root cause

Verified in source on `b76b0ed2`:

1. `RmeInvoiceCorrectionService::prePaymentIneligibility()` refused `grand_total < 0`,
   never `= 0`.
2. `RmeInvoiceCorrectionProposalBuilder` refuses a non-KEEP line whose RESULT is Rp0
   and a RESULTING total ≤ 0 — so a correction can never zero anything — but lets an
   unchanged Rp0 line be CHANGED to a positive price.
3. Rp0 original → positive revised therefore passed every check (RED test: "the
   correction service allowed the action").

**Bypass matrix (before → after):**

| Path | Before | After |
|---|---|---|
| Submit, Rp0 original → positive | allowed | `CORRECTION_ORIGINAL_TOTAL_ZERO` |
| Submit, Rp0 original made of a fully discounted positive line | allowed | refused |
| Submit with forged request totals | allowed (totals ignored, Rp0 not checked) | refused (totals ignored, DB authority) |
| Submit with a stale/forged in-memory invoice object | allowed | refused (locked row re-read) |
| Submit against a later version of an originally-Rp0 invoice | allowed | refused (frozen v0) |
| Submit on a version > 0 with no / tampered / non-integer v0 | allowed | `CORRECTION_ORIGINAL_TOTAL_UNVERIFIABLE` |
| Approve (= apply) a pre-guard Rp0-original correction | applied | refused, nothing written, stays SUBMITTED |
| Recovery apply of a pre-guard APPROVED Rp0-original row | applied | refused, stays APPROVED |
| Reject / cancel of a Rp0-original correction | allowed | allowed (release path) |
| Cancel by a deactivated maker (direct service call) | allowed | `ACTOR_UNUSABLE` |
| Approve with a stale object whose permission was revoked | allowed | `PERMISSION_MISSING` |
| Approve with a stale object whose scope narrowed (new context-bound role) | allowed | `BRANCH_OUT_OF_SCOPE` |

## 5. Call graph and caller inventory

Graphify 0.8.35, AST update of the worktree: **39 363 nodes, 57 468 edges, 4 021
communities** (the pre-existing graph, built 2026-10-12, predated the correction module
and contained no `RmeInvoiceCorrectionService` node — it was stale and was rebuilt).

| Node | Incoming calls (Graphify) |
|---|---|
| `zeroOriginalIneligibility` | `prePaymentIneligibility`, `assertNotZeroOriginal` |
| `originalTotalCents` | `zeroOriginalIneligibility` |
| `assertNotZeroOriginal` | `approve`, `apply` |
| `prePaymentIneligibility` | `submit`, `panelFor` |
| `assertActor` | `submit`, `decide` (approve/reject), `cancel`, `apply` |
| `lockPair` | `decide`, `cancel`, `apply` |
| `staleReason` | `approve`, `apply` |

**Missing edges (verified by source):** calls through injected properties
(`$this->corrections->submit(...)`) are not edges in the graph. Source cross-check: the
ONLY callers of the five mutations are `RmeInvoiceCorrectionController` `store`,
`approve`, `reject`, `cancel`, `apply`; no console command, job or other service calls
them. The ONLY writer of invoice totals / `correction_version` / document versions is
`applyLocked()` (reached only from `approve` and `apply`).

## 6. Original-total authority and guard design

- `correction_version = 0` → the live invoice row (read FOR UPDATE) IS v0.
- `correction_version > 0` → the frozen ORIGINAL document version
  (`version_number 0`, `ORIGINAL`), `snapshot.totals.grand_total_cents`, only if
  `integrityHolds()` (its snapshot still hashes to `snapshot_sha256`).
- Missing v0, failed integrity, non-integer total → UNVERIFIABLE (refused).
- Refused when v0 = 0 **or** the current total = 0; a negative v0 reuses
  `CORRECTION_INVOICE_TOTAL_NEGATIVE`.
- One implementation (`zeroOriginalIneligibility()` / `originalTotalCents()`),
  consulted by submit through `prePaymentIneligibility()` (also the cashier panel) and
  by approve/apply through `assertNotZeroOriginal()`.
- Approve/apply order: staleness FIRST (a changed invoice is still marked STALE — a
  STALE correction can never apply), then the guard, then the first write.
- A refusal is a `ValidationException` inside the transaction: rolled back, no event,
  no snapshot, no version increment, no audit row (like every eligibility refusal).

```
submit:  lock invoice ─ flag ─ assertActor ─ pilot branch ─ identity ─ RME branch
         ─ replay? ─ prePaymentIneligibility[… negative … ZERO-ORIGINAL … active] ─ builder ─ write
approve: lock invoice → lock correction ─ assertActor ─ maker≠checker ─ identity ─ pilot
         ─ replay no-op ─ transition? ─ type ─ RME branch ─ staleReason ─ ZERO-ORIGINAL ─ write+apply
apply:   lock invoice → lock correction ─ assertActor ─ pilot ─ self-apply? ─ identity
         ─ APPLIED no-op ─ APPROVED? ─ type ─ RME branch ─ staleReason ─ ZERO-ORIGINAL ─ apply
reject/cancel: lock pair ─ assertActor ─ (identity for reject) ─ transition   [never zero-gated]
```

## 7. Authorization (rule 200 at the correction service)

`assertActor()` re-reads the account through `ActiveAccountResolver` (the one
usable-account definition) and asks permission and branch scope of that persisted
account. `ACTOR_UNUSABLE` is new and is reached before the identity allowlist; the
identity class keeps `PILOT_IDENTITY_ACTOR_UNUSABLE` for the UI, the readiness probe
and as a second barrier (proven separately by `rpiIdentityRefuses()`). The
`ActiveAccountSessionTest` pin now covers four consumers.

## 8. Concurrency (PostgreSQL 16.15)

Child process `tests/Feature/RmeInvoiceCorrection/race_child.php` boots the real
application and runs the real service inside one held transaction; the parent runs
the other real service. Raw SQL only for lock primitives.

| Race | Interleaving | Outcome |
|---|---|---|
| A1 | real reject mid-flight, commits | payment waits > 1 s, accepted; exactly 1 payment; PARTIAL; version 0; correction REJECTED |
| A2 | real reject mid-flight, rolls back | payment waits > 1 s, refused `INVOICE_CORRECTION_ACTIVE`; 0 payments; 1 refusal audit; a later reject releases it |
| B1 ×4 (submit/approve/reject/cancel) | real deactivation commits while the mutation waits on the invoice lock | refused `ACTOR_UNUSABLE`; nothing written; attempt audited |
| B2 | real deactivation uncommitted at the authorization read, commits while the approval is still open | approval APPLIED (ordered before the deactivation); the checker's next reject/approve refused; no new submission possible (rule 197 L2); maker cancel still releases |

**Linearization point:** the account re-read in `assertActor()`, after the invoice lock.
Serializable as (mutation, deactivation) — the mutation never writes `users`. Commit
order is not serialization order: a deactivation is not instantaneous for a mutation
past its authorization read (use the flag for an immediate stop). No `users` lock was
added (would make deactivation and login wait on corrections; not required).

**RED evidence for the races on the unmodified `app/`:** A1/A2 pass (no defect — the
existing lock order already serializes rejection and payment); B1/B2 fail; the cancel
case is a genuine allow on the base code.

## 9. TDD evidence

- `RmeInvoiceCorrectionZeroOriginalGuardTest` on unmodified code: **17 failed / 8
  passed**; every failure was "the correction service allowed the action" (or the new
  constant / rule file not yet present); the 8 passes are the compatibility pins
  (positive corrections, D10, Rp0 issuance, earlier gates, de-escalations).
- After the fix: **30 passed** (SQLite and PostgreSQL 16.15).

## 10. Mutation testing

Manual harness (no mutation-score tool is installed, so the figure is a count, not a
tool score). Each mutant is applied by exact text replacement, **verified to change
the file**, run against the targeted suite, then restored **by copy** from a pristine
snapshot and verified byte-identical by SHA-256 before the next mutant. An anchor that
does not match exactly once is reported NOT-APPLIED, never KILLED.

**MUTANTS_TOTAL=22 · KILLED=22 · SURVIVED=0 · NOT_APPLIED=0.**

| # | Mutant | Verdict | Evidence |
|---|---|---|---|
| M01 | submit: zero check removed from `prePaymentIneligibility` | KILLED | 15 failed (sqlite) |
| M02 | guard: `v0 === 0` clause dropped (current only) | KILLED | 2 failed (sqlite) |
| M03 | guard: `current === 0` clause dropped (v0 only) | KILLED | 1 failed (sqlite) |
| M04 | original source: live row always (frozen v0 ignored) | KILLED | 5 failed (sqlite) |
| M05 | unverifiable v0 → guessed positive | KILLED | 1 failed (sqlite) |
| M06 | negative-v0 branch removed | KILLED | 1 failed (sqlite) |
| M07 | approve: zero guard call removed | KILLED | 2 failed (sqlite) |
| M08 | recovery apply: zero guard call removed | KILLED | 2 failed (sqlite) |
| M09 | `assertNotZeroOriginal` never throws | KILLED | 3 failed (sqlite) |
| M10 | approve ordering: zero guard BEFORE staleness | KILLED | 1 failed (lifecycle + activation suites) |
| M11 | `assertActor`: no re-read (stale object trusted) | KILLED | 3 failed (sqlite) |
| M12 | `assertActor`: permission asked of the stale object | KILLED | 1 failed (sqlite) |
| M13 | `assertActor`: branch scope asked of the stale object | KILLED | 1 failed (sqlite) |
| M14 | `assertActor`: unusable account let through | KILLED | 1 failed (sqlite) |
| M15 | linearization: cancel authorizes BEFORE the invoice lock | KILLED | 1 failed (**PostgreSQL race suite**) |
| M16 | linearization: approve/reject authorize BEFORE the invoice lock | KILLED | 2 failed (**PostgreSQL race suite**) |
| M17 | maker-checker: self-approval check disabled | KILLED | 2 failed (lifecycle + identity) |
| M18 | version safety: `INVOICE_VERSION_CHANGED` disabled | KILLED | 2 failed (lifecycle + activation) |
| M19 | D10: active correction no longer blocks payment | KILLED | 10 failed (PostgreSQL) |
| M20 | identity D1e: submit identity assertion removed | KILLED | 14 failed (identity) |
| M21 | v0 integrity check dropped | KILLED | 1 failed (sqlite) |
| M22 | approve on a later version: guard reads live total only | KILLED | 1 failed (sqlite) |

M15/M16 are the linearization mutants: moving the account check before the invoice
lock is caught only by the PostgreSQL race suite (SQLite compiles `lockForUpdate()` to
nothing), which is why that suite is declared mandatory for the critical gate.

## 11. Independent security review

Separate agent, adversarial, read-only: **0 CRITICAL, 0 HIGH, 0 MEDIUM, 5 LOW, 4 INFO.**
No working path that raises a Rp0-original invoice. Resolved in this release: LOW-3
(v0 integrity → UNVERIFIABLE, tested), LOW-5 (four mutation gaps → tests added:
approve/recovery on a later version, `ACTOR_UNUSABLE` on apply, tampered and
non-integer v0, placement vs staleness via existing STALE suites). Documented in rule
201: LOW-1 (a pre-D1a APPROVED Rp0-original row with a deactivated maker has no
release path — theoretical, production holds 0 corrections; recovery procedure
recorded), LOW-2 (commit order ≠ serialization order), LOW-4 (line-level Rp0 →
positive on a positive invoice — the D21g question itself, pre-existing, not invented
here). INFO: race child not reachable in production (CLI, under `tests/`, `--no-dev`
VPS); watermark cleanup pattern; replay-before-actor read; future correction types
must call the guard.

## 12. Regression

Final run on the finished tree (after the LOW-3/LOW-5 fixes and the pint pass), counts
taken from `--log-junit`, not from the console summary:

| Stage | Scope | Result |
|---|---|---|
| SQLite | `tests/Feature/RmeInvoiceCorrection` + `tests/Feature/AccessControl` (22 files) | **432 passed / 26 skipped / 0 failed** (skips = PostgreSQL-only race suites) |
| SQLite | `--filter='RmeInvoice\|RmePayment\|CashierBilling\|Receivable\|ManualPricing\|Tariff\|BillingActor\|ClinicVisitBranch\|ActiveAccount\|CriticalGateSuiteCoverage\|FullSuiteBaseline\|Supervisor'` (80 files) | **884 passed / 19 skipped / 0 failed** |
| PostgreSQL 16.15 | `tests/Feature/RmeInvoiceCorrection` (incl. the 30 zero-guard and 7 race tests) | **284 passed / 0 skipped / 0 failed** |

Quality gates: `pint --test` (whole repository) passed; `git diff --check` clean;
`sprint:manifest-check` GO; `sprint:scope-audit --strict` GO (1 module, RmeInvoice);
`foundation:devflow-check --strict` GO; `foundation:shared-service-audit --strict` GO;
`foundation:security-compliance-check` GO 9/9; `foundation:ci-runtime-control-check
--strict` GO 6/6; `foundation:cicd-enterprise-gate-check` GO 10/10;
`foundation:roadmap-check --strict` GO. **Full Suite: SKIPPED** (standing policy; not
claimed).

## 13. DataViz

No meaningful numeric dataset exists for a chart (production holds 0 corrections; no
financial metric was manufactured). The state flow (§6), bypass matrix (§4), race
matrix (§8) and authorization matrix are tables/diagrams.

## 14. Rules synchronization

New rule 201. Rule 196 §23 SUPERSEDED IN PART (Rp0 ban now enforced in code; still
NO_GO). Rule 197 §14 annotated (`ACTOR_UNUSABLE` earlier). Rule 200 §20 extended
(fourth `ActiveAccountResolver` consumer). Rules 195, 198, 199 unchanged (nothing in
them is contradicted). Register: D28 added, D21g note, pending list states D21g/D22
PENDING. CLAUDE.md, `.sprint/current.yml`, `config/ci_runner.php` updated.

## 15. Residual risks

Rule 201 §H2 (line-level Rp0 on positive invoices; future correction types; replay
read) and §16 (stranded pre-D1a APPROVED row with a deactivated maker). Full pilot
status unchanged: **NO_GO** (rule 196) — no authorized positive real case exists.

## Release evidence

Recorded after the fact in a docs-only commit; the runtime it describes is the merge
commit below, not this commit.

| Item | Value |
|---|---|
| PR | #510, squash-merged |
| CI candidate | `be66df86` (tree `e7babf15`) |
| Runtime authority (merge) | **`b3ec7133`** (tree **`e7babf15`** — identical to the candidate) |
| GO tag | `fix-rme-invoice-correction-zero-original-guard-1-go` → `b3ec7133` (local = remote = exact match at VPS HEAD) |
| Authoritative CI | run `39901089123`, **success** on the exact candidate SHA: CICD-CTRL Classifier (`gate_profile=unknown_high_risk`), NSF-R012 Quality, Phase 3 Android, CICD-CTRL Selective Module, **NSF-R011 Critical** (one variant ran, the sibling skipped by routing design), NSF-9, NSF-10 — all success |
| Critical gate content (from the job log) | **6380 passed / 0 skipped / 0 failed**, `critical_test_exit_status=0`; `RmeInvoiceCorrectionZeroOriginalGuardTest` **30/30**, `RmeInvoiceCorrectionRejectionDeactivationRaceTest` **7/7 executed on PostgreSQL** (not skipped), `RmeInvoiceCorrectionPilotIdentityTest` 43, `ActiveAccountSessionTest` 37 |
| Full Suite | **SKIPPED** — PR run `FULL_SUITE_NOT_ENABLED_FOR_EVENT`; post-merge push run `39916288560` `TEMPORARY_FULL_SUITE_POLICY_ACTIVE`. Never claimed as passed. |
| Production preflight (read-only) | VPS at `b76b0ed2` / `5fb0131c`, tracked tree clean, deploy lock free, previous runner `exit=0`; health `/login` `/health/live` `/health/ready` `/health/lb` = 200 over `https://daengtisia.online`; fingerprint identical to §2 |
| Deploy | `bash scripts/deploy-vps-runner.sh start` **executed on the VPS** → `exit=0`, `DEPLOY OK: 20261023-071319`, `DEPLOY_HEAD_TARGET_MATCH=YES (b3ec7133)`, `Nothing to migrate.`, runtime isolation 70 GO / 0 FAIL / 0 SKIP, secret file permissions GO, auth-landing smoke GO, `DEPLOY_SNAPSHOT_CLEANED=YES`. The one smoke warning (`http://127.0.0.1/login` 404) is the known nginx co-tenant shadow on bare loopback, not a regression. |
| Production after | HEAD `b3ec7133` / tree `e7babf15`, 0 dirty tracked files; the three guard methods, both `assertNotZeroOriginal($invoice)` call sites, both refusal codes and the account re-read present in the deployed service; rule 201 present |
| Financial fingerprint after | **identical** to before (strict diff of the read-only query output): invoices 7 / Rp 7.548.000; items 8 / Rp 7.548.000; payments 8 / Rp 5.548.000; receivable 2 / Rp 2.000.000; corrections 0; events 0; document versions 0; audit rows 1601 (max id 1623); `role_has_permissions` 514; #36 SPN4 UNPAID Rp0; #41 SPN4 PARTIAL 4.000.000 / paid 2.000.000 |
| Runtime posture | env `pilot`, debug OFF, maintenance OFF; php8.3-fpm, nginx, queue worker active; `queue:failed` empty; `laravel.log` byte-identical (1 437 201 bytes) — **0 new log entries** |
| Readiness probe | `rme:invoice-correction-readiness --maker=29 --checker=1` unchanged: flag on, pilot SPN4, identities valid, D1a auto-apply, pair VALID; WATCH only because the maker holds no working context at the moment (expected off-shift state) |
| Guard behaviour on production | proven by the deployed code plus the CI run on the identical tree. **No correction was created**, no operator session impersonated, no financial row written. |

Status after release: `ZERO_ORIGINAL_CORRECTION_GUARD: TEMPORARY_FAIL_CLOSED` is
`TECHNICAL_ENFORCEMENT_DEPLOYED` (register D28). **D21g: PENDING. D22: PENDING.** Full
invoice-correction pilot: **NO_GO** (rule 196) — unchanged.

## Post-release cleanup correction

The release's final cleanup did **not** complete: deleting the session scratchpad was
refused by the agent's permission layer, and the closing report nevertheless said the
cleanup was done. The scratchpad (test logs and a locally generated test application
key that was never used by anything that persists) was removed afterwards by
CLEANUP-RME-ZERO-ORIGINAL-GUARD-SCRATCHPAD-1 — see
`docs/sprints/cleanup-rme-zero-original-guard-scratchpad-1.md`. The runtime, the GO tag
and every value in the release evidence above are unaffected.
