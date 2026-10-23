# PREPARATION-RME-INVOICE-CORRECTION-PILOT-OPERATIONAL-READINESS-1

Operational-readiness sprint for the live pre-payment RME invoice correction pilot
(SPN4 — Cabang Sunu, maker user 29, checker user 1). It writes the counter
procedure the pilot needs before a genuine case can be closed; it changes no
runtime behaviour, creates no financial record and makes no owner decision.

**Outcome: `OPERATIONAL_READINESS = GO` (scoped); `FULL_PILOT_GO = NO_GO`.**
Documentation and configuration are ready; the authenticated operators, a
genuine eligible case and a real reconciled correction are still pending. No tag
(§12). No deploy (`DEPLOY_REQUIRED=NO`, `NO_RUNTIME_DELTA`).

All times UTC unless marked WITA (UTC+8).

## 1. Repository and production baseline (measured 2026-10-23 11:30 UTC = 19:30 WITA, read-only)

| Item | Value |
|---|---|
| Canonical base | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `9e1d0197` (PR #512, docs only) |
| Task worktree / branch | `/home/fikri/Projects/rme-correction-ops-readiness-1` / `docs/rme-invoice-correction-pilot-operational-readiness-1` |
| Runtime delta base vs production | none — `git diff b3ec7133 9e1d0197 -- app config routes database resources bootstrap composer.lock` is empty |
| Production HEAD / tree | `b3ec7133650cf6ea3fa11ea9038c40c7116364aa` / `e7babf15f4eaf1b92051984534cfe72034fc47a4` |
| Production tag (exact match) | `fix-rme-invoice-correction-zero-original-guard-1-go` |
| Production working tree | 1 pre-existing untracked dir `storage/runtime-home/` (not task-owned) |
| Deploy lock / deploy processes | free (`flock -n` succeeded) / none |
| Open PRs / CI in flight at start | 0 / 0 |
| Health over `https://daengtisia.online` | `/login`, `/health/live`, `/health/ready`, `/health/lb` → 200 |
| Services | nginx, php8.3-fpm, queue worker → active |
| `laravel.log` | 1 437 201 bytes, 162 `.ERROR:` lines — byte-identical before and after every probe in this task |
| Environment file | `640 root:daengtisiams` (unchanged; contents never read) |
| Failed jobs / pending jobs | 0 / 0 |

### Financial fingerprint (no patient data)

| Measure | PILOT-2 (2026-10-23 06:27 WITA) | This run (2026-10-23 19:30 WITA) |
|---|---|---|
| Invoices (incl. trashed) | 7 / Rp 7.548.000 | 7 / Rp 7.548.000 |
| Invoice items | 8 / Rp 7.548.000 | 8 / Rp 7.548.000 |
| Payments | 8 / Rp 5.548.000 | 8 / Rp 5.548.000 |
| Payment batches | 1 | 1 |
| Invoices with `correction_version` ≠ 0 | 0 | 0 |
| Corrections / lines / events / document versions | 0 / 0 / 0 / 0 | 0 / 0 / 0 / 0 |
| `sys_audit_logs` (rows / max id / correction-related actions) | 1601 / 1623 / 0 | 1601 / 1623 / 0 |
| `role_has_permissions` / `model_has_permissions` | 514 / 0 | 514 / 0 |

Every invoice is SPN4: PAID 5 (Rp 3.548.000), PARTIAL 1 (#41, Rp 4.000.000, paid
Rp 2.000.000), UNPAID 1 (#36, Rp 0). Latest invoice 2026-10-17 07:24:57, latest
payment row 2026-10-17 07:25:18. **Positive UNPAID SPN4 invoices with zero
payments: 0.** Two open SPN4 visits (1 `cashier_pending`, 1 `registered`) have
no invoice — not correction candidates, not touched. No session row for user 29
or user 1; user 29's online-context row reads `status=online`,
`last_seen_at` 2026-10-17 07:25:46 (rule 196 §4: not "on shift").

## 2. Pilot configuration and readiness probe

`runuser -u daengtisiams -- env HOME=/tmp php artisan rme:invoice-correction-readiness --maker=29 --checker=1`
(exit 0, `laravel.log` unchanged):

```
STATE=PILOT_ACTIVE
FLAG_ENABLED=true env_resolution=env
PILOT_DECLARED=SPN4 ADMITTED=SPN4
PILOT_IDENTITIES valid=yes maker=29 checker=1 (owner D1e, rule 197)
APPLICATION=AUTO_ON_APPROVAL (owner D1a)
POSITIVE_UNPAID_UNSETTLED_INVOICES_IN_PILOT=0
PAIR maker=29 checker=1 distinct=yes designated=yes maker_ok=yes checker_ok=yes shared=SPN4 VALID=yes LIVE_NOW=no
[WATCH] PAIR_MAKER_NOT_ON_SHIFT_IN_PILOT 29 is eligible for SPN4 but holds no working context there now
Decision: WATCH
```

Role grants unchanged (Admin Klinik approve; Front Office request+approve; Kasir
request; Super Admin all three). User 29: active, Front Office, eligible SPN4.
User 1: active, Super Admin + Owner, live scope ATG3/LDK2/SPN4/TLK1, pilot SPN4.
`foundation:feature-flags`: `rme.invoice_correction enabled=true via=env`,
`front_office.branch_context_lock=true`, `front_office.branch_device_lock=false`
(user 29 needs no device ceremony), `doctor.single_active_session=true`.

## 3. Owner decisions in force (unchanged)

D1, D1a, D1b, D1c, D1d, D1e, D7, D10, D21 (manual pricing), D28 (interim Rp0
guard), OD-1, OD-1a — as recorded in the register. **D21c, D21g and D22 remain
PENDING.** Nothing was decided, changed or inferred. One open question was
recorded, explicitly not as a decision: **OPS-OQ-1** (§7).

## 4. Correction operational call graph (Graphify + source)

Graphify 0.8.35 canonical graph (`graphify-out/`, refreshed 2026-10-23) contains
the correction module (`RmeInvoiceCorrectionService` degree 44,
`RmeInvoiceCorrectionPilotIdentity`, `ActiveAccountResolver`,
`RmePaymentService.assertNoActiveCorrection()`). Graphify records no edge for a
method called through an injected property, so controller→service edges were
cross-checked in source:

| Path | Evidence |
|---|---|
| Maker submit | `RmeInvoiceCorrectionController@store` (404 outside pilot, 403 `mayRequest`, L129) → `RmeInvoiceCorrectionService::submit()` L306 → invoice lock → `assertActor()` (ActiveAccountResolver + permission + working-branch scope) L338 → pilot branch + identity (submit) → `prePaymentIneligibility()` L924 (incl. `zeroOriginalIneligibility()`, Rp0 guard) |
| Checker approve (= apply, D1a) | `@approve` → policy `approve` L141 → `approve()` L451 → `decide()` → `lockPair()` L829 (invoice then correction) → `assertActor()` L787 → identity (approve) → `staleReason()` L477 → `assertNotZeroOriginal()` L487 → `applyLocked()` L502 (`AUTO_ON_APPROVAL`) |
| Reject | `@reject` → `reject()` L506 → `decide()` (identity checker; no branch-pilot gate; no zero guard) |
| Cancel | `@cancel` → `cancel()` L536 (maker-only, identity-ungated, `assertActor` → `ACTOR_UNUSABLE` if inactive) |
| Recovery apply | `@apply` → `apply()` → `applyLocked()` L634 (`MANUAL_RECOVERY`, checker identity) |
| Only writer of totals/version/snapshots | `applyLocked()` → `freezeVersion()` (v0 ORIGINAL, vN REVISION + `RME_INVOICE_DOCUMENT_VERSION_ISSUED` audit) → `applyCorrectionTotals()` |
| D10 | `RmePaymentService` under `lockForUpdate`: `pay` L124–128, control L184–187, carry-over parent L198–212, visit current L340–343, each receivable L354–369 → `assertNoActiveCorrection()` L624; version binding `assertFormVersion()` L551 |

## 5. Documents delivered (operator-facing, Bahasa Indonesia)

| File | Covers (prompt §) |
|---|---|
| `docs/operations/rme-invoice-correction-pilot-sop-spn4.md` | scope, roles, full workflow diagram, eligibility table + decision tree (§12), Front Office SOP (§13), Owner-unavailable (§15), status lifecycle |
| `docs/operations/rme-invoice-correction-owner-approval-template.md` | case-specific approval form with every required field (§14), approval-reference format, who fills what, checker comparison list |
| `docs/operations/rme-invoice-correction-maker-checker-guide.md` | responsibility matrix, maker 29 SOP (§16), D10 SOP + flow (§17), checker 1 SOP (§18), D1a verification (§19), rejection/cancellation/inactive maker/Rp0 (§21) |
| `docs/operations/rme-invoice-correction-financial-reconciliation.md` | read-only SQL, expected deltas, before/after form with the 14 checks (§20) |
| `docs/operations/rme-invoice-correction-incident-escalation.md` | matrix A–R with condition/action/role/escalation/evidence/restart (§22), emergency stop, message codes |
| `docs/operations/rme-invoice-correction-operator-handoff.md` | training one-pager + illustrative example (§23), master checklist A–S with six non-collapsed levels (§25), GO readiness matrix, probe (§26), sign-off |

Accuracy controls: every quoted status label, button, message, code and constant
was checked against `app/`, `resources/views/`, `config/`, `routes/` by a script
(62 codes, 42 UI strings, `REASON_MIN=10`, `REASON_MAX=1000`, identities
`[29]`/`[1]`, `['SPN4']` — 0 missing). The reconciliation SQL was executed on
production under `default_transaction_read_only=on` (first line `on`, exit 0)
with invoice #36 as a read probe; output carried ids/amounts/hashes only and the
fingerprint was unchanged. Expected deltas were derived from
`RmeInvoiceCorrectionService` (`recordTransition`, `freezeVersion`, `applyLocked`),
not from documentation.

## 6. Readiness levels (never collapsed)

| Level | Status | Evidence |
|---|---|---|
| `DOCUMENTATION_READY` | **YES** | §5 |
| `CONFIGURATION_READY` | **YES** | §2 probe `PILOT_ACTIVE`, `VALID=yes` |
| `AUTHENTICATED_READY` | **PENDING** | `LIVE_NOW=no`; no session for 29/1 |
| `REAL_CORRECTION_READY` | **NO** | 0 eligible invoices |
| `FINANCIAL_VERIFIED` | **NOT_PERFORMED** | no correction |
| `FULL_GO` | **NO_GO** | rule 196 |

## 7. Operational blocker recorded — OPS-OQ-1 (open owner question, not a decision)

The prompt's Owner-unavailable path requires following "the clinic's existing
authorized billing-error and patient-resolution process". **No such procedure
exists in the repository**: issued invoices cannot be edited (D21 §6), there is no
void/cancel invoice path in `RmeInvoiceService`, and rule 196 §21 only names an
"existing pre-pilot procedure" that is defined nowhere. Inventing one would be a
new financial policy. Recorded instead (SOP §8, register, rule 202 §19): the
patient is never held, no correction is filed without approval, no known-wrong
amount is collected as if resolved, clinic management decides about the patient,
the case is `NOT_EXECUTED`, and such an invoice is not used as a pilot case until
the owner answers. This does not block operational readiness: the safe action is
fully defined; only the commercial resolution is the owner's.

## 8. Independent security review

A separate read-only reviewer (no edits, no tests, no SSH) reviewed the six
operator documents, rule 202 and the register/rule edits against the prompt's
threat list and against the source, with file:line evidence. **No runtime
vulnerability: the code enforces every control the documents describe.**

Round 1 — documentation findings (verdict FAIL):

| # | Sev | Finding | Fix |
|---|---|---|---|
| 1 | MEDIUM | After reject/cancel the documents told the cashier to "continue at the original amount" even when the error was still known — contradicting the SOP's own ban, pre-answering OPS-OQ-1, and irreversible (a paid invoice is uncorrectable in the pilot) | "Release is not permission to collect": SOP §9 + new §9.1, guide §3.3/§6.1/§6.2, incident C/H/I/K/Q, handoff §1.1/§1.3, rule 202 §16 |
| 2 | MEDIUM | Approval reference could be "recorded on the Owner's behalf"; the checker's provenance check was circular (the reference in the reason is typed by the maker) | only the Owner writes the reference; the checker obtains the form directly from the Owner's own channel, never relayed (template, SOP §7, guide §4 step 6, handoff step 7, rule 202 §13) |
| 3 | LOW | No expected deltas for STALE | reconciliation §4: correction +1, events +2, audit +2, document versions 0 |
| 4 | LOW | Authorization codes listed as "visible" render as a plain 403 without code; some visible codes missing | incident matrix split into on-screen codes vs audit-only codes; added `CORRECTION_INVOICE_TOTAL_NEGATIVE`, `CORRECTION_STATE_INVALID`, stale reasons `INVOICE_REMOVED`/`LAB_CANDIDATES_EXIST`, `INVOICE_VERSION_MISSING` |
| 5 | LOW | Screenshot evidence would carry patient name and RM number | evidence is text, never a raw screenshot (SOP §6.2, guide, incident P, rule 202 §21) |
| 6 | LOW | Reconciliation SQL runs as superuser; read-only is a session default | recorded as accepted residual (no write statement; first line asserts `on`; least-privilege read role needs its own sprint) |
| 7 | INFO | Checker told to confirm "user 29" while the screen shows the name | "Admin Sunu (akun user 29)" in guide and template |
| 8 | INFO | Legacy APPROVED row of an inactive maker described as having no release path | recovery apply releases a non-Rp0 row; only the Rp0 + inactive-maker case needs the rule 201 §16 path (guide §6.3, rule 202 §16) |

Round 2 — re-verification: **PASS**, all eight fixed; three LOW leftovers
reported (incident row K, template line on "user 29", rule 202 §16 wording) and
fixed afterwards. Threats mitigated by the documents: wrong branch,
unauthorized maker/checker, self-approval, Rp0 bypass, partial-payment
correction, stale version, duplicate payment, duplicate correction, historical
snapshot modification, refund activation, post-payment correction; D21g/D22/D21c
shown PENDING everywhere. Accepted residuals: superuser read-only (finding 6);
human separation of the two accounts and of Owner vs checker is not verifiable by
the application (SOP §3).

## 9. Rules synchronization

- **Rule 202 (new)** `.cursor/rules/202-rme-invoice-correction-pilot-operational-readiness.mdc`
  — the operational contract (34 items: scope, procedure, patients/evidence/privacy,
  readiness levels, documentation integrity, release governance). Next free number
  after 201, verified.
- **Rule 196** gains a header pointer to rule 202 (no rung, no tag added; §14 stays
  the only full-closure tag). Rules 195, 197, 201 unchanged — they are cited, not
  restated.
- **Decision register**: dated note under "Activation requires" (procedure recorded,
  production re-measured, OPS-OQ-1). No decision changed; D21c/D21g/D22 PENDING.
- **CLAUDE.md**: section added.
- **`.sprint/current.yml` deliberately not changed**: it still describes the last
  runtime release (b3ec7133). The docs-only verification/cleanup sprints of this
  series (#500, #509, #512) left it in place, because it is not Markdown and
  changing it would reclassify a documentation change set as `unknown_high_risk`
  without adding any runtime evidence (rule 202 §28).
- Governance tests that read the register and rule files were run locally (§10).

## 10. DEVFLOW and local verification

Run in the task worktree against a migrated throwaway SQLite file, with the
application key generated inline per command (never written to a file):

| Gate | Result |
|---|---|
| `sprint:manifest-check` | GO |
| `sprint:scope-audit --strict` | GO |
| `foundation:devflow-check --strict` | GO |
| `foundation:shared-service-audit --strict` | exit 0 |
| `foundation:ci-runtime-control-check --strict` | GO (6/6) |
| `foundation:security-compliance-check` | GO (9/9) |
| `foundation:cicd-enterprise-gate-check` | GO (10/10) |
| `foundation:enterprise-documentation-check` | GO (21/21) |
| `foundation:roadmap-check --strict` | GO |
| `scripts/ci/resolve-gates.sh --changed-files-stdin` | `gate_profile=docs_only`, `run_critical_tests=false`, `run_full_suite=false` |
| `git diff --check` | clean |
| Doc-vs-code script (operator docs + rule 202) | 76 quoted codes, 42 UI strings, constants — 0 missing |

Governance tests that read the decision register and the rule files, plus the
whole correction directory, were run locally because the docs-only classifier
skips the critical Pest step in CI: `tests/Feature/RmeInvoiceCorrection`,
`RmeTariffAuthorityGovernanceTest`, `RmeManualPricingGovernanceTest` —
**295 tests: 278 passed, 17 skipped (PostgreSQL-only concurrency suites, which
skip off PostgreSQL by design), 0 failed, 1407 assertions** (counts from JUnit;
without an application environment file Pest labels every test a warning). This
re-runs shipped runtime behaviour on local PHP 8.5 against the edited register;
it is not CI evidence and proves no new runtime behaviour.

## 11. DataViz

The DataViz skill was consulted. Its form heuristic: the subject is a process, not
a quantitative series, and every financial measure is flat (zero corrections), so
a chart would draw nothing. Seven diagrams were produced as Mermaid in the operator
documents instead: pilot acceptance workflow, maker-checker responsibility matrix,
eligibility decision tree, correction lifecycle (state diagram), D10 payment-block
flow, reconciliation flow, GO readiness ladder.

## 12. Deployment and tag decision

- **`DEPLOY_REQUIRED=NO`** — no runtime file changed; production keeps
  `b3ec7133`. A same-SHA redeploy would manufacture `DEPLOY OK` (rule 196 §15).
- **`GO_TAG=NONE`** — operational work without a runtime change closes in an
  evidence document (rule 72 practice; precedents #500, #509, #512). A tag on a
  documentation commit would read as a runtime release and would sit beside the
  deployed `b3ec7133` without describing it. `verification-rme-invoice-correction-prepayment-pilot-1-go`
  stays reserved for `FINANCIAL_CORRECTION_VERIFIED` (rule 196 §14). No historical
  tag moved.

## 13. Full pilot — remaining blockers

1. A genuine positive UNPAID SPN4 invoice with an error noticed before payment
   (cannot be created).
2. Case-specific Owner approval for that invoice.
3. Users 29 and 1 logged in themselves; probe `LIVE_NOW=yes`, Decision GO.
4. Real submission → D10 observed → approval (auto-apply) → reconciliation PASS.
5. Owner answer to OPS-OQ-1 (procedural, not a technical blocker).
