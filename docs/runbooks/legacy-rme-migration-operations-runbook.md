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
