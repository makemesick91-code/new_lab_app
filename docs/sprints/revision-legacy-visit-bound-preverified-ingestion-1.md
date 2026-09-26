# REVISION-LEGACY-VISIT-BOUND-PREVERIFIED-INGESTION-1

**Branch:** `feature/legacy-visit-bound-preverified-ingestion-1`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `d0af0694`
**Rule mirror:** `.cursor/rules/167-legacy-visit-bound-preverified-ingestion.mdc`

---

## 1. What this sprint does, in one sentence

An authorized front-desk operator, standing at a **real visit** with the
patient's old paper chart, verifies the historical document date(s) **once** —
and the downstream checker never re-enters them.

## 2. What it deliberately does NOT do

The original prompt asked for **direct activation with no second review**. That
was **withdrawn by the owner** after the audit surfaced a genuine conflict, and
this sprint implements the revised intent instead.

| Asked for originally | Shipped |
|---|---|
| Auto-publish after attestation | **No.** A separate checker still finalizes. |
| SOD carve-out | **No.** `LEGACY-RME-SOD-1` stays armed, untouched. |
| Dormant SOD-bypass flag | **No.** No such switch exists. |
| Generic publish for Admin Klinik | **No.** Permissions unchanged. |
| Remove duplicate **date** verification | **Yes.** This is the whole deliverable. |

### Why the original design was refused

`LEGACY-RME-SOD-1` is armed on production (`LEGACY_RME_REQUIRE_SEPARATE_PUBLISHER=true`,
verified in the live environment) and enforces `uploaded_by != acting_user_id`
for **both** review and publish, re-asserted inside the publish transaction.
Its purpose is that "a human other than the filer actually looked at the
rendered pages."

Worse, the proposed "system transition" would have *passed* the guard by
accident: `SeparatePublisherGuard::violates()` returns `false` when the actor is
null. That would have satisfied the rule's letter while defeating its entire
purpose — a silent, undocumented weakening of a separately GO-tagged production
invariant, producing an outcome the uploader's own role is deliberately
forbidden from producing (they hold neither `review_` nor `publish_`).

## 3. The canonical workflow

```
REAL VISIT
  → operator opens the visit → "Arsip Legacy"
  → uploads Legacy RME / Legacy Odontogram
  → inspects the source document
  → enters the historical date(s)
  → explicitly attests: "Saya telah memeriksa dokumen asli…"
  → server re-resolves visit / patient / branch / ceiling
  → all canonical validation runs
  → source SHA-256 binds the attestation
  → async render
  → READY_FOR_REVIEW
  → SEPARATE checker: dates shown READ-ONLY, pre-attested
                      confirms patient / render / document integrity
  → canonical publish
```

## 4. Audit findings that shaped the design

### 4.1 `Admin Klinik` and `Front Office` both exist

