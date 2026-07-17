# SATUSEHAT-4D — Multi-Branch Operational Governance Runbook

Credential-independent. Nothing here enables external submission or production.
SATUSEHAT-2 stays WATCH. All commands are read-only unless a write flag is noted.

## 1. Operating flow

```
Profile all RME-enabled branches
→ rank readiness (PII-free)  →  group branches into a rollout wave
→ approve wave  →  assign remediation targets  →  operate branch boards
→ human operator UAT (see docs/operations/satusehat-4d-uat-kit.md)
→ multi-branch synthetic rehearsal  →  evaluate promotion gates
→ promote / demote / suspend branches  →  record the decision
→ remain blocked only by the external credential (SATUSEHAT-2 campaign)
```

## 2. Diagnostics (read-only, no network)

| Command | Purpose |
|---|---|
| `php artisan satusehat:multi-branch-readiness [--wave=] [--json]` | comparative matrix + summary |
| `php artisan satusehat:wave-status [--json]` | rollout waves + enrolled counts |
| `php artisan satusehat:uat-status [--json]` | UAT runs + sign-off coverage |
| `php artisan satusehat:governance-audit [--strict] [--json]` | GO/WATCH/FAIL safety audit |
| `php artisan satusehat:multi-branch-rehearse --wave=<id> [--confirm] [--json]` | synthetic rehearsal (dry-run default) |
| `php artisan satusehat:branch-readiness`, `satusehat:pilot-status`, `satusehat:issue-aging`, `satusehat:operator-backlog`, `satusehat:production-guard-check` | 4C/4A reused |

`governance-audit`: FAIL (kill switch off) exits non-zero always; WATCH exits non-zero only under `--strict`.

## 3. RACI

Roles: Admin Klinik (AK), Doctor (Dr), Supervisor RME (SR), Clinical Reviewer (CR), IT Operator (IT), Owner/Management (OW). *(Kasir / Admin Lab: no access.)*

| Activity | R | A | C | I |
|---|---|---|---|---|
| Patient remediation | AK | SR | Dr | OW |
| Practitioner readiness | AK | SR | Dr | OW |
| Branch metadata / location readiness | AK | SR | IT | OW |
| Diagnosis adoption | Dr | SR | CR | OW |
| Clinical terminology review | CR | SR | — | OW |
| Wave approval | SR | SR | IT | OW |
| Human operator UAT | IT | SR | AK/Dr/CR | OW |
| Multi-branch rehearsal | IT | SR | — | OW |
| Branch promotion/demotion | SR | SR | IT | OW |
| Branch suspension | SR | SR | IT | OW |
| Change control | SR | SR(≠requester) | IT | OW |
| Incident response | IT | SR | CR | OW |
| Credential installation / sandbox closure / production approval | — | (SATUSEHAT-2 campaign) | — | OW |

Runtime permissions match this RACI (see `.cursor/rules/89-...`). Do not document a
responsibility the permissions do not support.

## 4. Change control

Categories: readiness_threshold, scoring_weight, wave_membership, pilot_status,
branch_suspension, terminology_activation, rollout_mode, production_guard_config,
credential_state. **`production_guard_config` and `credential_state` can be logged
but NEVER approved/applied in 4D.** Requester ≠ approver. Every change request is
audited; a rollback plan is recorded.

## 5. SLA & escalation

Priorities: low(168h) / normal(72h) / high(24h) / critical_internal(8h); hard
issues default to `high`. Escalation ladder: branch_supervisor → clinical_reviewer
→ it_operator → super_admin → management (up-only). Resolution is by revalidation
only; hard issues can never be waived. External blockers are tracked separately.
*(Durations are safe provisional defaults; management approval pending.)*

## 6. Incident drills (hermetic, no network)

Run/record via `SatusehatIncidentDrillService`. Auto safety-invariant drills
(`external_send_flag_tampering`, `production_flag_tampering`, `integration_disabled`)
must PASS. Documented drills (each: trigger → expected safe state → diagnostic →
rollback → evidence): cross_branch_idor, incorrect_wave_enrollment,
duplicate_active_wave_membership, threshold_tampering, score_manipulation,
hard_issue_reopening, mapping_deprecation, source_drift, rehearsal_partial_failure,
synthetic_reset_scope, queue_worker_stopped, redis_unavailable, stale_assignment,
unauthorized_suspension, nginx_default_server_regression, co_tenant_shadowing,
privacy_leak_attempt, rollback_to_safe_state.

## 7. Rollback (non-destructive)

Keep SATUSEHAT disabled; suspend active waves; demote branch readiness safely;
return diagnosis rollout to informational; return code to the prior approved tag.
Retain issues/diagnoses/mappings/audit/UAT/governance records. No migration
rollback by default. Synthetic reset is marker-scoped only. Clinical workflows
remain available throughout.

## 8. GO gate

Operational GO requires: waves + matrix + promotion/demotion + change-control +
SLA + executive view implemented; **human operator UAT completed and signed off
(all six roles), material findings fixed + re-tested**; multi-branch rehearsal +
incident drills passed; branch isolation + performance verified; tests + security
review passed; CI green; deploy + smoke passed. Only then create the annotated tag
`satusehat-4d-multi-branch-readiness-scale-up-operational-governance-go`.
External GO is a separate, later SATUSEHAT-2 campaign.
