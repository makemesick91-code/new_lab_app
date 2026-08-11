# Legacy RME PDF Archive — Controlled Rollout Runbook

**Sprint:** LEGACY-RME-PDF-ROLL-2
**Feature flag:** `rme.legacy_pdf_archive`
**Environment override key:** `FEATURE_RME_LEGACY_PDF_ARCHIVE`
**Gate command:** `php artisan legacy-rme:rollout-readiness`
**Owner:** RME module owner (enablement decision) + VPS operator (execution)
**Review cadence:** before every enablement and after every rollback

---

## 1. Purpose

LEGACY-RME-PDF-1A..1D delivered the legacy RME archive runtime. LEGACY-RME-ROLL-1
made the flag's runtime override survive a cached config. This runbook governs the
only remaining question: **turning it on in production, and turning it off again.**

The archive is **OFF by default and stays OFF** unless someone explicitly decides
otherwise against a green readiness report. A GO tag on the code is not an
authorization to enable the feature, and a successful pilot is not an
authorization to widen it.

## 2. When to use this runbook

- Before enabling the archive on any deployment.
- Immediately after enabling it, to prove the switch actually took effect.
- To roll the archive back to OFF.
- During a controlled pilot (import → review → publish → VOID → correction).

## 3. Prerequisites

| Requirement | Why |
|---|---|
| Deployed commit matches the intended GO tag | The gate audits the running code, not the repository |
| Poppler (`pdfinfo`, `pdftoppm`) installed | Rasterization stalls forever without it |
| A real background queue worker running | Page rendering is queued by design, never inline |
| Private disk writable | Rendered pages are clinical evidence |
| Legacy permissions seeded | Otherwise an authorized operator lands on a 403 |
| Current verified database backup | Rollback safety, per the standing deploy contract |
| **An approved pilot scope** | The decisive gate — see §5 |

## 4. Safe commands

All of these are read-only unless stated otherwise.

```bash
# The gate. Run it before doing anything else.
php artisan legacy-rme:rollout-readiness --expect=off --strict

# Machine-readable evidence for the release record.
php artisan legacy-rme:rollout-readiness --json

# After enabling — proves the switch resolved, rather than assuming it.
php artisan legacy-rme:rollout-readiness --expect=on --strict

# After rollback — proves the feature is genuinely closed again.
php artisan legacy-rme:rollout-readiness --expect=off --strict

# Cross-check the flag registry directly.
php artisan foundation:feature-flags --json
```

On the VPS these run as the PHP-FPM runtime user, not as root:

```bash
runuser -u www-data -- php artisan legacy-rme:rollout-readiness --expect=off --strict
```

Running them as root creates root-owned cache files that the web process then
cannot write — the failure mode already documented in the deploy runbooks.

## 5. Approved pilot scope — the gate nobody bypasses

A controlled pilot runs against **a patient and a historical document that a
human explicitly authorized**. There is no default and no inference.

The following are **not** authorization:

- the code shipped and carries a GO tag
- a Super Admin account exists
- a previous sprint passed
- the feature "would probably be fine"

Record the approval as environment settings before enabling:

| Setting | Meaning |
|---|---|
| `LEGACY_RME_PILOT_APPROVED` | `true` only when a human approved a specific pilot |
| `LEGACY_RME_PILOT_APPROVAL_REFERENCE` | Non-PHI governance reference (decision id / runbook section) |
| `LEGACY_RME_PILOT_BRANCH_CODE` | The single branch the pilot is confined to, e.g. `LDK2` |

Deliberately **not** stored anywhere in config: the patient identifier, the
document path, any name. Patient ownership and the historical-date rule are
enforced per import by the 1A date-rule service and the 1C publish revalidation
— that is where a per-patient decision belongs.

## 6. Enablement procedure

1. **Prove the baseline is OFF.**
   `legacy-rme:rollout-readiness --expect=off --strict` must exit `0`.
   A NO_GO here stops the rollout; do not "fix" it by enabling anyway.

2. **Confirm a verified backup exists.** Enabling precedes clinical writes.

3. **Record the approval** (§5) in the environment file, then rebuild the
   config cache through the project's canonical mechanism so the override is
   actually resolved.

4. **Set the override** `FEATURE_RME_LEGACY_PDF_ARCHIVE=true` in the environment
   file and rebuild the config cache again.

