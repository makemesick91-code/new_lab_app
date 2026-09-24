# SUNU-GO-LIVE-READINESS-1

Branch `feature/sunu-go-live-readiness-1`, based on
`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` at
`a4513be5` (do NOT target `main`).

Certifies Cabang Sunu (`SPN4`) ready for its first REAL patient after
FULL-PATIENT-ESTATE-RESET-1, and arms Admin Sunu's branch context as a cohort of
one. Rule mirror `.cursor/rules/165-sunu-go-live-readiness.mdc`; operator
handoff `docs/operations/sunu-first-real-patient-checklist.md`.

**Scope is ONE branch and ONE account.** This asserts nothing about Landak,
Antang or Telkomas, and nothing about the device lock.

---

## What this workstream did NOT find

It was commissioned expecting a documentation defect and possibly a code fix.
Both suspected defects turned out not to exist, and saying so is part of the
result.

**No stale branch-code registry.** `CLAUDE.md` and
`.cursor/rules/73-rme-branch-sun4-perawat-online-context.mdc` were believed to
still declare `TKM1 = Telkomas` / `SUN4 = Sunu` as canonical. On the canonical
branch they do not: rule 73 carries an explicit SUPERSEDED marker, the registry
line names `TLK1` and `SPN4` directly, and dedicated rules
`92-telkomas-branch-code-canonical.mdc` and `136-sunu-branch-code-canonical.mdc`
own the aliases. Both rename revisions are merged ancestors of canonical.

**No phantom-branch hazard in `RmeBranchSeeder`.** The seeder was believed to
still declare the deprecated codes, which — since `DatabaseSeeder` calls it —
would have created duplicate `TKM1` / `SUN4` branches, active and RME-enabled,
on any `db:seed`. On canonical its registry is keyed by
`BranchCodeAlias::TELKOMAS_CANONICAL` / `SUNU_CANONICAL`, and its own tests pin
`TLK1` / `SPN4`.

**Root cause of both false findings:** the greps ran in the primary checkout,
which was sitting on an unrelated older CI-evidence branch, not on canonical.
This is recorded as rule 165 items 13–14 because it is the more durable lesson:
a finding measured against the wrong tree is not a finding.

**No split brain.** `resolveActiveBranchForAdmin()` (the branch a new visit
registers at) was the specific concern. It resolves through
`activeContextBranchId()`, which returns via `narrowToFrontOfficePin()` — so a
stale online-context row selected before arming yields NO branch rather than the
wider one. REVISION-FRONT-OFFICE-BRANCH-CONTEXT-LOCK-1 had already closed it.

---

## Production state, measured read-only

Host `srv1730088`, `/var/www/asia-dental-lab-v2`, `APP_ENV=pilot`,
`APP_DEBUG=false`, DB `asia_dental_lab_pilot`, PostgreSQL 16.15. Production HEAD
`bea624f3` = `revision-front-office-branch-context-lock-1-go`, so the capability
being armed was already deployed.

### Patient estate — reset premise CONFIRMED, not assumed

The handoff asserted a zero estate. It was verified rather than trusted, because
a "patients = 0" acceptance criterion run against a populated clinical database
would be a hazard, and because a patient appearing where zero was expected might
be the first real one.

| Table | Rows |
|---|---|
| `mst_patients` | 0 |
| `trx_clinic_visits` | 0 |
| `trx_medical_records` | 0 |
| `trx_odontograms` | 0 |
| `trx_rme_visit_consents` | 0 |
| `trx_rme_prescriptions` | 0 |
| `trx_rme_invoices` / `trx_rme_payments` | 0 / 0 |
| `trx_lab_orders` / `trx_lab_case_candidates` | 0 / 0 |
| `trx_rme_legacy_records` | 0 |
| `stg_legacy_patient_imports` | 0 |
| `mst_patient_documents` | 0 |
| `trx_satusehat_candidates` | 0 |
| `notifications` | 0 |

Preserved as intended: `sys_audit_logs` 996, `trx_satusehat_audit_logs` **43** —
the exact count the handoff predicted, which is itself corroboration.

### Branch foundation

| id | code | name | active | RME |
|---|---|---|---|---|
| 1 | `TLK1` | Cabang Telkomas | yes | yes |
| 2 | `LDK2` | Cabang Landak | yes | yes |
| 3 | `ATG3` | Cabang Antang | yes | yes |
| 4 | `MAIN` | Main Fallback Branch | yes | no |
| 5 | `SPN4` | **Cabang Sunu** | yes | yes |

Sunu rooms: `SPN-A` *Ruangan A* and `SPN-B` *Ruangan B*, both `active`,
`treatment_room`. No duplicates, nothing soft-deleted.

