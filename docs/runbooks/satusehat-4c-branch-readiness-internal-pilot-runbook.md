# SATUSEHAT-4C — Branch Readiness Remediation & Internal Pilot Operations Runbook

**Status:** Internal readiness only. **SATUSEHAT-2 stays WATCH.** No OAuth / sandbox / production / external FHIR request occurs anywhere in this workflow. External submission is disabled; production is blocked. "Pilot ready" here means **internal** readiness — external readiness remains a separate credential blocker owned by the *SATUSEHAT-2 External Credential Closure Campaign*.

## 1. Scope & guarantees

- Branch-scoped readiness profiling, remediation, internal-pilot selection/approval/suspension, threshold governance, and synthetic rehearsal.
- **No branch is a pilot by default.** MAIN can never be a pilot clinic branch (enforced against `BranchService::rmeEnabledIds()`).
- Every write is transactional, locked, audited (append-only `trx_satusehat_audit_logs`), and PII-free (no NIK / raw clinical notes).
- Hard data-quality issues can never be waived and are never "resolved" except by revalidation (SATUSEHAT-4A rule).
- Readiness score cannot override hard blockers; approval requires **every** internal eligibility gate to pass.

## 2. Roles (RBAC)

| Role | Capability |
|---|---|
| Admin Klinik | Branch-pinned readiness view + issue remediation (`view_satusehat_branch_readiness`, `manage_satusehat_branch_remediation`). No cross-branch. No pilot config/approval. |
| Supervisor RME | Full: readiness, remediation, pilot configuration/approval, rehearsal, metrics. Still **no external send / production**. |
| Owner | Read-only branch readiness + pilot metrics. |
| Doctor | Structured diagnosis workflow only — no readiness administration. |
| Kasir / Admin Lab | Denied. |
| Super Admin | Canonical bypass (Gate::before). |

## 3. Operator workflow

1. **Profile** — `satusehat:branch-readiness --branch=<id>` (or the *Kesiapan Cabang* board) → per-component rates + score + failed internal gates.
2. **Remediate** — resolve open data-quality issues (assign priority + SLA, escalate, review). Resolution is a revalidation; the rule engine decides.
3. **Structured diagnosis** — raise adoption (SATUSEHAT-4B) until the adoption gate passes.
4. **Mappings** — activate reviewed treatment / diagnosis / dental mappings (clinical review; no guessed codes).
5. **Rehearse** — `satusehat:pilot-rehearse --branch=<id> --confirm` (synthetic, network-silent). Terminal result: `BLOCKED_EXTERNAL_CREDENTIAL` (internal pipeline clean) or `failed`.
6. **Select candidate** → **approve** (INTERNAL GO) once `satusehat:pilot-eligibility --branch=<id>` reports every internal gate passing.
7. **Operate** — monitor the *Operasi Pilot* dashboard (issue aging, operator backlog, external blocker, production guard).

## 4. Commands (read-only unless noted)

- `satusehat:branch-readiness [--branch=] [--json]`
- `satusehat:pilot-status [--json]`
- `satusehat:pilot-eligibility [--branch=] [--json] [--strict]`
- `satusehat:pilot-rehearse --branch=<id> [--confirm] [--dry-run] [--json]` *(--confirm persists a synthetic run)*
- `satusehat:issue-aging [--branch=] [--json]`
- `satusehat:operator-backlog [--branch=] [--json]`
- Reused: `satusehat:diagnosis-adoption-audit`, `satusehat:terminology-audit`, `satusehat:data-quality-scan`, `satusehat:queue-health`, `satusehat:production-guard-check`.

## 5. Thresholds & score

Config-driven defaults in `config/satusehat_pilot.php` (`thresholds`, `score.weights`), versioned. Per-branch reasoned + audited overrides via the *configure* action. Changing a threshold never resolves an issue and never makes a candidate ready.

## 6. Incident drills (hermetic)

Wrong-branch assignment, cross-branch IDOR, pilot suspended, adoption below threshold, deprecated mapping, source drift, synthetic reset scope, Redis/queue down, stale issue, duplicate rehearsal, production/send flag tampering, missing credentials, local conformance failure, emergency rollback to informational mode, Nginx co-tenant regression. Each: trigger → expected status → diagnostic command → operator action → escalation owner → rollback → evidence.

## 7. Rollback

Non-destructive: keep SATUSEHAT disabled, suspend the internal pilot, return rollout mode to informational, revert code to the prior approved tag. Issues / diagnoses / mappings / audit / pilot history are retained. Synthetic records are removed only via the marker-scoped `satusehat:synthetic-pilot reset --confirm`. No production data is ever deleted; no migration is rolled back by default.

## 8. Rules (durable)

SATUSEHAT-2 remains WATCH · no external HTTP · pilot readiness branch-scoped · MAIN excluded · no pilot by default · approval explicit + internal GO only · external credential a separate blocker · score cannot override hard blockers · hard issues resolve only by revalidation · assignment respects branch scope · Admin Klinik branch-pinned · Kasir/Admin Lab denied · no fabricated IHS/Organization/Location · synthetic reset marker-scoped · rehearsal ends at `BLOCKED_EXTERNAL_CREDENTIAL` · no auto-send / no Send All · NIK masked · production blocked · deploy runner runs on the VPS · DMS Nginx `default_server` protection retained.
