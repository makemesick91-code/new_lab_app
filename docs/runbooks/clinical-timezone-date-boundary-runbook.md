# Clinical timezone & date-boundary runbook

**Sprint:** LEGACY-RME-DATE-TZ-1
**Scope:** the clinical calendar authority, how to inspect it safely on any
deployment (including production), and how to troubleshoot a date-boundary
report.

---

## 1. The model in one page

There are **two** time domains in DaengtisiaMS and they are deliberately
different:

| | Technical instant | Clinical calendar date |
|---|---|---|
| Example | `2026-08-13T16:00:00Z` | `2026-08-14` |
| Means | an absolute moment | the day the clinic was living through |
| Frame | UTC (unchanged architecture) | `Asia/Makassar` |
| Examples in the code | `created_at`, `updated_at`, queue timestamps, `sys_audit_logs` event times, deploy logs | legacy archive eligibility, migration quota buckets |
| Read via | `now()`, Carbon | `App\Support\Clinical\ClinicalClock` |

`config/app.php` stays `'timezone' => 'UTC'` **on purpose**. Seeing `UTC` as the
process default next to `Asia/Makassar` as the clinical calendar is the
**correct** posture, not a finding.

### Why it matters

`Asia/Makassar` is UTC+08:00, so:

```
2026-08-13 16:00:00 UTC  ==  2026-08-14 00:00:00 Asia/Makassar
```

For the eight hours between 16:00Z and 24:00Z, the UTC calendar and the clinic
calendar name **different days**. The legacy archive rule is

```
latest_rme_date < clinical_today
```

so under a UTC-anchored clock a document dated the clinic's *previous* day was
still refused as "not yet historical" — and the identical document produced a
different answer depending only on the hour it was submitted.

---

## 2. Where the authority lives

```
config/clinical.php                       'timezone' => env('CLINICAL_TIMEZONE', ClinicalTimezone::DEFAULT)
App\Support\Clinical\ClinicalTimezone     DEFAULT = 'Asia/Makassar'   ← the only literal
App\Support\Clinical\ClinicalClock        the only reader
```

- `config/legacy_rme.php` has **no** `clinical_timezone` key. It was removed
  because its `env('LEGACY_RME_CLINICAL_TIMEZONE', env('APP_TIMEZONE', 'UTC'))`
  default resolved to UTC in production.
- `config/satusehat.php` derives its `clinic_timezone` **default** from the same
  constant so the two files cannot drift.

**Do not add a second clinical timezone key anywhere.**

---

## 3. Inspect the effective timezone (safe on production)

Reading the config file is *not* proof — only the running application knows what
it resolved. Use the diagnostic:

```bash
php artisan clinical:date-diagnose --json
```

Expected on a healthy deployment:

```json
{
  "clinical_timezone": "Asia/Makassar",
  "clinical_timezone_valid": true,
  "clinical_timezone_canonical": true,
  "technical_timezone": "UTC",
  "process_default_timezone": "UTC",
  "database_writes": 0,
  "system_clock_mutated": false
}
```

`--strict` fails the command unless the timezone is the canonical value — use it
in a release gate.

The rollout gate reports the same posture as a check:

```bash
php artisan legacy-rme:rollout-readiness --expect=off --strict
# check id: clinical_timezone
```

---

## 4. Prove the midnight boundary — without touching the clock

**Never** change the VPS system clock and **never** install a global test-now in
a long-lived process. Pass the instant as a calculation input instead:

```bash
php artisan clinical:date-diagnose \
  --instant=2026-08-13T15:59:59Z \
  --instant=2026-08-13T16:00:00Z \
  --instant=2026-08-13T16:00:01Z
```

Expected:

| instant (UTC) | clinical date |
|---|---|
| `2026-08-13T15:59:59Z` | `2026-08-13` |
| `2026-08-13T16:00:00Z` | `2026-08-14` |
| `2026-08-13T16:00:01Z` | `2026-08-14` |

The `--instant` value lives only inside that process and disappears when it
exits. The command writes nothing.

---

## 5. Troubleshooting

### `InvalidClinicalTimezoneException` / readiness check `clinical_timezone: FAIL`

The configured value is not a resolvable IANA identifier. This is **intentional
fail-closed behaviour** — a clinical decision made in an unknown frame is
silently wrong forever, so the system refuses instead.

Common causes:

- a typo such as `Asia/Makasar` (one `s`);
- an offset alias — `WITA`, `UTC+8`, `GMT+8`, `+08:00` are all rejected;
- a blank `CLINICAL_TIMEZONE`.

Fix: set `CLINICAL_TIMEZONE=Asia/Makassar` (or unset it to take the default),
then rebuild the config cache **as the runtime user**:

```bash
php artisan config:clear && php artisan config:cache
php artisan clinical:date-diagnose --strict
```

### The date boundary looks one day off

1. Confirm the effective timezone with `clinical:date-diagnose` — not by reading
   the config file, and not by trusting a cached config.
2. Confirm you are comparing the right things: `latest_rme_date` is a stored
   **DATE** and is never shifted; only "today" is derived from an instant.
3. Remember the rule is strict: `latest_rme_date == clinical_today` is **not yet
   historical**. That is correct behaviour, not an off-by-one.

### A stored legacy date looks wrong

Do **not** "correct" it with a timezone conversion. Stored DATEs are historical
evidence read off a document by a human. A published legacy record is immutable
— the correction path is VOID with a reason plus a fresh import, never an
in-place edit.

### Config cache hides a change

`config/clinical.php` resolves `ClinicalTimezone::DEFAULT` at cache-build time.
After changing the environment you must clear and rebuild the cache as the
runtime user (see INFRA-SEC-RUNTIME-1); a root-owned cache is a separate,
known failure mode.

---

## 6. Deploy

Nothing special: no migration, no seed, no data backfill.

```bash
# ON THE VPS ONLY — never locally, never on a CI runner
cd /var/www/asia-dental-lab-v2
bash scripts/deploy-vps-runner.sh start
```

DEPLOY-HARDEN-1 pins the target SHA and runs from an immutable snapshot; **no
manual pre-pull**. Deployment is complete only at `exit=0` **and** `DEPLOY OK`.

Post-deploy verification:

```bash
php artisan clinical:date-diagnose --strict --json
php artisan clinical:date-diagnose --instant=2026-08-13T15:59:59Z --instant=2026-08-13T16:00:00Z
php artisan legacy-rme:rollout-readiness --expect=off --strict
```

---

## 7. Rollback

The change is configuration + a service delegation; there is no schema or data
component, so rollback is the standard path and carries no data risk:

```bash
# ON THE VPS
bash scripts/rollback-vps.sh <previous-GO-tag>
```

Rollback preserves INFRA-SEC-ENV-1, INFRA-SEC-RUNTIME-1 and DEPLOY-HARDEN-1 —
rolling the application back never rolls the security tooling back.

**Rolling back restores the UTC-anchored clinical calendar**, i.e. it reinstates
the boundary defect. Only do it if this release breaks production for an
unrelated reason, and treat the resulting state as WATCH until a corrected
deployment succeeds.

---

## 8. What this runbook does not cover

- Changing technical timestamp storage — out of scope and deliberately unchanged.
- Per-branch clinical timezones — not implemented; a separate product decision.
- Enabling the legacy archive. This sprint leaves
  `FEATURE_RME_LEGACY_PDF_ARCHIVE=false`, capability OFF, admission EMPTY,
  active wave NONE.
