# Legacy RME Migration Operations Runbook (ROLL-4)

Operating a wave-based Legacy RME migration: plan, approve, admit, assign, run,
monitor, pause, drain, reconcile, sign off.

Companion to `docs/runbooks/legacy-rme-pdf-rollout-runbook.md` (ROLL-1/2/3 —
enabling, admitting and rolling back the capability itself). Read that one first;
this one assumes the capability side is already understood.

**Every command below is read-only unless it carries `--apply`.**

---

## 0. Safe resting state

```
capability          OFF   (FEATURE_RME_LEGACY_PDF_ARCHIVE=false)
admitted branches   EMPTY (LEGACY_RME_ADMITTED_BRANCH_CODES=)
published read      AVAILABLE to authorized clinical readers
```

This is where a deployment sits between waves, and where ROLL-4 closure returns
it. `legacy-rme:migration-status` reports `Findings: none` in this state — no
wave is required when nothing is admitted.

---

## 1. Plan a wave

Decide, with the owner:

- exact branch set (branch CODES, e.g. `ATG3,LDK2`)
- a non-PHI approval reference (ticket or decision id — never a patient identifier)
- daily quota, wave-wide and/or per branch
- who the intake operators are

The owner's approval must name the **exact** branch set. A previous wave's
approval never authorizes a new one.

---

## 1a. Candidate and operator preflight — BEFORE recording the approval

A wave that is opened before anyone confirmed it has documents to migrate, or an
operator allowed to migrate them, opens a write capability on live clinical data
for nothing. Both checks are cheap, read-only, and must pass first.

**Do the documents exist?** An approved branch with no un-migrated document is
not a wave — it is WATCH.

```bash
# Every PDF on the host. Anything already under the private legacy store is
# ALREADY MIGRATED and is not a candidate.
find / -xdev -iname '*.pdf' -not -path '*/vendor/*' -not -path '*/node_modules/*' \
  -not -path '/proc/*' -not -path '/sys/*' -not -path '/usr/*' -not -path '/snap/*'

# Nothing already staged/pending for that branch?
php artisan legacy-rme:migration-status --json
```

If object storage is enabled, check it too; `storage:object-readiness-check`
reporting `disabled_ready` means there is no remote candidate source.

**Do the operators exist?** ROLL-4 separates the approver from the operator on
purpose. Confirm, as *different* accounts:

- someone holds `create_legacy_rme_imports` (the intake operator), and
- someone else holds `approve_legacy_rme_migration_wave` (the approver), and
- someone holds `manage_legacy_rme_migration_operations` (wave governance).

A deployment where the approver/governance permissions are held by **no role**
cannot satisfy separation of duties. Super Admin's global `Gate::before` bypass
lets one account do everything, which is exactly the concentration this split
exists to prevent — it is not a substitute for provisioning the operators.