The prompt warned against assuming they were the same actor. They are not — and
neither were they merged away. `RoleSeeder` keeps both ("`Admin Klinik` and
`Kasir` are deliberately LEFT INTACT"), with `Front Office` as their measured
union. Both already hold `create_legacy_*_imports` and `view_legacy_*_imports`,
and **neither holds `review_` or `publish_`**. The new permission therefore goes
to exactly those two roles — the cohort that could already file a document.

### 4.2 The date engine already had most of the bounds

`LegacyRmeDateRuleService` already implemented strict `latest < earliest native
RME`, strict `latest < today` via `ClinicalClock` (Asia/Makassar), the
birth-date bound, and the earliest/latest range model. Only the **visit
ceiling** was new. This sprint extended that one service rather than building a
second rule set.

### 4.3 Odontogram has **no** identity SOD — audited, not assumed

`LegacyOdontogramPublishService::review()/publish()` never compare the actor
with `uploaded_by`; there is no `SeparatePublisherGuard` equivalent. Its duty
separation is **permission-based** (`review_`/`publish_` are separate named
permissions the front-desk cohort holds neither of).

Per the owner's explicit instruction, **no identity SOD was invented for
symmetry**. The difference is preserved and documented, and the odontogram test
suite pins the control that actually exists rather than one that does not.

Consequence worth stating plainly: for odontogram, maker-checker holds via the
role split alone. A Super Admin (who bypasses via `Gate::before`) could still do
both — pre-existing, not introduced here.

### 4.4 Branch authority stays RM-derived

`origin_branch_id` remains derived from the patient's Nomor RM (FIX-ROLL2-1).
It is the column row visibility and the policies key off, so letting the visit
redefine it would silently change who can see existing rows. The visit governs
**identity, the date ceiling and access** — not archive ownership.

## 5. Changes

### Migration (additive only)

`2026_09_26_100001_add_visit_preverified_attestation_to_legacy_staging_tables`
adds to `stg_rme_legacy_imports` and `stg_odontogram_legacy_imports`:
`verification_mode`, `verification_visit_id`, `verification_visit_date`,
`verified_by`, `verified_at`, `verified_selected_date`,
`verified_latest_date` *(RME only)*, `verified_source_sha256`.

Nothing dropped, narrowed or backfilled. NULL means "did not come through a
visit" — a true statement about every pre-existing row.

### New shared primitives (`app/Support/Legacy/`)

| Class | Role |
|---|---|
| `LegacyVerificationMode` | the vocabulary; `VISIT_PREVERIFIED`, never inferred |
| `LegacyVisitBindingService` | **THE** visit authority — read-only, two gates |
| `LegacyVisitAttestation` | the resolved, server-built binding |
| `LegacyVisitBindingRefusal` | stable refusal codes |

### HTTP

Routes nested under `visits/{clinicVisit}` so a visitless variant cannot exist:

- `rme.visits.legacy-archive.rme.create` / `.store`
- `rme.visits.legacy-archive.odontogram.create` / `.store`

Three independent authorization layers: route middleware (`view_clinic_visits|manage_clinic_visits`
**plus** `verify_legacy_dates_at_ingestion`), the FormRequest (`create` on the
archive model **and** the same permission), and `LegacyVisitBindingService`
(ClinicVisitPolicy + `RmeWorkingBranchScope`, fail-closed).

### Checker UI

`resources/views/legacy/partials/preverified-date-evidence.blade.php` —
read-only evidence labelled **"Telah diverifikasi Admin Klinik saat upload"**,
showing verified dates, verifier, timestamp, bound visit and ceiling. **No
editable date control.** A wrong date means reject / re-import, never
edit-then-publish.

### Permission

`verify_legacy_dates_at_ingestion` → `Admin Klinik`, `Front Office`.

## 6. Tests

| Suite | Count |
|---|---|
| `tests/Feature/LegacyRme/VisitBoundPreverifiedIngestionTest.php` | 30 |
| `tests/Feature/LegacyOdontogram/VisitBoundPreverifiedOdontogramTest.php` | 13 |

Covering: attestation recorded once and SHA-bound · backlog path untouched ·
uploader cannot review/publish own document · separate checker can · SOD canary ·
no publish/void widening · permission granted to exactly two roles · strict
visit boundary (on/after/before, latest-only crossing) · native bound preserved ·
read-only checker dates with no date input · panel absent for backlog rows ·
revalidation (SHA drift, cancelled visit, moved visit date, incomplete
attestation) · non-enumerating refusal for out-of-scope visits · nonexistent /
null / cancelled / soft-deleted visit · patient mismatch · missing attestation ·
no visit ever created · branch stays RM-derived · no native clinical or billing
side effects.

## 7. Defects this sprint found in its own work

1. **Silent evidence loss.** `$attestation?->evidenceColumns(...)` inside a
   `DB::transaction` closure whose `use` clause omitted `$attestation` — a
   nullsafe call on an undefined variable is only a warning, so the whole
   attestation vanished with no error and five tests failed from one root cause.
   Fixed by computing the columns **before** the transaction.
2. **`x-ui.card` has no `subtitle` slot** — the mandated operator label was
   silently dropped. Now rendered as real content.
3. **Wrong redirect route names** in the controller (`rme.legacy-imports.show`
   → `settings.rme.legacy-imports.show`; the odontogram staging view is
   `settings.rme.legacy-odontograms.show`, while `rme.legacy-odontograms.show`
   is the *published record* viewer).
4. **A wrong test assumption about branch scope.** `users.branch_id` alone does
   not narrow `RmeWorkingBranchScope`; an active online context does. The
   original test would have passed only by accident. Rewritten to model a real
   branch-bound Front Office session, and to assert the binding **agrees with**
   `ClinicVisitPolicy` rather than inventing a second boundary.

## 8. Deploy

`php artisan migrate --force` (one additive migration) then
`db:seed --class=PermissionSeeder --force`,
`db:seed --class=RoleSeeder --force`, `permission:cache-reset`.

`LEGACY_RME_REQUIRE_SEPARATE_PUBLISHER` stays `true`. No flag is added, changed
or introduced by this sprint.
