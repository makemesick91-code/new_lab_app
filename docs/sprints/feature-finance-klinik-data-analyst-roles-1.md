# FEATURE-FINANCE-KLINIK-DATA-ANALYST-ROLES-1

**Type:** FOUNDATION_SPRINT (new module `ClinicOversight`) · **Base:** `f4c89cba`
(docs on top of production `a8685125` / `revision-rme-visit-detail-invoice-registration-ux-1-go`)
· **Rule:** 186 (new) · **GO tag:** `feature-finance-klinik-data-analyst-roles-1-go`

Two independent read-only roles — **Finance Klinik** and **Data Analyst** — that
see every RME branch: a KPI dashboard, native + published legacy medical records
(read, print, PDF, export), and dental-room / queue monitoring. No migration, no
schema change. Four permissions, two roles, nobody assigned.

---

## 1. Decisions (owner D1–D12, as implemented)

| # | Decision | Where it lives |
|---|---|---|
| D1 | Two roles, identical **initial** permissions, independent records | `RoleSeeder::OVERSIGHT_INITIAL_PERMISSIONS`, two `ROLE_PERMISSIONS` entries |
| D2 | Lab `Finance` role untouched | asserted by `ClinicOversightRolePermissionTest` |
| D3 | Cross-branch = active + RME-enabled, MAIN excluded | `ClinicOversightScope::eligibleBranches()` |
| D4 | Read-only, GET-only surface | `routes/web.php` group `oversight.*` (35 GET routes) |
| D5 | Five KPIs, clinic day = WITA | `OversightKpiRepository`, `ClinicalDayWindow` |
| D6 | Piutang = **active as of now**, not date-filtered | `activeReceivablesByBranch()` |
| D7 | Native + PUBLISHED legacy RME/odontogram | `OversightClinicalRecordService`, `OversightLegacyArchiveController` |
| D8 | Print / PDF / CSV require a separate export permission | `export_oversight_data` |
| D9 | Monitoring Dental + Monitoring Antrian | `OversightMonitoringService` |
| D10 | No KTP image, no full NIK, no consent record | views + exports, asserted |
| D11 | Every identified read / extraction audited, PII-free | `OversightAuditService` |
| D12 | No user receives either role | no seeder/migration assigns them |

## 2. Why a dedicated module

Measured on the base commit before any code was written:

1. `RmeWorkingBranchScope` gives every role that is **not** context-bound the full
   RME branch set by an implicit fall-through. Granting `view_clinic_visits` would
   have made that fall-through the authority for two new cross-branch roles.
   This is the pre-existing finding **F-01**; it is **not** fixed here and **not**
   used by this module.
2. The operational `rme.prescriptions.print` stamps `printed_at` on a GET.
3. The `visit.room` middleware redirects roomless pre-exam visits.
4. The operational visit page links the consent surfaces.
5. The legacy workspace scopes widen only for governance permissions (rules
   173/174); a read-only permission must never join those sets.

So the module reuses the operational **presentation** — the native print / PDF
templates (through one extracted `ClinicVisitPrintDataBuilder`), the odontogram
and prescription print templates, the legacy page/PDF templates, and the unified
medical-record index repository (unmodified, fed an explicit scope value object)
— but never an operational controller, policy or branch scope.

The three operational print templates gained only an optional back/PDF URL
override (`$printBackUrl ?? route(...)`); their operational output is unchanged.

## 3. Permissions and roles

| Permission | Finance Klinik | Data Analyst |
|---|---|---|
| `view_finance_analytics_dashboard` | ✓ | ✓ |
| `view_clinical_records_readonly` | ✓ | ✓ |
| `view_operational_monitoring` | ✓ | ✓ |
| `export_oversight_data` | ✓ | ✓ |

Grouped under **RME** in `PermissionGroupingService`. Not granted: `view dashboard`,
`view_owner_dashboard`, `view_clinic_visits`, any `manage_*` / `create_*` / `void_*`,
`view_rme_consents`, `view_cross_branch_patient_ktp_documents`, or any legacy
import/review/publish/void permission. No wildcard.

## 4. KPI definitions

Clinic day `D` = the Asia/Makassar date from `ClinicalClock` (default: today).
DATE columns compare as `[D, D+1)`; UTC timestamps compare against the half-open
UTC range of that WITA day, with no function applied to the column.

| KPI | Definition |
|---|---|
| Jumlah Pasien | DISTINCT patients of visits on D with `check_in_at` set, not cancelled, not deleted. Total = distinct people across branches (branch sum also reported). |
| Jumlah Transaksi | DISTINCT cashier batches of `trx_rme_payments` paid on D; NULL batch → one per payment (`'payment:'||id`). Total = distinct batches overall. |
| Jumlah Omzet | `SUM(trx_rme_payments.amount)` by `paid_at` on D, attributed to the **invoice** branch. Lab `trx_payments` never read. |
| Piutang (PIUTANG AKTIF PER SAAT INI) | invoices UNPAID/PARTIAL, `grand_total > 0`, `grand_total − SUM(valid payments) > 0`. Not date-filtered. |
| Pasien Cancel | visits with status cancelled whose `cancelled_at` falls in D. |

Money is computed as exact integer cents. Nothing is cached. The page polls the
JSON feed every 60 s, pauses while the tab is hidden, and every poll is
re-authorized and re-scoped on the server.

## 5. Branch authority

`ClinicOversightScope` is the module's only branch authority. A user without the
area's permission gets an empty set. A request `branch_id` only narrows: an id
outside the set, MAIN, an inactive or non-RME branch, or a non-numeric value all
yield **no** branch — never the whole set. Every record id is resolved through the
scope, so an out-of-scope id is a **404**. Legacy records additionally require
`PUBLISHED`, asserted in the controller because Super Admin passes every gate.

