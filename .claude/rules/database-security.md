---
paths:
  - "database/migrations/**"
  - "config/database.php"
  - "scripts/deploy-vps.sh"
  - "scripts/backup-vps.sh"
  - "scripts/backup_postgres.sh"
  - "app/Modules/LabOrder/Models/AuditLog.php"
---

# Database security, privilege separation & audit protection — domain rules

Loaded automatically when you open a file under the paths above. Global rules CG-01…CG-24 live in `CLAUDE.md` and are
not repeated here. Authority: owner decisions > canonical `.cursor/rules/` > this file > archive; code is evidence of
current behaviour. A mismatch is implementation drift: report it.

Status: rules 245 and 248 are DESIGN LOCKED (owner, 2026-11-20) and NOT IMPLEMENTED; production privileges are NOT
VERIFIED (Gate 1 R6-01, R6-02). Architecture: `docs/architecture/database-least-privilege-r6.md`.

## Invariants

- **AIR-DBS-01** Today one database identity migrates and serves runtime; production privileges are NOT VERIFIED. This
  is drift against rule 245; never claim R6 clearance from repository evidence. Source: rule 245.
- **AIR-DBS-02** Target roles: `dms_owner` (NOLOGIN, owns everything), `dms_migrator` (explicit SET ROLE dms_owner,
  INHERIT FALSE), `dms_runtime` (owns nothing; no CREATE, TRUNCATE or TRIGGER; not a member of the owner),
  `dms_readonly`. Source: rule 245.
- **AIR-DBS-03** Every migration that creates a table declares its runtime class; a table the runtime row-locks keeps
  UPDATE on one column, trigger-guarded when append-only. Source: rule 245.
- **AIR-DBS-04** Role, membership and ownership changes are provisioning, never a Laravel migration (CG-09). Source:
  rule 245.
- **AIR-DBS-05** SECURITY DEFINER only with owner `dms_owner`, `SET search_path = pg_catalog, pg_temp`, qualified
  names, EXECUTE revoked from PUBLIC. Source: rule 245.
- **AIR-DBS-06** Audit, financial and approval event tables and `trx_lab_order_status_logs` are append-only at three
  layers; never disable such a trigger outside a test-only owner connection. Source: rule 248.
- **AIR-DBS-07** Money CHECKs are added NOT VALID and validated only after an owner-run data audit; unclassified
  historical rows are exempt by an explicit predicate. Source: rule 246.
- **AIR-DBS-08** A CHECK never validates another table: approvals, exceptions, branch scope and cross-row totals are
  service rules under the lock plus deferred constraint triggers owned by the object owner. Source: rules 246, 247.
- **AIR-DBS-09** The pre-deploy dump stays mandatory and is not BK-2; a backup identity change is deferred (A-18) and
  happens only after a rehearsal restore of its dump; the BK-2 package builder's identity is verified before any role
  change. Source: rules 232, 245.

## Canonical rules — read the relevant one before changing this area

| Rule | Scope |
|---|---|
| `.cursor/rules/245-database-least-privilege.mdc` | R6 roles, runtime table classes, row-lock privilege, provisioning, proof |
| `.cursor/rules/248-audit-trail-append-only.mdc` | Audit, event and LAB-evidence tables append-only at three layers |
| `.cursor/rules/246-rme-lab-fee-approval-and-billing-integrity.mdc` | Money CHECKs and invariants (database part) |
| `.cursor/rules/176-private-database-dump-creation.mdc` | Dumps private from creation |
| `.cursor/rules/232-bk2-manual-only-not-a-deploy-gate.mdc` | BK-2 manual-only; timers unchanged |

## History

- 2026-11-20 — created by RME-LAB-FEE-APPROVAL-1 (`docs/sprints/rme-lab-fee-approval-1.md`), owner decisions A-15 and
  A-21.
