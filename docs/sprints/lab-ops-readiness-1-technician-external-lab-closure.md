# LAB-OPS-READINESS-1 — Lab Workflow V2 Operational Data Readiness Closure

**Technician Account Governance & Active External Lab Enablement**

- Branch: `feature/lab-ops-readiness-1-technician-external-lab-closure`
- Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main)
- Baseline: `lab-workflow-v2-pilot-uat-1-operational-readiness-closure-go` @ `0cdddca` (+ docs `e8877f8`)
- GO tag (on merge, after deploy + strict GO + smoke): `lab-ops-readiness-1-technician-external-lab-closure-go`
- Manifest: `.sprint/current.yml` (MODULE_SPRINT, `schema_change:false`, `security_impact:true`)

## Goal

Close the two operational WATCH items reported by `lab-workflow:pilot-readiness-audit` on the
VPS pilot, driving `--strict` to **GO** (not a WATCH closure):

1. `technician_accounts` — 11 masters, 1 real+eligible (Dila TECH-001 → user 13), 10 faker
   orphans (`orphan_technician_no_user`).
2. `active_external_lab` — 0 active external labs.

## Non-negotiables honoured

- No fabricated technician accounts, vendors, or owner approval.
- No fuzzy/automatic technician↔user linking.
- External lab vendor data is real/owner-approved only.
- All data repair is dry-run first, transactional, audit-logged; **no hard-delete, no soft-delete
  of used masters, no history removal**; `migrate:fresh`/`db:wipe`/global destructive seed forbidden.
- Workflow V2 canonical, legacy create blocked, Admin Lab stays Lab-only.
- Deploy runner runs **inside the VPS**; never locally.
- GO tag only after deploy + owner-approved data repair + internal & external UAT + strict GO + smoke.

## What shipped (no migration — reuses existing columns)

### Tooling

- **`lab:technician-deactivate --technician= --reason= [--dry-run|--apply] [--json]`**
  → `TechnicianAccountAuditor::deactivateMaster()`. Dry-run default; requires a non-empty reason;
  refuses while an assignment is `ASSIGNED`/`IN_PROGRESS`; sets `is_active=false` only (NEVER
  soft/hard-delete, NEVER detaches `user_id`); transactional + `lockForUpdate`; idempotent; audit
  log (`mst_technicians`, `UPDATE`, reason recorded); before/after output. Single master per run
  (no bulk wildcard). Fills the CLI gap — the UI-only `technicians.deactivate` route had no
  reason/active-assignment guard/audit for governed VPS repair.
- **`lab:external-lab-upsert --name= [--phone= --email= --address= --notes= --active=] [--dry-run|--apply] [--json]`**
  → `ExternalLabProvisioningService::upsert()`. Idempotent by unique `name` (case-insensitive);
  explicit field allowlist (no arbitrary JSON/SQL, no mass assignment); dry-run default;
  transactional + `lockForUpdate`; audit log; **never deletes** (a soft-deleted same-name vendor is
  restored, not duplicated); can activate/deactivate. Fills the edit/deactivate gap left by the
  index+store-only External Lab UI.

### Auditor metadata (additive, non-weakening)

- `TechnicianAccountAuditor::audit()` summary gains `active_orphan_count` +
  `inactive_technician_count`. Every orphan/anomaly check remains gated on `is_active`, so a
  legitimately deactivated master is **not** an anomaly (the documented "10 inactive legacy masters
  must not be WATCH" rule) — decision stays WATCH/NO-GO only for *active* gaps.
- `LabWorkflowPilotReadinessAuditor`: `active_external_lab` value surfaces `active_labs[]`
  (id+name — operational label, not PII) so evidence shows which real vendor closed it;
  `technician_accounts` value surfaces `active_orphans`/`inactive`.

## Closure logic

- Deactivating the 10 faker orphans (owner-approved) → no active orphans → `technician_accounts`
  auditor GO with Dila as the 1 eligible technician (`eligible_technician` unchanged).
- Enabling one real/owner-approved active external lab → `active_external_lab` GO.
- No auditor gate was weakened; GO is reached by fixing data, not relaxing rules.

## Durable rules

See `.cursor/rules/79-lab-ops-readiness-technician-external-lab.mdc` and the CLAUDE.md entry.

## Tests

- `tests/Feature/LabWorkflow/LabTechnicianDeactivateTest.php` (12)
- `tests/Feature/LabWorkflow/LabExternalLabUpsertTest.php` (12)
- Regression: `tests/Feature/LabWorkflow` + `tests/Feature/AccessControl` (248 passed / 8 GD-skipped).

## Deploy / data repair (post-merge, inside VPS)

1. `ssh daengtisiams-vps` → `cd /var/www/asia-dental-lab-v2` → `bash scripts/deploy-vps-runner.sh run`.
2. Backup taken by runner; `Nothing to migrate.` expected (no migration).
3. Dry-run then `--apply` deactivate the 10 owner-approved orphan technician ids.
4. Dry-run then `--apply` upsert the owner-approved active external lab vendor.
5. Internal technician UAT + external dispatch functional UAT (accept + reject/resend).
6. `lab:technician-account-audit --strict` GO and `lab-workflow:pilot-readiness-audit --strict` GO.
7. Authenticated-equivalent smoke + log review, then GO tag on the merge commit.
