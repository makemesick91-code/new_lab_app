# HOTFIX-FIX-PRE-68-45-DOCTOR-PERFORMANCE-403

**Branch:** `hotfix/fix-pre-68-45-doctor-performance-403`
**Base / PR target:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (never `main`)
**Rollback baseline:** `fix-pre-68-45-rme-owner-doctor-inventory-pr-polish-go`

## Problem

The FIX-PRE-68-45 Scope C **Doctor Performance / Income** report (`GET /rme/reports/doctor-performance`) returned an opaque 403 for legitimate doctor accounts. Root cause: a doctor **user** that is not linked to a doctor **record** via `mst_doctors.user_id` resolves to the `denied` tier, so the controller aborted with a bare 403 and no diagnosis. There was no tool to detect the unlinked accounts or any permission leakage.

## Access decision (official for this hotfix)

| Caller | Result |
| --- | --- |
| **Linked Doctor** (`mst_doctors.user_id` + `view_own_doctor_performance_report`) | Own data only; a forged `?doctor_id=` is ignored (IDOR-forced). |
| **Unlinked Doctor** (`view_own_doctor_performance_report`, no doctor record) | Clear 403 — *"Akun dokter belum terhubung ke data dokter. Hubungi admin untuk menghubungkan user ke master dokter."* |
| **Owner / Supervisor RME** (`view_doctor_performance_report`) | Executive access (all doctors, all RME branches). |
| **Super Admin** | Access via the all-permissions role sync (existing convention). |
| **Kepala Cabang** | **403.** Has no doctor-report permission in this hotfix. Branch-scoped doctor access is **deferred** to a future sprint and must be built with explicit branch isolation + tests. |
| **User without permission** | 403 (route permission middleware). |

Full KTP/NIK is never rendered. Doctor identity is **never** inferred from name/email and accounts are **never** auto-linked.

## Changes

- **Service** `DoctorPerformanceReportService::resolveAccess()` splits a new `unlinked` tier out of `denied` (permission present, no `mst_doctors.user_id` link). Report aggregation is unchanged.
- **Service** `DoctorPerformanceReportService::accessAudit()` — read-only, privacy-safe audit (user id/name/email only).
- **Controller** `DoctorPerformanceReportController::index()` returns the clear 403 message for the `unlinked` tier; a plain 403 for `denied`.
- **Command** `php artisan rme:doctor-performance-access-audit` (`--json`, `--strict`) — detects unlinked doctor-role users, doctor records without a `user_id`, own-permission holders without a link, Kepala Cabang permission leakage (user- and role-level), and missing permissions. `--strict` exits `2` on any anomaly.
- **Role permissions** — verified already correct: Doctor → `view_own_doctor_performance_report`; Owner + Supervisor RME → `view_doctor_performance_report`; Super Admin → all; Kepala Cabang → none. No seeder change was required; `RoleSeeder`/`PermissionSeeder` remain idempotent (`syncPermissions` + `forgetCachedPermissions`).
- **Sidebar** — remains permission-gated (`@canany`). An unlinked doctor still sees the link but receives the clear 403 on click; the route stays protected server-side regardless of sidebar visibility.

## Durable rules

1. Doctor Performance report is **doctor-own-data only** for linked doctors, IDOR-forced server-side.
2. An **unlinked** doctor account must not access the report and must get a clear, diagnosable 403 — never other doctors' data, never an auto-link.
3. Owner / Super Admin executive access is explicit and permission-safe.
4. **Kepala Cabang has no access** in this hotfix. A future branch-scoped doctor report must be implemented explicitly with branch isolation and tests.
5. Use `rme:doctor-performance-access-audit` to detect unlinked doctors and permission leakage (run after any deploy that reseeds roles).
6. No KTP/NIK in doctor performance reports or the audit output.

## Tests

- `tests/Feature/RME/DoctorPerformanceReportAccessTest.php` (7) — full access matrix incl. Kepala Cabang 403 + no permission.
- `tests/Feature/RME/DoctorPerformanceAccessAuditCommandTest.php` (9) — registration, OK/anomaly detection, Kepala Cabang leak, `--strict` exit codes, privacy-safe JSON.
- Regression: `DoctorPerformanceReportTest` (6) + `RmeReportFilter|SidebarPermissionVisibility|RolePermissionHardening|PilotRouteAuthorization` (34) green.

## Deploy note

No migration. After deploy, reseed permissions idempotently and run the audit:

```
php artisan db:seed --class=PermissionSeeder --force
php artisan db:seed --class=RoleSeeder --force
php artisan rme:doctor-performance-access-audit
```

Report any unlinked doctor accounts to an admin for manual linking — do **not** auto-link.
