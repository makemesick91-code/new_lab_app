# Legacy RME Steady-State Operations Runbook

**Sprint:** LEGACY-RME-STEADY-STATE-OPS-1
**Audience:** migration supervisor, maker (importer), checker (reviewer/publisher)
**Status:** canonical for ROUTINE batches

---

## 0. What changed, and what did not

Legacy RME migration is no longer an engineering exercise per batch. Every
control it needs already shipped and **none of them changed in this sprint**:
capability, admission, capacity backpressure, quota, operator assignment,
pause/drain, reconciliation, completion sign-off, source-RM binding and
separation of duties all behave exactly as before.

What this sprint added is an **operating model** plus one consolidated
pre-flight, so a trained operator can run a batch without a developer.

| Question | Answer |
|---|---|
| Is Legacy RME migration still an engineering wave per batch? | No. It is routine operations. |
| Does a routine batch need a GO tag? | **No.** GO tags are for software/governance changes. A batch produces operational evidence and an approval closure. |
| Is Wave-3 back? | **No.** `ROLL-4-WAVE-3` remains **SKIPPED / NOT REQUIRED** and is never reopened. |
| Can I run two batches at once? | No. See §3. |

### The engineering-mode runbooks are still authoritative for detail

This runbook is the **operating procedure**. It does not replace:

- `docs/runbooks/legacy-rme-migration-operations-runbook.md` — ROLL-4 wave
  mechanics, QA sampling, abort recovery, emergency stop, source-RM diagnosis.
- `docs/runbooks/legacy-rme-pdf-rollout-runbook.md` — enablement and rollback.

When this runbook says "register the batch", the exact command syntax lives in
the ROLL-4 runbook.

---

## 1. What makes a batch "routine"

A batch is routine only if **all** of these hold. Miss one and it is an
engineering exercise that needs its own approval, not routine work.

- **Explicitly approved** — a *fresh* approval reference. Wave-1 and Wave-2R
  approvals are historical evidence and are never reused.
- **Branch-scoped** — the enrolled branches are named. Never "all branches".
- **Quota-bounded** — an exact daily ceiling exists.
- **Time-bounded** — a planned start and end date exist.
- **Maker/checker assigned** — operators assigned, and the publisher is not the
  importer.
- **Source documents frozen** — the candidate set is fixed *before* opening.
- **Reconciliation required** — closure requires a balanced ledger.

### Sizing

| Bound | Value | Why |
|---|---|---|
| Routine default / day | **25** | The ingestion queue's own single-worker pending ceiling. Beyond it uploads are already refused by backpressure, so a bigger allowance cannot make documents flow faster. |
| Routine maximum / day | **100** | 4× the queue ceiling. The binding constraint here is *human review* throughput. |
| Above 100 | not routine | Needs elevated approval naming who accepted the larger blast radius. |
| Absolute rail | **500** | Server-side, unchanged by this sprint (`max_declarable_daily`). |

> **These are bounded defaults, not a benchmark.** Production evidence to date is
> Wave-1 (1 document) and Wave-2R (4 documents) — five documents total, far too
> small a sample to fit a rate to. Measured throughput is **NOT YET AVAILABLE**.
> Revise these once a real batch produces measured review-and-publish timings.

### Naming

New batches: `LRME-<BRANCH>-<YYYYMMDD>-<NN>` — e.g. `LRME-LDK2-20260817-01`.

Advisory only; nothing refuses a differently-named batch. Historical codes
(`WAVE-1`, `WAVE-2R`) are **never renamed** — they are immutable evidence.

`WAVE-3` is a **retired identity** and *is* refused: work declared under it has
no approval record and never will.

---

## 2. The one command to run first

```bash
# On the VPS, in the app directory. Read-only — opens nothing, writes nothing.
php artisan legacy-rme:ops-readiness
```

It composes the deployment gate, the operations layer, SOD posture, the clinical
calendar, admission/approval, batch binding and window, sizing, reconciliation,
queue headroom and **backup freshness** into one decision, and prints a
per-branch readiness matrix.

