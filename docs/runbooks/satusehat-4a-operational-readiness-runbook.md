# Runbook — SATUSEHAT-4A Operational Readiness & Data Quality

Credential-independent operations for the SATUSEHAT readiness workspace.
**External submission stays DISABLED; SATUSEHAT-2 stays WATCH; production stays
blocked.** Nothing in this runbook opens a network connection to SATUSEHAT.

## 1. Purpose & when to use

- Menilai kesiapan data internal per cabang sebelum kampanye kredensial.
- Menjalankan remediasi kualitas data (pasien, diagnosis, mapping).
- Menjalankan rehearsal sintetis end-to-end tanpa kredensial.

## 2. SOP — daily/weekly operational loop

1. Buka **SATUSEHAT → Kesiapan Data** (`/rme/satusehat/readiness`).
2. Jalankan **Kalkulasi Ulang (Terbatas)** (atau CLI
   `php artisan satusehat:data-quality-scan --apply`).
3. Triase **Isu Kualitas Data**: filter per kategori/severity/pemilik.
4. Remediasi sesuai pemilik (RACI di bawah); isu selesai HANYA lewat
   *Validasi Selesai* (revalidasi server oleh rule engine).
5. Review klinis untuk mapping diagnosis/tindakan (lifecycle mapping tetap
   draft → review → verify → active).
6. Pantau ringkasan checklist onboarding — item eksternal jujur
   `blocked_external` sampai kredensial resmi ada.

## 3. RACI

| Aktivitas | R | A | C | I |
| --- | --- | --- | --- | --- |
| Remediasi data pasien (nama/TTL/gender/RM) | Admin Klinik | Supervisor RME | — | Owner |
| Entry diagnosis terstruktur | Doctor | Supervisor RME | Clinical Reviewer | — |
| Review klinis mapping (Condition/Procedure/dental) | Clinical Reviewer (Supervisor RME) | Supervisor RME | Doctor | Owner |
| Entry teknis mapping/identifier | Supervisor RME | Supervisor RME | IT Operator | — |
| Review kandidat (approve/exclude) | Supervisor RME | Supervisor RME | — | Owner |
| Assignment isu + waiver | Supervisor RME | Supervisor RME | Admin Klinik | Owner |
| Rehearsal sintetis | IT Operator | Supervisor RME | — | Owner |
| Instalasi kredensial + live sandbox closure | IT Operator | Owner/Management | Supervisor RME | Semua |
| Aktivasi produksi | — (BLOCKED — kampanye terpisah) | Owner/Management | — | — |
| Incident response | IT Operator | Super Admin | Supervisor RME | Owner |

Catatan: Doctor & Kasir TIDAK pernah memegang permission outbound send.

## 4. Diagnostic commands (read-only defaults, no network, no PII)

```bash
php artisan satusehat:diagnose --json
php artisan satusehat:readiness-audit [--branch=] [--strict] [--json]
php artisan satusehat:data-quality-scan [--branch=] [--from=] [--to=] [--limit=] [--apply] [--strict]
php artisan satusehat:queue-health [--strict]
php artisan satusehat:reconciliation-status [--strict]
php artisan satusehat:production-guard-check          # SATUSEHAT-3, must stay blocked
```

## 5. Synthetic rehearsal window (safe on VPS)

```bash
php artisan satusehat:synthetic-pilot seed --confirm
php artisan satusehat:rehearse --synthetic --dry-run
# expected final state: BLOCKED_EXTERNAL_CREDENTIAL (internal pipeline clean)
php artisan satusehat:synthetic-pilot verify
php artisan satusehat:synthetic-pilot reset --confirm
```

Guarantees: isolated `SYN4A` branch, synthetic markers, no real patient/NIK,
no fabricated IHS, reset removes only campaign records.

## 6. Incident & failure drills (symptom → action)

| # | Symptom | Diagnostic | Expected state | Operator action | Escalation | Rollback/evidence |
| - | --- | --- | --- | --- | --- | --- |
| 1 | Missing credentials | `satusehat:diagnose` | `*_present=false`, gateway Disabled | None — by design (WATCH) | Owner for campaign | n/a |
| 2 | Wrong environment | `satusehat:diagnose` | `environment=sandbox` | Fix env var, `config:cache` | IT Operator | config only |
| 3 | Production flag attempt | `satusehat:production-guard-check` | blocked, 8 blockers | Revert flags immediately | Super Admin | audit log |
| 4/5 | Missing Organization/Location placeholder | Dashboard org/location cards | `awaiting_external_identifier` | None (external) / assign room if `location_missing` | Supervisor RME | n/a |
| 6/7 | Missing Patient/Practitioner identifier | Dashboard + issues (info/external) | BLOCKED_EXTERNAL_CREDENTIAL | None — external | Owner | n/a |
| 8/9 | Invalid diagnosis/treatment mapping | Issues `diagnosis_mapping`/`treatment_mapping` | soft issues open | Clinical review → activate mapping | Clinical Reviewer | mapping versioned |
| 10 | Dental incomplete | Issue `dental_completeness` | soft/info | Doctor completes odontogram | Supervisor RME | n/a |
| 11 | Source drift | Issue `source_drift` (HARD) | approval revoked | Re-review + re-approve candidate | Supervisor RME | audit `approval_revoked` |
| 12 | Queue worker stopped | `satusehat:queue-health` + `systemctl status daengtisiams-queue-worker` | pending grows | Restart worker (worker runbook) | IT Operator | ENT-5 runbook |
| 13 | Redis/cache unavailable | `foundation:monitoring-observability-check` | cache degraded | MON-1 runbook | IT Operator | MON-1 |
| 14 | Duplicate preparation | `satusehat:reconciliation-status` | idempotency_key UNIQUE blocks dupes | Cancel stray batch | Supervisor RME | batch audit |
| 15 | Stale lock | items `locked_at` old | n/a while disabled | Wait lock TTL / reconcile | IT Operator | audit |
| 16 | Payload conformance failure | Issue `local_conformance` (HARD) | candidate `dental_conformance_failed` | Fix source odontogram data | Supervisor RME | never waivable |
| 17 | Kill switch active | `satusehat:diagnose` `send_enabled=false` | expected default | None | — | n/a |
| 18 | Partial local batch | `satusehat:reconciliation-status` | batch `prepared`, no sends | Cancel or keep local | Supervisor RME | audit |
| 19 | Unauthorized remediation attempt | 403 + `sys_audit`/satusehat audit | denied | Verify RBAC, report | Super Admin | audit log |
| 20 | Branch IDOR attempt | 404 on foreign-branch issue | denied (fail-closed) | Report | Super Admin | audit log |

## 7. Rollback (non-destructive)

- Code: `scripts/rollback-vps.sh <previous-go-tag>` (ENT-11) — no data restore.
- Additive tables/diagnosis rows/issues/audit are KEPT (never dropped).
- Synthetic campaign: `satusehat:synthetic-pilot reset --confirm` only.
- External integration remains disabled in every rollback state.