5. **Prove the switch took effect.**
   `legacy-rme:rollout-readiness --expect=on --strict` must exit `0`.
   If the environment file says `true` but the gate still reports OFF, **stop** —
   the override is not resolving and the rollback path is equally broken.

Never edit PHP source, and never hand-patch the cached config file, to enable a
feature.

## 7. Controlled pilot sequence

Run as the authorized Master Data operator, against the approved scope only.

1. **Import** — Master Data → Master Data RME → Import Arsip RME Lama. Select the
   approved patient, upload the approved historical PDF, and enter the document
   date **read off the document itself** — never today, never the upload time.
2. **Processing** — the queued job renders pages via Poppler. If it does not
   leave `PROCESSING`, check the worker; never edit the status in the database.
3. **Review** — confirm the correct patient, correct document, readable pages and
   correct page order.
4. **Publish** — the publish revalidates patient, date and pages atomically and
   produces an immutable record.
5. **Patient history** — confirm the record appears, clearly labelled as legacy
   and visually distinct from native RME.
6. **Doctor viewer / print / export** — confirm read-only access, and that the
   doctor can neither publish nor VOID.

Verify after publish that **no** native artifact was created: no clinic visit, no
native medical record, no invoice, no payment, no odontogram, no lab order, no
SATUSEHAT candidate. The archive is history, not a live encounter.

## 8. Correction procedure

A published record is immutable. Correct it by **VOID + fresh import**, never by
editing the published evidence.

1. VOID the incorrect record with a real, meaningful reason (the reason is
   mandatory and is preserved permanently).
2. Import the corrected document as a **new** import batch.
3. Review and publish it.

The voided record and its files remain. VOID retracts; it never erases.

## 9. Rollback to OFF

1. Set `FEATURE_RME_LEGACY_PDF_ARCHIVE=false` in the environment file.
2. Rebuild the config cache through the canonical mechanism.
3. Prove it: `legacy-rme:rollout-readiness --expect=off --strict` exits `0`.
4. Spot-check that the operator and clinical archive routes now answer `404`.

**Rollback preserves everything.** Disabling the feature hides the runtime; it
does not delete the schema, the staged imports, the published or voided records,
or any file on the private disk. Data loss is never part of a rollback.

Rollback does **not** affect native RME. Native visits, records, billing and lab
workflow continue unchanged.

## 10. Evidence to capture

- `legacy-rme:rollout-readiness --json` before enabling, after enabling, after rollback
- The deployed commit SHA and GO tag
- Backup filename and its verification decision
- Counts before/after the pilot for the native tables listed in §7
- Failed-job count before and after
- Application log delta, reviewed for errors attributable to the pilot

Evidence must never contain a patient identifier, a KTP/NIK, document contents,
a private storage path, or any credential.

## 11. Troubleshooting

| Symptom | Cause | Action |
|---|---|---|
| Gate reports OFF after setting the override to true | Config cache not rebuilt, or the build-time capture is broken | Rebuild the config cache; if it persists, treat as a ROLL-1 regression |
| Routes report missing | Stale route cache | Clear and rebuild the route cache |
| Import stuck in `PROCESSING` | Queue worker down, or Poppler absent | Check the worker and `pdftoppm`; never edit status in the database |
| Operator gets 403 | Permissions not seeded | Re-run the permission seeder and reset the permission cache |
| `pilot_scope_approved` FAIL | No approval recorded | Obtain a real approval — do not bypass the check |

## 12. Forbidden actions

The following must **never** be used in any part of this procedure:

- `migrate:fresh`, `db:wipe`, `migrate:reset`, `schema:drop` — the archive holds
  clinical evidence, and none of these has any legitimate role here
- Deleting published or voided legacy records, or their files, to "clean up" a test
- Editing a published record's content instead of VOID + fresh import
- Editing application source directly on the server
- Hand-patching the cached config file to flip the flag
- Enabling the feature without an approved pilot scope
- Widening the pilot to more patients or branches without a fresh approval
- Granting a broad permission temporarily to make a pilot step pass

## 13. What a successful pilot does *not* authorize

A green pilot proves the workflow operates safely **for the approved scope**. It
does not authorize all branches, all patients, bulk or background historical
import, permanent production enablement, skipping manual review, converting the
archive into native RME, or submitting legacy data to SATUSEHAT.

Widening the rollout is a separate, explicitly approved stage.