Prefer a least-privileged branch-pinned operator (`users.branch_id` set to the
wave's branch) over Super Admin. Grant through the canonical seeder/permission
workflow, never direct SQL, and record the change.

**Only when both checks pass** do you continue to §2 and record the approval.

---

## 2. Record the approval on the server (ROLL-3 — the authority)

```bash
# On the VPS, in the app directory.
LEGACY_RME_WAVE=WAVE-2
LEGACY_RME_ADMISSION_APPROVAL_REFERENCE=ROLL-4-WAVE-2-OWNER-APPROVAL-YYYY-MM-DD
LEGACY_RME_ADMISSION_APPROVED_BRANCH_CODES=ATG3,LDK2
LEGACY_RME_ADMITTED_BRANCH_CODES=            # still empty; opened in step 5
```

Rebuild the config cache after editing (ROLL-1 capture rule — nothing reads the
environment at runtime):

```bash
php artisan config:cache
```

---

## 3. Register the wave (ROLL-4 — the operational mirror)

```bash
php artisan legacy-rme:wave-admin register \
  --wave=WAVE-2 --name="Gelombang 2" --branches=ATG3,LDK2 \
  --daily-quota=40 --per-branch-daily-quota=25 \
  --actor=<owner-user-id-or-email>            # dry run

php artisan legacy-rme:wave-admin register ... --apply
```

The service refuses any branch the deployment's approval does not cover, and any
branch that is not an active RME-enabled branch.

---

## 4. Approve and activate

```bash
php artisan legacy-rme:wave-admin approve  --wave=WAVE-2 --actor=<approver> --apply
php artisan legacy-rme:wave-admin activate --wave=WAVE-2 --actor=<operator> --apply
```

`approve` requires `approve_legacy_rme_migration_wave`, deliberately separate from
`manage_legacy_rme_migration_operations`. With
`LEGACY_RME_REQUIRE_SEPARATE_APPROVER=true` the approver must differ from the
creator.

Activation moves every `PLANNED` branch to `ACTIVE`.

---

## 5. Assign operators, then open admission

```bash
php artisan legacy-rme:wave-admin assign \
  --wave=WAVE-2 --branch=ATG3 --operator=<user> --actor=<operator> --apply
```

An operator needs `create_legacy_rme_imports` **and** an assignment for that
branch. Assigning someone who lacks the permission is refused.

Only now open ROLL-3 admission:

```bash
LEGACY_RME_ADMITTED_BRANCH_CODES=ATG3,LDK2
php artisan config:cache
```

Verify before anyone uploads:

```bash
php artisan legacy-rme:rollout-readiness --expect=on --strict
php artisan legacy-rme:migration-status --strict
```

Both must be GO / no findings. `migration_operations_layer` FAILs if the wave is
missing or disagrees with the approval.

---

## 6. Monitor

```bash
php artisan legacy-rme:migration-status                 # human
php artisan legacy-rme:migration-status --json          # evidence capture
php artisan legacy-rme:migration-status --strict        # deploy/CI gate
```

UI: **Master Data → Master Data RME → Operasi Migrasi Arsip RME Lama**.

`--strict` exits non-zero on: `WAVE_NOT_REGISTERED`, `WAVE_BINDING_MISMATCH`,
`ADMITTED_WITHOUT_APPROVAL`, `PIPELINE_SATURATED`, `UNEXPLAINED_RECORDS`,
`QUOTA_LEDGER_DRIFT`, `STALE_PROCESSING`, `REVIEW_BACKLOG`,
`OPERATIONS_NOT_ENFORCED`.

**Reading the panel honestly:**

- `pending_jobs: not measurable` means the probe could not run. It does **not**
  mean zero.
- `completion_percent: null` means nobody counted the archive. Set
  `--planned-documents` if a real count exists; never invent one.
- A growing review backlog with a healthy queue is a **people** bottleneck —
  add reviewers or lower the quota, not worker capacity.

---

## 7. Pause / resume

```bash
php artisan legacy-rme:wave-admin pause  --wave=WAVE-2 --actor=<op> \
  --reason="Menjeda untuk verifikasi kualitas pemindaian." --apply

php artisan legacy-rme:wave-admin resume --wave=WAVE-2 --actor=<op> --apply
```

Per branch: `branch-pause` / `branch-resume` with `--branch=ATG3`.

Pause stops **new** intake. Everything already accepted keeps its lifecycle and
may still be published. Resume re-verifies the approval binding — a pause is
exactly when someone changes the deployment's approval.

---

## 8. Drain (winding down)

```bash
php artisan legacy-rme:wave-admin branch-drain --wave=WAVE-2 --branch=ATG3 \
  --actor=<op> --reason="Seluruh dokumen cabang sudah diunggah." --apply

php artisan legacy-rme:wave-admin drain --wave=WAVE-2 --actor=<op> \
  --reason="Mengakhiri gelombang migrasi." --apply
```

Same runtime effect as a pause, but not resumable — it is the path to completion.
Publish still runs every canonical revalidation.

---

## 9. Handle failures

Failed renders appear as `failed_unresolved`. For each one, either:

- retry it (`settings.rme.legacy-imports.retry`) — allowed while the wave and
  branch are ACTIVE, and **not** charged against quota again; or
- cancel it (`…legacy-imports.cancel`) with a reason.

A branch cannot be completed while any failure is unresolved.

**Stale PROCESSING** rows are reported, never auto-repaired. Investigate the
worker first:

```bash
systemctl status daengtisiams-queue-worker.service
php artisan queue:failed
```

---

## 10. QA sample

The wave detail page lists a deterministic sample of published archives with
structural facts only: record id, branch, declared date range, page count, and
whether the private source file is still present.

For each sampled record also verify, with a real authorized identity:

- it appears in the patient's clinical history;
- the treating doctor can open the viewer;
- a non-treating doctor cannot.

This is a completeness check, not a clinical review. **No patient identity is
rendered anywhere in this panel.**

---

## 11. Reconcile and complete a branch

```bash
php artisan legacy-rme:migration-status --wave=WAVE-2 --json
```

Required before sign-off: `in_flight = 0`, `failed_unresolved = 0`,
`unexplained = 0`, `quota_drift = 0`, branch status `DRAINING`.

```bash
php artisan legacy-rme:wave-admin branch-complete --wave=WAVE-2 --branch=ATG3 \
  --actor=<op> --reason="Seluruh dokumen cabang telah diverifikasi dan diterbitkan." --apply
```

The reconciliation is recomputed under a row lock and frozen onto the branch row.
An empty queue is never sufficient evidence.

---

## 12. Complete the wave

```bash
php artisan legacy-rme:wave-admin complete --wave=WAVE-2 --actor=<op> \
  --reason="Gelombang migrasi ditutup." --apply
```

Every enrolled branch must be `COMPLETED` or explicitly `CANCELLED` first.

---

## 13. Close the wave out (return to the safe resting state)

```bash
LEGACY_RME_ADMITTED_BRANCH_CODES=
php artisan config:cache
php artisan legacy-rme:migration-status
```

Optionally switch the capability off as well. Published archives stay readable to
authorized clinical readers either way.

---

## 14. Emergency stop

```bash
FEATURE_RME_LEGACY_PDF_ARCHIVE=false
php artisan config:cache
```

Withdraws **all** legacy mutations including publish; the operator surface 404s.
The admission decision reports `FEATURE_DISABLED`, so an operator can tell an
incident stop from a routine rollback.

**It does not remove authorized PUBLISHED clinical read**, and it never deletes a
source PDF, a rendered page, a published record, a VOID record or audit evidence.

Draining a branch is **not** an incident-containment mechanism and must never be
used as one.

---

## 15. Never

- Reuse a historical approval for a new wave.
- Admit a branch the owner did not approve for **this** wave.
- Mark a branch complete on an empty queue or `failed_jobs = 0`.
- Hard-delete PUBLISHED or VOID evidence.
- Edit a published record in place (correction is VOID + fresh import).
- Run `migrate:fresh` or `db:wipe` on the VPS.
- Treat the dashboard as an authorization boundary — it is presentation.
- **Move a staged import through its lifecycle with SQL or Tinker.** Use §16.

---

## 16. Abort recovery — the canonical lifecycle CLI (OPS-CLI-1)

Wave-2 was opened and aborted, and the operator could not withdraw the staged
documents over SSH. `legacy-rme:import-admin` closes that gap. It is an **adapter
over the same services the browser uses** — same capability flag, same branch
scope, same permissions, same policy, same transition map, same quota semantics,
same audit trail. It is not an override and holds no privileges of its own.

```bash
php artisan legacy-rme:import-admin {cancel|review|publish|retry} \
    --import=<id> --actor=<id|email> [--apply] [--json]
```

### Always dry-run first

Without `--apply` nothing is written — no row, no audit entry, no job. Read the
`blockers` array; it reports **every** obstacle it can see, not just the first.

```bash
# What WOULD happen? Nothing is touched.
php artisan legacy-rme:import-admin cancel --import=87 --actor=u7@example --json

# Then, deliberately:
php artisan legacy-rme:import-admin cancel --import=87 --actor=u7@example --apply --json
```

### Reading a refusal

| `refusal_code` | What to fix |
|---|---|
| `FEATURE_DISABLED` | The capability is OFF (§14). Recovery does not bypass the emergency stop. |
| `IMPORT_NOT_IN_SCOPE` | Wrong id, **or** the import belongs to a branch this account cannot see. Deliberately indistinguishable. |
| `PERMISSION_DENIED` | The account lacks the named permission the HTTP route also requires. |
| `POLICY_DENIED` | Permission held, but branch scope or the transition gate refused. |
| `TRANSITION_NOT_ALLOWED` | The import's status cannot reach this action's target. Dry-run only. |
| `SEPARATION_OF_DUTIES` | The uploader may not publish their own document. Find the checker. |
| `SERVICE_REFUSED` | The canonical service declined: wrong state, missing source PDF, unusable pages, or a date that no longer holds. |
| `ACTOR_REQUIRED` / `ACTOR_NOT_FOUND` / `ACTOR_INACTIVE` | Missing, unknown, deactivated or deleted `--actor`. Checked **before** anything is resolved. |
| `UNKNOWN_ACTION` / `IMPORT_REQUIRED` | The action word is not one of the four, or `--import` is missing/not a positive integer. |
| `INVALID_ARCHIVE_LABEL` | `--title` > 150 or `--description` > 2000 — the same bounds the browser enforces. |

Exit code is `0` only when the requested outcome was reached (or a dry run found
the action eligible). **Every refusal exits non-zero**, so a wrapper script cannot
mistake one for success.

### Rules that hold on the command line exactly as in the browser

- **One import per invocation.** There is no `--all`, `--branch`, `--wave` or
  `--force`. Loop in your own shell if you mean to; each iteration is separately
  authorized and separately audited.
- **`--actor` is mandatory and must be a real, active account.** The Linux user,
  the SSH login and `root` are never an application identity.
- **Cancelling does NOT hand a quota slot back.** The bucket is a reservation
  spent at intake. Never loop `upload → cancel → upload` to get past a ceiling.
- **Review cannot be skipped**, including by a Super Admin — the canonical publish
  service refuses regardless of the `Gate::before` bypass.
- **Publishing twice creates one record, not two**, and reports `changed: false`.
- **Retry means requeue.** `PUBLISHED` and `CANCELLED` are terminal.
- **Nothing downstream is ever created** — no visit, medical record, odontogram,
  invoice, payment, lab order or SATUSEHAT candidate.

Every mutation lands in `sys_audit_logs` attributed to `--actor` and tagged
`channel = CLI`, so an SSH recovery is distinguishable from a browser action.

### Forbidden alternatives

```
UPDATE stg_rme_legacy_imports SET status = ...        -- never
php artisan tinker → ->update(['status' => ...])       -- never
editing uploaded_by / published_by by hand             -- never
adjusting a quota bucket by hand                       -- never
```

---

## 8. Two things Wave-1 (2026-08-14) proved this runbook was missing

**Turning the capability ON has no step here.** §2–§5 open the approval, the
wave and ROLL-3 admission, and §7 documents turning `FEATURE_RME_LEGACY_PDF_ARCHIVE`
back *off* — but nothing states when it goes *on*. Wave-1 enabled it last, after
the wave was ACTIVE, the operator assigned and admission opened, and verified
with `legacy-rme:rollout-readiness --expect=on --strict` before anyone uploaded.
Do it in that order: a branch must never be admitted with the capability already
open and no wave to govern it.

**Separation of duties is now enforced, so the actor flags are not
interchangeable.** With `LEGACY_RME_REQUIRE_SEPARATE_APPROVER=true`:

```bash
register  --actor=<wave manager>     # holds manage_…_operations
approve   --actor=<checker>          # holds approve_…_wave, and is NOT the creator
activate  --actor=<wave manager>
assign    --actor=<wave manager> --operator=<maker>
```

Passing the creator to `approve` is refused server-side — including for Super
Admin, whose global bypass grants permissions but cannot make one identity into
two. If only one staffed account exists, stop at WATCH rather than reusing it.