### Front Office

Eight Front Office accounts exist (7, 8, 16, 17, 29, 30, 31, 32) — matching the
config's "production carries EIGHT and the owner approved FOUR". Before this
workstream **no `FRONT_OFFICE` key existed in the production environment at
all**, so both flags sat at their `false` default with an empty cohort: the
capability was shipped and INERT, exactly as its GO tag claimed.

Admin Sunu (29) already worked at SPN4 by choice: an `online` `admin_clinic`
context on branch 5, and daily branch contexts for 2026-09-21 through 09-24 all
`initial=5 current=5 change_count=0`. **Arming therefore pins what the account
was already using** — the precondition that makes this a narrowing rather than a
lockout.

### Infrastructure

Disk 89G free of 96G (8% used). DB 27 MB. Backup timer
`daengtisiams-db-backup.timer` active, last run 8h before the audit, next in
15h, retained dumps present. `nginx`, `php8.3-fpm`, `postgresql` and
`daengtisiams-queue-worker` all active. `queue:failed` empty. Over the canonical
domain: `/login`, `/health/live`, `/health/ready`, `/health/lb` all 200, with
`ready` reporting database / cache / queue / storage / object_storage all `ok`.

### RM numbering after the wipe

`PatientMedicalRecordNumberService::composeForRegistration()` builds
`DG-{BRANCH}-{YEAR}-{manual}`; the sequence segment is **typed by staff**, not
auto-generated. There is no counter to reset and no way for the wipe to cause a
sequence collision, so nothing was reset — the master brief's preference for
leaving identifiers alone needed no exception.

---

## What was built

One gap was real. `front_office.branch_context_lock` and
`front_office.branch_device_lock` are both CRITICAL-risk, their scope lives in an
environment string, and **no read-only command could report who was armed**.
Every sibling capability here has one (`rbac:admin-lab-lab-only-audit`,
`rme:doctor-performance-access-audit`, `lab-workflow:pilot-readiness-audit`). The
alternatives were inference from the raw string or a REPL on production, which is
forbidden. Arming a front desk while verifying it by inference is precisely the
shape of change that should not happen.

- `App\Support\AccessControl\FrontOfficeBranchPinAuditor` — read-only. Reports
  flag state, cohort (through `FrontOfficeBranchDeviceCohort`, never a second
  parser), and per account THREE independently resolved branches: the pin,
  `BranchContext::forUser()`, and `resolveActiveBranchForAdmin()`. It compares
  them, so the split brain is checked on the host that is running, not only in a
  test. An armed id that is not a Front Office account is reported explicitly
  rather than silently omitted.
- `rbac:front-office-branch-pin-audit` (`--json`, `--strict`) — thin command.
  **Deliberately has no `--arm`/`--apply`**: arming stays a supervised ceremony
  (rule 164), and a command that could do it from a shell would make that
  ceremony optional.

Tests: `tests/Feature/FrontOfficeDevice/FrontOfficeBranchPinAuditTest.php`. Half
the cases force a BROKEN state — oversized cohort, branch outside the allowlist,
an armed id belonging to a doctor — because an instrument whose failure path is
dead would have certified this go-live just as cheerfully.

---

## Mutation validation — three survivors, all real

A green suite is not evidence that a guard is load-bearing. Every invariant this
workstream relies on was deleted and the suite re-run. The harness restores by
**copy** (never `git checkout --`, which would also discard untracked work) and
refuses to report a result when the mutation changed nothing, because a mutant
that did not apply is not a kill.

| # | Mutation | Result |
|---|---|---|
| M1 | pin enforcement removed from `FrontOfficeBranchPinResolver::compute()` | KILLED — 28 failed |
| M2 | narrowing removed from the SESSION-ROW site of `activeContextBranchId()` | **SURVIVED** → gap closed → KILLED — 1 failed |
| M2b | narrowing removed from BOTH sites | KILLED — 5 failed |
| M3 | device lock made to trigger on the context flag | KILLED — 7 failed |
| M4 | `pinningEnabled()` no longer ORs the device flag | KILLED — 6 failed |
| M5 | cohort membership check removed | KILLED — 9 failed |
| M6 | auditor blinded to an oversized cohort | **SURVIVED** → assertion tightened → KILLED — 1 failed |
| M7 | auditor made to write (`$user->save()` per account) | **SURVIVED** → test rewritten → KILLED — 1 failed |
| M8 | auditor able to arm | static: no write verb, no `--apply`/`--arm` option |

The three survivors were the point of doing this.

