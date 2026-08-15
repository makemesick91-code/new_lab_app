# LEGACY-RME-DATE-TZ-1 — Canonical Clinical Timezone & Date-Boundary Correctness

**Branch:** `feature/legacy-rme-date-tz-1-canonical-clinical-timezone-date-boundary-correctness`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
**Type:** RUNTIME_FIX · **Migration:** none · **Data backfill:** none

---

## 1. The defect

The ROLL-4-WAVE-1 production report observed that the clinical timezone resolved
to **UTC** while DaengtisiaMS clinical operations run on **WITA (`Asia/Makassar`,
UTC+08:00)**.

Root cause, exactly:

```php
// config/app.php  — hard-coded, and it never reads APP_TIMEZONE at all
'timezone' => 'UTC',

// config/legacy_rme.php  — the clinical calendar
'clinical_timezone' => env('LEGACY_RME_CLINICAL_TIMEZONE', env('APP_TIMEZONE', 'UTC')),
```

Neither `LEGACY_RME_CLINICAL_TIMEZONE` nor `APP_TIMEZONE` is set in production,
and the environment example file declared no timezone key at all — so the chain fell all the
way through to the literal `'UTC'`. Two services then repeated the same
fallback inline:

- `LegacyRmeDateRuleService::timezone()` → `config('app.timezone', 'UTC')`
- `LegacyRmeMigrationQuotaService::today()` → `config('app.timezone', 'UTC')`

### Why UTC is wrong here

`Asia/Makassar` is UTC+08:00, so `2026-08-13 16:00:00Z == 2026-08-14 00:00:00`
in the clinic. For the eight hours between 16:00Z and midnight Z the two
calendars name **different days**.

The legacy archive age rule is `latest_rme_date < today`. Under a UTC-anchored
clock:

| Instant | UTC date | Clinical date | Document dated `2026-08-13` |
|---|---|---|---|
| `2026-08-13T15:59:59Z` | 2026-08-13 | 2026-08-13 | refused (correct) |
| `2026-08-13T16:00:00Z` | 2026-08-13 | **2026-08-14** | refused (**WRONG** — it is historical) |

The same document therefore produced a different eligibility answer depending on
nothing but the hour of submission. That is a clinical correctness defect, not a
cosmetic one.

### A second, quieter defect: split authority

`config/satusehat.php` already declared `clinic_timezone` defaulting to
`Asia/Makassar`. So the codebase carried **two** clinical-timezone declarations
that disagreed with each other in production.

---

## 2. The model

Two semantic domains, deliberately separate:

| | Technical instant | Clinical calendar date |
|---|---|---|
| Example | `2026-08-13T16:00:00Z` | `2026-08-14` |
| Frame | UTC — existing architecture, **unchanged** | `Asia/Makassar` |
| Covers | `created_at`, `updated_at`, queue, `sys_audit_logs`, deploy logs, telemetry | clinical eligibility, migration quota day |
| Read via | `now()` / Carbon | `App\Support\Clinical\ClinicalClock` |

**This sprint did not convert any timestamp to WITA.** `config/app.php` stays
`'UTC'` on purpose. A UTC process default beside a WITA clinical calendar is the
correct posture.

---

## 3. What changed

### New — the canonical authority

| File | Role |
|---|---|
| `config/clinical.php` | `'timezone' => env('CLINICAL_TIMEZONE', ClinicalTimezone::DEFAULT)` — the single declaration |
| `App\Support\Clinical\ClinicalTimezone` | `DEFAULT = 'Asia/Makassar'` (the only literal), strict IANA validation |
| `App\Support\Clinical\ClinicalClock` | `timezone()` · `today()` · `now()` · `toClinicalDate()` · `inspect()` |
| `App\Support\Clinical\InvalidClinicalTimezoneException` | fail-closed signal |
| `App\Console\Commands\ClinicalDateDiagnoseCommand` | read-only `clinical:date-diagnose` |

`ClinicalTimezone::isValid()` matches against the platform's IANA identifier
list, so `WITA`, `UTC+8`, `GMT+8`, `+08:00` and the real-world typo
`Asia/Makasar` are all rejected rather than silently accepted as a fixed offset.

### Modified

| File | Change |
|---|---|
| `LegacyRmeDateRuleService` | injects `ClinicalClock`; `today()`/`timezone()` delegate; inline UTC fallback **removed** |
| `LegacyRmeMigrationQuotaService` | injects `ClinicalClock`; inline UTC fallback **removed** |
| `LegacyRmeRolloutReadinessService` | new `clinical_timezone` check (FAIL on invalid, WATCH on non-canonical, GO on canonical) |
| `config/legacy_rme.php` | `dates.clinical_timezone` **removed**, with a comment recording why it must not come back |
| `config/satusehat.php` | `clinic_timezone` default now derives from `ClinicalTimezone::DEFAULT` — same effective value, one literal |
| `LegacyRmeMigrationQuotaFactory` | uses `ClinicalClock` so fixtures land in the bucket the gate reads |
| environment example file | documents `CLINICAL_TIMEZONE` (first timezone key it has ever carried) |
| `.github/workflows/foundation-evidence-gates.yml` | `ClinicalClock` added to **both** critical-gate filter variants |

