# ENT-12 — Backup & Disaster Recovery Automation

Branch: `feature/ent-12-backup-disaster-recovery-automation`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Scope source: `config/foundation_roadmap.php` ENT-12 (category `backup_dr`, depends ENT-11 + NSF-10).
GO tag: `ent-12-backup-disaster-recovery-automation-go`.

## Objective (from roadmap)

Automate DB + storage backup with retention, periodic restore rehearsal with
evidence, and RTO/RPO targets. Restore rehearsal never targets the production
database; evidence records path + size.

## What shipped (implementation-heavy)

Runtime automation + read-only governance, built on NSF-10 backup-verify and
ENT-11 deploy/rollback:

- `scripts/backup-vps.sh` — fail-fast automated DB backup (`pg_dump` → verify →
  prune by `BACKUP_DR_RETENTION_DAYS`, keeping a minimum floor). No destructive
  DB command.
- `scripts/restore-rehearsal.sh` — non-production restore drill into a scratch
  database guarded to differ from production; verifies restored table count,
  drops only the scratch DB, writes non-sensitive `restore-rehearsal.json`
  evidence (path/size, RTO/RPO). Never touches the pilot data.
- `config/backup_dr.php` — read-only registry (automation files, forbidden
  destructive patterns config-not-code, backup/rehearsal expectations,
  retention, RTO/RPO objectives, evidence artifact, pre-deploy gate).
- `App\Support\Backup\BackupDrScanner` — read-only posture scanner.
- `App\Services\Foundation\BackupDrGovernanceService` — publishes
  **ENT12-BDR001..ENT12-BDR012** into `architecture:foundation-governance-summary`
  as `backup_dr_governance`; re-verifies ENT-5..11 GO. Informational only, not
  wired into the blocking combined decision.
- `php artisan foundation:backup-dr-check` (`--json`, `--strict`,
  `--fail-on-warning`).

## Gate / evidence integration

- `backup-dr-check.json` required in the `ci` + `vps` release-evidence profiles
  (`config/release_evidence.php`, `ReleaseEvidenceService` job map).
- `foundation:backup-dr-check` added to the release-safety pre-deploy gate
  (`config/release_safety.php`) + `backup_script`/`restore_rehearsal_script` in
  `deploy_gate_files`.
- ENT-12 CI-gate registry entry (`config/foundation_governance.php`).
- Wired into `scripts/deploy-vps.sh` + `scripts/ci/foundation-evidence-gates.sh`
  + `.github/workflows/foundation-evidence-gates.yml` after the ENT-11 gate,
  preserving the ENT-8 cache-order hardening.

## Roadmap

- ENT-11 gains `deploy_evidence_commit` `aa14d9d`.
- ENT-12 → `completed` with `governance_section` `backup_dr_governance`,
  `readiness_command` `foundation:backup-dr-check`, `policy_doc`, `go_tag`.
- `next_recommended_sprint` → **ENT-13 — Load Test 5 Cabang Baseline**.

## Preserved foundations

ENT-5 queue-retry, ENT-6 idempotency/outbox, ENT-7 developer console, ENT-8
health-check, ENT-9 security compliance, ENT-10 CI/CD gate, and ENT-11
deploy/rollback governance all remain mandatory and GO; the DR gate re-verifies
them. Full-payment-only, SOAP-hidden, KTP/NIK-masking, and no destructive VPS DB
commands are all unchanged.