**M2 — a guard no test reached.** `activeContextBranchId()` narrows at two
sites: the daily-context branch and the raw session row. `admin_clinic` is a
DAILY-LOCKED role, so for a Front Office account every existing test took the
first path and the second was dead in the suite — it could have been deleted
with everything staying green. It is not dead in production: a session row that
survives into a new clinical day has no daily context covering it, and until the
operator selects again that raw row is all registration has. New test
`narrows the session row itself when no daily context covers the day`.

**M6 — a verdict that could not say which rule fired.** The oversized-cohort
test asserted only `decision === 'FAIL'`. With five armed accounts each
individually undecidable, the per-account anomalies already forced FAIL, so the
assertion passed while the oversize rule was gone. It now asserts the anomaly
by name.

**M7 — "read-only" measured as row counts.** The test compared table counts
before and after, so an `UPDATE` to an existing row was invisible. It now
asserts on the SQL the auditor actually emits via `DB::listen`, which is what
read-only has to mean.

## Security review

Performed manually and recorded here rather than run through the
`security-review` skill, which scopes the primary checkout and would have
diffed the wrong tree — the same failure mode that produced this sprint's two
false findings. Reviewed surface: the auditor, the command, and the tests.

- **No new HTTP surface.** A console command only; no route, no controller, no
  policy, no permission added or changed.
- **Nothing is persisted — but the first version of this claim was WRONG.** See
  "The read-only claim that failed on production" below. The auditor now
  resolves the branch authorities inside a rolled-back transaction, covered by a
  test using an AGED context row and by mutation M9.
- **No privilege widening.** It reports; it cannot arm, disarm, repair or
  reconfigure. Rule 164's "arming is a supervised ceremony, never a deploy"
  stays intact.
- **No PII.** Staff account names only — the same operational labels the
  sibling access-control audits print. Patient tables are unreachable from the
  auditor; verified by scan. No KTP/NIK, no secret, no credential, no token.
- **No injection surface.** Options are boolean flags; nothing from input
  reaches a query.
- **Cohort disclosure.** The parsed `(id → branch code)` map is printed. That is
  not a secret: the cohort config states in terms that only two non-secret
  values may live there, and no credential or key may.
- **Findings: none CRITICAL, none HIGH, none MEDIUM.**

---

## The read-only claim that failed on production

This is recorded in full because the corrective matters more than the feature.

The auditor was documented as read-only, asserted as read-only by a test called
`writes nothing at all`, and confirmed as read-only by mutation M7, which was
killed. It was merged, deployed, and then run against the pilot for its
pre-activation check — where it **wrote**:

```
before:  user 29 | admin_clinic | branch 5 | online   | 2026-09-24 02:20:29
after:   user 29 | admin_clinic | branch 5 | inactive | 2026-09-24 05:12:51
```

The 05:12:51 stamp is the audit run itself.

**Mechanism.** `BranchContext::forUser()` and `resolveActiveBranchForAdmin()`
both reach `UserOnlineContextService::currentContextFor()`, which lazily garbage
collects an expired session by calling `markExpiredInactive()`. The auditor
contains no write verb; the write is three calls deep in code it merely reads
through. A static scan for `->save(` could never have found it.

**Why the test did not catch it.** Every fixture in the suite created a FRESH
online context. `isExpired()` requires `last_seen_at` to be older than the
inactivity window, so the expiry branch was never reached in any test. The
assertion was true of the paths it exercised and false of the path production
took. Mutation M7 had the same blind spot: it proved the test could detect a
write I *added*, not one the call graph already contained.

**Impact.** Small but not zero. The row had already passed its TTL and the
operator's next request would have expired it identically; no branch assignment,
permission, or clinical record changed. What was damaged was the guarantee — a
tool whose whole purpose is to be safe against a live clinic cannot be
"read-only except sometimes", and the surrounding documents asserted the
stronger claim.

**Fix.** `resolveWithoutPersisting()` performs both resolutions inside a
transaction that is **always** rolled back — never `DB::transaction()`, whose
closure commits on success. The real authorities are still used, because an
auditor that reimplemented them would measure a copy and could disagree with
what actually serves the operator. New test
`leaves an EXPIRED online context exactly as it found it` ages the row past its
TTL first; mutation **M9** (revert the fix) is KILLED by exactly that test.

**The transferable lesson**, recorded as rule 165 item 6a: *read-only is a
property to be proven, not inferred from the absence of a write verb, and a
fixture that is always fresh cannot prove anything about stale-row handling.*

The production row was left as it now stands. It was genuinely expired, the
application would have made the same transition, and hand-editing a production
row back to `online` to tidy the evidence would be falsifying state.