## 6. Monitoring

* **Dental room** = `treatment_room` or `consultation_room`. The board lists active
  rooms; lists keep visits in a since-deactivated dental room (labelled).
* Room attribution = the room currently stored on the visit. There is no room
  history in the schema, so none is claimed.
* **JENIS PERAWATAN DITAGIHKAN** = items of issued invoices (UNPAID/PARTIAL/PAID);
  DRAFT and VOID excluded; free-text items go to *Lainnya / Item Tidak
  Terklasifikasi*. This is billing evidence, not proof a procedure was performed.
* **Queue** = visits of the clinic date per branch; older unfinished visits are
  counted separately and never mixed into today's queue.
* Nothing reads or writes the online-context presence table.

## 7. Landing and sidebar

A user holding neither `view dashboard` nor `view_owner_dashboard` now lands on the
first oversight area they may read. No existing role's landing changes (every
existing role either holds `view dashboard` or is handled by an earlier branch).
The sidebar gets one section, *Finance & Data Analyst*, gated by the three view
permissions. The sidebar is never the boundary.

## 8. Pre-existing findings, not addressed

* **F-01** — implicit all-branch fall-through in `RmeWorkingBranchScope` for roles
  that are not context-bound.
* **F-06** — insufficient room/doctor validation in a visit update path.

Both belong to a separate security sprint. This sprint neither fixes nor uses
them.

## 9. Evidence (pre-merge, local)

**New suites** — `tests/Feature/ClinicOversight/`: 64 tests / 955 assertions,
all passing. They cover:

* role and permission matrix: the lab Finance role is unchanged and nobody is
  assigned;
* the five KPIs with WITA boundaries, batch dedupe, invoice-branch attribution,
  lab exclusion, undated receivables, `cancelled_at` and a bounded query count;
* a GET-only route table;
* a 403 for nine other roles and a guest redirect;
* export withdrawal;
* MAIN, inactive and non-RME branches give 404;
* no KTP, NIK or consent anywhere;
* no business write on any GET;
* a PII-free audit;
* every mutation endpoint is refused;
* landing and sidebar behaviour;
* native and PUBLISHED-only legacy records, including VOID, MAIN and branchless;
* monitoring semantics;
* each defence layer tested on its own.

**Mutation testing.** Every mutant was applied by copy, checked to have changed
the file, and restored by copy with an sha256 check. Of 19 mutants, 18 were
killed and 1 is equivalent, leaving 0 real survivors.

* The first round left five survivors: M04 (scope without the permission check),
  M05 (export without the export permission), M09 (revenue without the lower day
  bound), M14 (export route without its middleware) and M15. Each was a real
  gap where a different layer was covering for the layer under test.
  `ClinicOversightGuardLayerTest` and a payment at `D−1 23:59:59 WITA` now kill
  the first four.
* M15 (the handwriting-image policy check removed on its own) survives because
  the explicit branch check behind it duplicates the guard. M15b, which removes
  both checks, is killed, so M15 is proven equivalent: the redundancy is
  deliberate.
* M12 did not apply on the first attempt because its anchor matched twice. It
  was split into M12a and M12b, and both are killed. That includes a Super
  Admin, who passes every gate, being refused a VOID record with a 404.

**Security review** (independent, adversarial, read-only): no CRITICAL, HIGH or
MEDIUM findings. There was one LOW finding: native handwriting image streams
were not audited. It is fixed, and each stream is now audited per image, only
once the image is known to exist. Mutants M16 and M17 are killed. Legacy
thumbnails are deliberately exempt, following the LEGACY-RME-PDF-1C convention.

**Regression** (run alone in the worktree):

| Scope | Result |
|---|---|
| AccessControl, Auth, Sprint53–55, Deploy, Navigation, User, Dashboard, Owner, ClinicOversight | 510 passed, 9 skipped |
| Cicd, RME, Ui, Patient, PatientMerge, BranchScope, MasterData | 2652 passed, 0 failed (stopped at the 30-min background limit inside ClinicMasterData; re-run below) |
| ClinicMasterData | 129 passed |
| LegacyRme, LegacyOdontogram, LegacyImportHub, LegacyMassUpload, LegacyBatchReview, LegacyBatchPublish, LegacyCompleteness, DoctorDevice, DoctorAccess | 2819 passed, 34 skipped |
| Inventory, Delivery, Reporting, Invoice, Satusehat | 1782 passed |

**Gates:**

* `pint --test` passes;
* `git diff --check` is clean;
* `view:cache` and `npm run build` both pass;
* `architecture:ui-governance-check --strict`: GO;
* `sprint:manifest-check`: GO;
* `sprint:scope-audit`: GO (3 modules);
* `foundation:devflow-check --strict`: GO;
* `foundation:shared-service-audit --strict`: GO;
* `foundation:security-compliance-check`: GO (9/9);
* `foundation:ci-runtime-control-check --strict`: GO;
* `foundation:cicd-enterprise-gate-check`: GO (10/10);
* `foundation:roadmap-check --strict`: GO.

**CI wiring.** A `ClinicOversight` token was added to both critical-gate
variants, and all six suites are declared in
`ci_runner.critical_gate_mandatory_suites`. `CriticalGateSuiteCoverageTest`
passes.

**Graphify.** The graph now has 38,153 nodes and 55,508 edges, up from 37,798
and 54,843. Outside the module, only the provider, the routes and the CI
registry reference `ClinicOversight`.

**Full Suite.** `FULL_SUITE=SKIPPED`: the global temporary Full Suite policy
(`.github/ci-policy/full-suite-policy.json`, `ACTIVE`) defers it. This is not a
pass.
