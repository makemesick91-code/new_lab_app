# ROLL-5 — Controlled 5-Branch Rollout Runbook

Part of **ROLL-5-1 — Five Branch Controlled Production Rollout Readiness**.

This runbook governs the **staged, controlled** rollout of DaengtisiaMS to five
clinic branches. It **certifies controlled 5-branch rollout only** — NOT national
scale, HA cluster, external penetration test, or full DR certification.

## Rollout stage plan

```text
Stage 1: 1 pilot branch
Stage 2: +2 branches   (3 total)
Stage 3: +2 branches   (5 total)
```

Each stage is entered **only** after the previous stage is stable and the
ROLL-5 readiness command returns an acceptable decision for that stage.

---

## Go / No-Go checklist (run before EACH stage)

```bash
# 1. Consolidated rollout readiness for the target stage
php artisan rollout:five-branch-readiness --include-audits --strict --stage=1   # (or 2 / 3)

# 2. Existing foundation gates (authoritative sources — not duplicated by ROLL-5)
php artisan foundation:monitoring-observability-check --include-audits --strict
php artisan inventory:procurement-workflow-audit --strict
php artisan rme:doctor-performance-access-audit --strict
php artisan foundation:ci-runtime-control-check --strict
php artisan foundation:security-compliance-check
php artisan foundation:roadmap-check --strict
```

- **GO** → proceed with the stage.
- **WATCH** → resolve every WATCH reason (branch data, backup, restore drill, roles) first; WATCH does not auto-block but must be cleared before onboarding branches.
- **FAIL** → **do not proceed.** A FAIL is an unsafe state (debug-on in prod, health down, storage not writable, role-permission leak, capacity query broken, or a failing audit).

Branch-count readiness per stage is derived from **RME-enabled active branches**
(`BranchService::listRmeEnabled()`), never from a request value.

## Daily monitoring checklist (during rollout)

```bash
php artisan foundation:monitoring-observability-check --include-audits          # MON-1 consolidation
php artisan rollout:five-branch-readiness --stage=<current>                     # rollout posture
php artisan inventory:procurement-workflow-audit --strict                       # procurement integrity
php artisan rme:doctor-performance-access-audit --strict                        # doctor-report access
tail -n 200 storage/logs/laravel.log                                            # new errors (masked in UI)
curl -s https://<host>/health/live ; curl -s https://<host>/health/ready        # health endpoints
```

Also review the read-only UIs (Super Admin only, `view_developer_console`):
`/foundation/monitoring` and `/foundation/rollout/five-branch-readiness`.

## Backup / restore drill process

See `docs/runbooks/roll-5-backup-restore-drill-runbook.md`. Key rule: restore
drills run **only** against a staging/test scratch DB — never production.

## Rollback SOP

1. Confirm the target GO baseline (previous stage's GO tag / commit).
2. Take a fresh backup (`bash scripts/backup-vps.sh`).
3. `bash scripts/rollback-vps.sh <tag-or-commit>` (ENT-11 — checkout + rebuild + re-verify gates + smoke; **no automatic data restore**).
4. If data restore is genuinely required, use the explicit `scripts/restore_postgres.sh <backup>` step (human-run, deliberate).
5. Re-run the Go/No-Go checklist and confirm health + smoke.

## Incident response SOP

1. Detect via MON-1 / rollout readiness FAIL, health endpoint 503, or new Laravel errors.
2. Assess scope (single branch vs all branches; data vs availability).
3. If unsafe, **hold the rollout** (do not advance stages).
4. Mitigate: rollback (above), or fix-forward with a hotfix branch off the base branch (never `main`).
5. Preserve evidence; never expose KTP/NIK/patient notes in incident notes.
6. Post-incident: re-run all Go/No-Go gates before resuming the rollout.

## User support SOP (by role)

| Role | Primary surfaces | Common first check |
|---|---|---|
| **Admin Klinik** | Antrian pasien, kunjungan, input ruangan | Room gate: patient must be placed in a room before doctor exam. |
| **Doctor** | RME (rekam medis, odontogram), Kinerja Dokter | Own-data only; unlinked doctor → link user↔`mst_doctors`. |
| **Kasir** | Kasir/pembayaran, piutang, kwitansi | Consent + payment gates; full-payment / partial rules. |
| **Kepala Cabang** | Alur PR cabang (PR only) | Cannot create PO by design (server-side `PurchaseOrderPolicy::create`). |
| **Admin Warehouse** | PR → PO → GR, transfer, opname | GR default batch expands per-product (never one global batch). |

## Permission audit SOP

```bash
php artisan inventory:procurement-workflow-audit --strict     # Kepala Cabang PO-leak = FAIL
php artisan rme:doctor-performance-access-audit --strict       # doctor-report access anomalies
php artisan rollout:five-branch-readiness --json | jq '.signals[] | select(.key=="role_permission_readiness")'
```

Missing role → WATCH (seed via RoleSeeder). Role-permission leak → FAIL (revoke).

## Capacity smoke SOP

```bash
php artisan rollout:five-branch-readiness --capacity-smoke --json \
  | jq '.signals[] | select(.key=="capacity_smoke")'
```

This is a **lightweight capacity smoke** (bounded, read-only COUNT probes on
high-traffic tables), not a national-scale load test. A slow probe → WATCH; a
broken/timed-out query → FAIL. For real scale projection see ENT-13/ENT-14.

## Known non-goals

- ❌ Not national scale (5 branches, controlled).
- ❌ Not HA cluster validation.
- ❌ Not external penetration test.
- ❌ Not full DR certification.

National-scale readiness requires a **separate future scale-validation sprint**.