| Output | Meaning |
|---|---|
| `Ready for a routine batch: YES` | Every gate is GO. You may proceed. |
| `Ready for a routine batch: NO` | Do not open a batch. Read the blockers. |
| `Decision: WATCH` | Degraded. Fix before the next batch; it does not block continuing work. |
| `STOP THE LINE` | Pause admission and preserve evidence *now*. See §8. |
| `Resting state: AT_REST` | Capability off, nothing admitted, no batch open, books balanced. |
| `Resting state: BATCH_IN_PROGRESS` | A batch is legitimately open. Not a fault. |
| `Resting state: NOT_AT_REST` | Should be at rest but is not. Investigate. |

Exit codes, for monitoring hooks:

| Code | When |
|---|---|
| `0` | GO, or WATCH without `--strict` |
| `1` | NO_GO — always. A FAIL or an unevaluated check never exits success. |
| `1` | WATCH, with `--strict` / `--fail-on-warning` |

Useful flags:

```bash
php artisan legacy-rme:ops-readiness --json                 # machine-readable, PII-free
php artisan legacy-rme:ops-readiness --strict               # release-gate strictness
php artisan legacy-rme:ops-readiness --branch=LDK2          # scope the branch matrix
php artisan legacy-rme:ops-readiness --skip-monitoring      # fast, openly degraded
```

> `--skip-monitoring` means backup freshness **cannot be established**, so the
> decision becomes NO_GO by design. A batch is never opened on the assumption
> that a restore point exists.

---

## 3. Multi-branch: what is actually supported

| Scope | Mode |
|---|---|
| **Across batches** | **SEQUENTIAL.** One declared batch code resolves one operative batch. There is no supported way to run two batches at once, and this sprint deliberately did not add one. |
| **Within one batch** | **CONCURRENT, BRANCH-ISOLATED.** One batch enrols many branches; each carries its own status, quota, operators and pause/drain/complete transitions. |

So multi-branch operations are real, and their shape is **one batch, many
branches** — not many simultaneous batches. This is a reading of
`LegacyRmeWaveBindingService`, not an aspiration.

A branch reported `NOT_READY` because it is deliberately closed is **not a
fault** — there is nothing to remediate.

---

## 4. Opening a batch

Run these in order. Do not skip ahead; later steps assume earlier ones.

1. **Confirm the approval.** Fresh reference, named branches, quota, window,
   maker and checker identified. Historical approvals are never reused.
2. **Freeze the candidate set.** Fix which documents are in scope before opening.
3. **Take and verify a backup.** Mandatory — see §5.
4. **Run the pre-flight.**
   ```bash
   php artisan legacy-rme:ops-readiness
   ```
   Do not proceed unless `Ready for a routine batch: YES`.
5. **Register, approve and activate the batch**, and enrol its branches
   (ROLL-4 runbook §3–§4). Declare a daily quota and a planned window.
6. **Assign operators** — maker and checker, per branch (ROLL-4 runbook §5).
7. **Open admission** for exactly the approved branch codes.
8. **Re-run the pre-flight** and confirm the batch binds:
   ```bash
   php artisan legacy-rme:ops-readiness
   php artisan legacy-rme:migration-status
   ```

---

## 5. Backup — the gate that did not exist before

A batch mutates clinical staging state. Without a verified restore point, that is
the one failure this programme cannot walk back.

```bash
# Take it, then verify it. Both steps, in this order.
bash scripts/backup-vps.sh
php artisan foundation:backup-verify
```

- Maximum age at open: **24 hours** (one working day). Older than that and the
  restore point is not one the operator recognises.
- **If the backup or its verification fails: DO NOT OPEN.** This is a refusal,
  not a warning.
- `legacy-rme:ops-readiness` reads the shared ENT-12 / MON-1 backup signal. It
  deliberately does **not** re-probe the backup directory — a second
  implementation of "is the backup fresh?" would be free to disagree with the
  first.

Retention, ownership and restore authority are governed by
`docs/runbooks/backup-dr-restore-rehearsal-runbook.md`. This runbook does not
restate them.

---

## 6. Running the batch