### Deliberately NOT changed

- Any technical timestamp column, cast, or storage timezone.
- Any stored clinical DATE. **No data migration, no backfill.**
- The eligibility rule itself — still strict `<`, never relaxed to `<=`.
- The native-RME cutoff (DATE vs DATE, timezone-invariant).
- `NO_NATIVE_REFERENCE` as a valid state.
- `DoctorPatientScopeService`, RM-derived branch authority, PUBLISHED-only reads.
- `SatusehatPeriodResolver` semantics (it normalizes stored wall-clock instants
  into UTC for FHIR — a different job from the clinical calendar).

---

## 4. Data-impact assessment

The defect is a **DECISION-TIME defect, not a PERSISTED-DATA defect**:

- `selected_rme_date`, `latest_rme_date`, `rme_date`,
  `earliest_native_rme_date_snapshot` and `visit_date` are all `date` casts —
  calendar dates, never instants. Nothing in the old code converted them through
  a timezone; `LegacyRmeDateRuleService::normalize()` explicitly reduces every
  input to `toDateString()` before comparing.
- The only clock-dependent rule is the `< today` age gate, which is evaluated at
  decision time and never written to a column.
- Consequence: the wrong timezone could only ever cause a **wrong refusal** (a
  genuinely historical document rejected during the 16:00–24:00 UTC window). It
  could not write a wrong date, and it could not admit a document that should
  have been refused — the UTC clock is strictly *behind* the clinical clock, so
  it was more conservative, never more permissive.

**Therefore: no backfill, and none is escalated.** No stored value needs
correcting.

Migration-quota buckets are the one place a UTC day boundary was persisted
(`quota_date`). Those rows are operational counters, not clinical records; the
production admitted-branch set is empty and no wave is active, so there are no
buckets to reconcile.

---

## 5. Tests

| Suite | Tests |
|---|---|
| `tests/Feature/Clinical/ClinicalClockGovernanceTest.php` | 17 |
| `tests/Feature/LegacyRme/LegacyRmeClinicalDateBoundaryTest.php` | 18 |

Covered: canonical timezone · IANA-only (offset aliases rejected) · fail-closed
on invalid/blank · `inspect()` reports without throwing · process-default-UTC
independence · 23:59:59 / 00:00:00 / 00:00:01 WITA · month, year and leap-day
rollovers · `latest == clinicalToday` refused · `latest < clinicalToday` passes ·
upload and publish share one clock · time may legitimately advance between them ·
native cutoff timezone-invariant · `NO_NATIVE_REFERENCE` preserved · stored DATE
never shifts · single-date and multi-date range semantics preserved · birth-date
rule invariant · quota day == clinical day · request/header/cookie/query
timezone cannot override · readiness gate GO and FAIL paths · evaluation
persists nothing.

Every test that freezes the clock restores it in a `finally` plus an
`afterEach`, so no frozen instant can leak.

### CI mapping

| Test class | Authoritative gate |
|---|---|
| `Tests\Feature\LegacyRme\LegacyRmeClinicalDateBoundaryTest` | NSF-R011 Critical (`LegacyRme` — already in the filter) |
| `Tests\Feature\Clinical\ClinicalClockGovernanceTest` | NSF-R011 Critical (`ClinicalClock` — **added** to both variants) |

The `ClinicalClock` token was added deliberately so this foundational suite maps
to a real CI path, rather than repeating the earlier defect where a suite existed
locally and matched no filter.

---

## 6. Verification

```bash
php artisan clinical:date-diagnose --strict --json
php artisan clinical:date-diagnose --instant=2026-08-13T15:59:59Z --instant=2026-08-13T16:00:00Z
php artisan legacy-rme:rollout-readiness --expect=off --strict
```

Expected boundary: `15:59:59Z → 2026-08-13`, `16:00:00Z → 2026-08-14`.

The diagnostic never writes a row and never mutates the system clock, so it is
safe on the production host.

---

## 7. Safe resting state

This sprint does **not** authorize a migration wave. Production stays:

```
FEATURE_RME_LEGACY_PDF_ARCHIVE = false
migration capability            = OFF
admission                       = EMPTY
active wave                     = NONE
clinical mutation               = 0
```

Historical Wave-1 evidence (RM `DG-LDK2-2024-22681`, legacy date `2026-08-13`,
PUBLISHED) is read-only regression material. It is not re-imported, not
modified, and its date is not shifted.

---

## 8. Durable rules

Recorded in `.cursor/rules/92-canonical-clinical-timezone-date-boundary.mdc`,
`docs/runbooks/clinical-timezone-date-boundary-runbook.md` and `CLAUDE.md`.