### Maker — per document

1. Open the original PDF and **read the Nomor RM printed on it**.
2. Enter that source RM **independently**.
3. Select the patient.
4. Let the server validate the binding.
5. Confirm the dates, then submit.

> **Never select the patient first and copy their RM into the source field.**
> Independent confirmation *is* the safety control. Copying it defeats the check
> entirely and is how a document reaches the wrong chart.

Dates on a multi-date document:

- `selected_rme_date` = **earliest** human-confirmed encounter date
- `latest_rme_date` = **latest** human-confirmed encounter date

Both are read from the document by a human. OCR is never the authority.

### Checker — before accepting

Verify, every time: source document · immutable source identity (SHA-256) ·
source RM · bound patient · branch · selected and latest dates · native cutoff
status · maker identity.

### Publisher

Must be authorized, must be a **different account** from the importer, and must
satisfy human separation of duties. The publish service revalidates everything —
being reviewed already is not a shortcut.

### Monitoring while it runs

```bash
php artisan legacy-rme:ops-readiness      # is it still safe?
php artisan legacy-rme:migration-status   # counts, quota, queue, reconciliation
php artisan legacy-rme:wave-status        # admitted branches, backlog, headroom
```

---

## 7. Refusals an operator will meet, and the only correct response

| Refusal | Response |
|---|---|
| Source RM **not found** | **STOP.** Do not pick a similar patient. Do not change a digit. Send to master-data investigation. No override exists. |
| Source RM **patient mismatch** | Re-check the RM you typed, the patient you chose, and the document. Do not continue until identity is exact. |
| Source RM **ambiguous** | STOP. Master-data investigation. |
| **Invalid / unknown / inactive / ambiguous branch** | STOP. There is no manual branch override. The branch is derived from the RM and that derivation is the authority. |
| **Duplicate document** (same SHA-256) | Already migrated or already staged. **Never rename a PDF to get past duplicate detection.** |
| **Native cutoff conflict** | Do not override. Do not fabricate a native visit. Escalate through clinical/master-data governance. |
| **Quota exhausted** | The rail is working. Wait for the next window or seek elevated approval. |
| **Pipeline saturated** | Backpressure is protecting the worker. Let it drain; investigate a stalled worker. |

**RM 27541** remains `DOCUMENT_NOT_ELIGIBLE` / `FUTURE_MIGRATION=NOT_ELIGIBLE`.
It is the canonical example of *not found → stop → master-data → no override*.
Do not retry it during routine operation and **do not bind it to patient 43**.

---

## 8. Stop-the-line

These mean the migration's own evidence can no longer be trusted. **Pause
admission immediately and preserve evidence.**

| Code | Meaning |
|---|---|
| `QUOTA_LEDGER_DRIFT` | The counter disagrees with the rows accepted. |
| `UNEXPLAINED_RECORDS` | A document is in no known bucket. |
| `SEPARATE_PUBLISHER_DISABLED` | An account could certify its own upload. |
| `ADMITTED_BRANCH_NOT_APPROVED` | A branch is open that no approval covers. |
| `BATCH_BINDING_MISMATCH` | The declared batch and its record disagree. |
| `CLINICAL_TIMEZONE_WRONG` | Every date boundary is untrustworthy. |
| `BACKUP_MISSING_OR_STALE` | No restore point. |

Severity vocabulary: `INFO` → normal · `WARNING` → degraded, fix before next
batch · `BLOCKER` → do not open, or pause · `INCIDENT` → a control or the audit
trail is compromised, escalate.

### Incident response

1. **Detect** — from the pre-flight, `migration-status`, or an operator report.
2. **Pause admission.** `legacy-rme:wave-admin pause` (dry-run first).
3. **Preserve evidence.** Change nothing else yet. Do not delete, do not edit.
4. **Identify the affected imports** by id.
5. **Recover through the canonical lifecycle only** —
   `legacy-rme:import-admin {cancel|review|publish|retry}`, dry-run first.
6. **Reconcile** until the books balance.
7. **Assess clinical/business impact.**
8. **Escalate to the owner** with PII-free evidence.
9. **Record** the incident in the batch evidence.

> **Application rollback ≠ data recovery.** Rolling back code does not un-migrate
> a published record, and restoring the database is not the fix for one bad
> import. Use the lifecycle CLI for a document; use rollback for a bad *release*;
> use a restore only for a genuine data-loss event, and never over production
> without owner authorization.

**Never** repair lifecycle state with direct SQL or Tinker. Every transition must
be audited, and a hand-edited row is an unaudited transition.

---

## 9. Pause · resume · drain · cancel · complete

| Action | Meaning | Use when |
|---|---|---|
| **pause** | Stops new admissions; keeps recoverable state | Unexpected errors, queue backlog, wrong source, operator unavailable, security concern, quota drift |
| **resume** | Reopens admission | Blocker resolved, reconciliation clean, assignments valid, approval still in window. **Never resume blind.** |
| **drain** | Stops new admissions, lets in-flight work finish | Winding down |
| **cancel** | Ends the batch | Abandoning it. Does **not** refund quota, erase audit history or delete source evidence |
| **complete** | Signs the batch off | Only when reconciliation balances. Refuses while work is unfinished — there is no `--force` |

All of these are dry-run by default. Add `--apply` deliberately.

> **A cancelled import still consumes quota.** This is intentional: quota counts
> what was *accepted into staging*, and a cancelled document was accepted. Do not
> read a quota total as a count of published documents.

Retry applies **only** to genuinely retryable failed states — never to a
published, cancelled or void record. Retry does not re-charge quota and never
clones a source document.

---

## 10. Closing a batch

A batch is **not** closed because the last PDF was published.

```bash
php artisan legacy-rme:migration-status   # must balance
php artisan legacy-rme:ops-readiness      # must return to AT_REST
```

Required before closure:

- `IN_FLIGHT = 0`
- `UNEXPLAINED = 0`
- `QUOTA_DRIFT = 0`
- unresolved failures = 0
- a written sign-off note

Then return to the **safe resting state**:

- capability **OFF**
- admission **EMPTY**
- active batch **NONE**

Confirm with `legacy-rme:ops-readiness` → `Resting state: AT_REST`.

Finally, record the batch evidence using
`docs/evidence/legacy-rme/routine-batch-evidence-template.md`.

---

## 11. Never

- Never run `migrate:fresh` or `db:wipe` on the VPS.
- Never repair lifecycle state with direct SQL or Tinker.
- Never share a maker/checker account between two people. One accountable human,
  one attributable account. Two humans behind one account cannot be attributed;
  one human holding two accounts satisfies the server and still violates
  governance.
- Never override a source-RM refusal.
- Never rename a file to defeat duplicate detection.
- Never reuse a historical approval.
- Never open a batch without a verified backup.
- Never fabricate a native visit to clear a cutoff conflict.
- Never treat a routine batch as grounds for a GO tag.

---

## 12. Roles

Determined from the shipped permission set — these are the real names.

| Action | `create_` | `review_` | `publish_` | `manage_…operations` | `approve_…wave` |
|---|---|---|---|---|---|
| Upload a document | ✅ | | | | |
| Review / certify | | ✅ | | | |
| Publish | | | ✅ | | |
| Register / pause / drain / complete a batch | | | | ✅ | |
| Approve a batch | | | | | ✅ |
| Read the pre-flight | \* | \* | \* | ✅ | ✅ |

\* Reading `view_legacy_rme_migration_operations` is what gates the operations
dashboard; the CLI pre-flight is run by whoever operates the server.

**Incompatible duties:** the importer of a document can never review or publish
*that* document — enforced server-side, with no Super Admin exemption. The
separate-**approver** rule ships OFF by documented decision (only one staffed
governance account exists) and is reported as a WARNING, not a refusal.

---

## 13. Review cadence

- Re-read §1 sizing once a real batch yields measured timings, and replace the
  bounded defaults with evidence.
- Turn on the separate-approver requirement as soon as a second staffed
  governance account exists.
- Owner: migration supervisor. Review each quarter, or after any incident.
