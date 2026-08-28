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
| `LEGACY_RME_PILOT_BRANCH_CODE` | The single branch the pilot is confined to, e.g. `TKM1`. Since FIX-ROLL2-1 the branch an archive lands in is **derived from the patient's Nomor RM**, so this approved scope must match that derived branch |

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

## 8b. LEGACY-RME-PDF-ROLL-3 — multi-branch wave operations

### The two gates

A branch may start new migration work only when **both** pass:

- **CAPABILITY** — `FEATURE_RME_LEGACY_PDF_ARCHIVE=true` (the whole runtime).
- **ADMISSION** — the branch code appears in `LEGACY_RME_ADMITTED_BRANCH_CODES`.

Capability ON with an empty allowlist migrates nothing. That is the intended
closed default, not a misconfiguration.

The code checked is always the one **derived from the patient's Nomor RM**. It
cannot be chosen by an operator or supplied by a request.

### Admitting a wave

Every wave needs **its own** approval, and that approval must name the exact
branches it covers. ROLL-2's `pilot_scope` is historical evidence of the original
single-branch pilot and authorizes nothing here — do not reuse its reference.

1. Confirm the branch codes with the rollout owner. Use real codes from the
   branch registry — never assume a code from an earlier sprint.
2. Obtain the owner's approval reference for **this** wave (a ticket or decision
   id — never a patient identifier).
3. Set all four values in the environment file, exact codes only:
   - `LEGACY_RME_ADMITTED_BRANCH_CODES=ATG3,LDK2,SUN4`
   - `LEGACY_RME_ADMISSION_APPROVED_BRANCH_CODES=ATG3,LDK2,SUN4`
   - `LEGACY_RME_ADMISSION_APPROVAL_REFERENCE=<owner approval id>`
   - optionally `LEGACY_RME_WAVE=WAVE-1`
4. Rebuild the config cache through the canonical mechanism.
5. Prove it took effect: `php artisan legacy-rme:wave-status` — check that
   **Wave approval** is populated and **Approved scope** matches **Admitted
   branches** exactly. A red `Admitted WITHOUT approval` row means stop.
6. Confirm the readiness gate is still green:
   `php artisan legacy-rme:rollout-readiness --expect=on --strict`.

> **Adding a branch mid-wave.** Widen `LEGACY_RME_ADMISSION_APPROVED_BRANCH_CODES`
> *and* the admitted list together, against a fresh owner approval. Widening only
> the admitted list fails closed: the new branch is refused at runtime and
> `branch_admission` FAILs, while the already-approved branches keep working.

Matching is exact. `TKM` does **not** admit `TKM1`, and `TKM1-EXTRA` is a
different branch entirely.

### Monitoring a wave

```bash
php artisan legacy-rme:wave-status            # admitted branches, backlog, headroom
php artisan legacy-rme:wave-status --json     # evidence for the release record
```

Watch three numbers:

- **pending render jobs** — approaching `LEGACY_RME_MAX_PENDING_JOBS` means new
  uploads will start being refused. That is the ceiling working, not a fault.
- **oldest awaiting review (hours)** — growing means the wave has outrun its
  human reviewers. Add reviewers or slow intake; do not add capacity.
- **free disk** — falling toward `LEGACY_RME_MIN_FREE_DISK_BYTES` stops
  ingestion before a render can exhaust the volume.

If ingestion is being refused, let the queue drain. It reopens by itself; there
is no latch to clear.

### NORMAL DRAIN — routine wave rollback (one branch)

Use when a branch should stop taking new work: the wave is complete, the
reviewers are saturated, or the owner has paused that clinic.

1. Remove the branch code from `LEGACY_RME_ADMITTED_BRANCH_CODES`.
2. Rebuild the config cache.
3. Prove new intake is refused for that branch (attempt the create screen for a
   patient of that branch — it shows "Cabang belum masuk gelombang migrasi").
4. Prove the other admitted branches are unaffected.

**What DRAIN does:** stops new uploads and retry re-queues for that branch.

**What DRAIN deliberately does NOT do:** it does not block publishing an import
that is already staged and human-reviewed. Those finish their lifecycle, and
publish still performs the full canonical revalidation — permission, patient,
RM-derived branch, date range, native boundary and state machine. Nothing is
deleted, and no queued job is touched.

> **DRAIN is the normal controlled-wave rollback. It is NOT incident
> containment.** If an incident requires all Legacy mutations to stop —
> including publish — use the EMERGENCY STOP in §9.

### EMERGENCY STOP

See §9. It is the capability switch, it withdraws publish as well, and the
admission report will read `FEATURE_DISABLED` rather than a wave reason so the
distinction is visible in evidence.

## 8c. FEATURE-LEGACY-IMPORT-HUB-1A — the four states, and activating a wave

### Capability ON is not activation, and activation is not usability

These are four different things. Treating any two of them as one is how
production ran an entire release with the archive switched on and every upload
refused, while the hub page reported "Aktif".

| State | What is true | Can a document be accepted? |
|---|---|---|
| capability ON | `rme.legacy_pdf_archive` is on | **no** |
| + admission active | branch codes admitted under an owner approval that covers them | **no** |
| + wave active | a registered ROLL-4 wave is ACTIVE and bound to that approval | only for an assigned operator |
| = end-to-end usable | all of the above, operator assigned, quota left | **yes** |

Read the current state, do not assume it:

```bash
# One decision, with the first refusing gate named.
php artisan legacy-rme:rollout-readiness
php artisan legacy-rme:wave-status

# The composed pre-flight — run this before opening any batch.
php artisan legacy-rme:ops-readiness --json
```

The hub page at `settings.legacy-imports.index` now reports the same thing to an
operator: `Belum Dibuka` plus the specific blocker, instead of a green badge and
a permanent footnote. It remains a REPORT — the gate that admits a document is
still taken inside the transaction that writes it.

### Blocker codes

`CAPABILITY_OFF` · `NO_BRANCH_ADMITTED` · `APPROVAL_MISSING` ·
`APPROVAL_INCOMPLETE` · `WAVE_NOT_DECLARED` · `WAVE_NOT_REGISTERED` ·
`WAVE_NOT_ACTIVE` · `WAVE_BINDING_MISMATCH` · `WAVE_UNREADABLE` ·
`STATE_UNAVAILABLE`

They are ordered like the runtime chain, so the reported blocker is always the
first control actually refusing — never a later one that would send an operator
to fix the wrong thing.

`STATE_UNAVAILABLE` is the odd one out: it means the gate state could not be
evaluated at all. It is reported as SHUT, never as "no gates apply" — an
unevaluated gate must never read as an open one. Treat it as an infrastructure
fault (database or wave store unreachable), not as a governance decision.

### Activating a wave

Two halves of ONE decision. Both must agree or the binding check fails closed.

**Half 1 — the deployment's environment file (the AUTHORITY).** Edit on the
server, then rebuild the config cache:

```
LEGACY_RME_ADMITTED_BRANCH_CODES=TKM1,LDK2,ATG3,SUN4
LEGACY_RME_ADMISSION_APPROVED_BRANCH_CODES=TKM1,LDK2,ATG3,SUN4
LEGACY_RME_ADMISSION_APPROVAL_REFERENCE=ROLL-4-WAVE-4-OWNER-APPROVAL-2026-08-28
LEGACY_RME_WAVE=WAVE-4
```

The approved set must COVER the admitted set. Widening the allowlist without
widening the approval fails closed (`APPROVAL_INCOMPLETE`) rather than inheriting
the older, narrower decision — and a branch that is approved but NOT admitted is
still refused, which is what makes a staged rollout safe.

**Half 2 — the operational record (the MIRROR).** Every action is a dry run
unless `--apply`, and the actor's permissions are checked exactly as in the
browser:

```bash
php artisan legacy-rme:wave-admin register \
  --wave=WAVE-4 --name='Wave 4 — multi-branch legacy RME migration' \
  --branches=TKM1,LDK2,ATG3,SUN4 \
  --per-branch-daily-quota=100 \
  --planned-start-date=YYYY-MM-DD --planned-end-date=YYYY-MM-DD \
  --reason='<owner approval reference>' --actor=<id|email> --apply

php artisan legacy-rme:wave-admin approve  --wave=WAVE-4 --actor=<APPROVER> --reason='...' --apply
php artisan legacy-rme:wave-admin activate --wave=WAVE-4 --actor=<id|email>  --reason='...' --apply

# One assignment per branch. There is NO exemption for Super Admin: a permission
# is not an assignment.
php artisan legacy-rme:wave-admin assign --wave=WAVE-4 --branch=LDK2 --operator=<id|email> --actor=<id|email> --apply
```

The approver must differ from the creator while
`LEGACY_RME_REQUIRE_SEPARATE_APPROVER=true`, and the publisher must differ from
the maker of each document while `LEGACY_RME_REQUIRE_SEPARATE_PUBLISHER=true`.
Account separation is what the application can verify; **human** separation is a
staffing control it cannot see and must never be claimed on its behalf.

### The daily ceiling is 100 per branch, per clinical day

Two independent counters apply and the tighter one wins:

- the hub ceiling — 100 accepted records per branch per clinical day per import
  type (`ops_legacy_import_daily_quotas`), and
- the wave's own per-branch quota, set to `100` above.

Set the wave quota to 100 explicitly. The operations layer's own default is 25,
so omitting it silently narrows the wave to a quarter of the approved ceiling.
The clinical day rolls over on Asia/Makassar via `ClinicalClock`, never on UTC
midnight.

### Activation imports nothing

Opening a wave migrates no patient data. A human still uploads each document, a
reviewer reviews it, and a separate publisher publishes it. Nothing scans
storage and nothing ingests on its own — if activation appeared to create
records, stop and treat it as an incident.

## 9. Rollback to OFF (EMERGENCY STOP / end-of-wave close)

1. Set `FEATURE_RME_LEGACY_PDF_ARCHIVE=false` in the environment file.
2. Rebuild the config cache through the canonical mechanism.
3. Prove it: `legacy-rme:rollout-readiness --expect=off --strict` exits `0`.
4. Spot-check that the operator and clinical archive routes now answer `404`.

For a full close, also clear `LEGACY_RME_ADMITTED_BRANCH_CODES` so the
deployment returns to *capability off, no branch admitted* — the state ROLL-3
expects between waves.

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

---

## 14. Panduan Dokter — membaca RME lama (LEGACY-RME-DOCTOR-WORKSPACE-1A)

Arsip RME lama pasien kini berada di dalam **urutan halaman yang sama** dengan
rekam medis tulisan tangan. Dokter tidak perlu membuka bagian dokumen terpisah
dan tidak perlu menggulir ke Riwayat RME.

1. Buka **RME Tulisan Tangan Lengkap** pada kunjungan pasien.
2. Berpindah halaman dengan **geser kiri/kanan**, atau tombol
   **← Sebelumnya** / **Berikutnya →**, atau menekan nomor halaman.
3. Halaman RME Legacy muncul langsung di urutan halaman yang sama, tepat
   setelah halaman tulisan tangan terakhir.
4. Halaman arsip diberi label **"Hanya Baca"** dan tombol nomornya diawali
   huruf **L** (misalnya `L3`). Halaman ini tidak dapat ditulisi, diubah, atau
   dihapus.
5. Gunakan **+ Perbesar / − Perkecil / Sesuaikan** untuk membaca, dan
   **Perbesar Layar Penuh** bila perlu. Menutup layar penuh mengembalikan Anda
   ke halaman yang sama.
6. Kembali ke halaman tulisan tangan dengan geser atau tombol navigasi yang
   sama untuk melanjutkan menulis.

**Catatan penting untuk dokter**

- **+ Tambah Halaman RM** selalu membuat **halaman rekam medis baru (native)**,
  termasuk ketika ditekan saat sedang membaca halaman arsip. Tombol ini tidak
  pernah menambah atau mengubah arsip lama.
- Saat memperbesar halaman arsip, gerakan geser dipakai untuk menggeser gambar
  (bukan berpindah halaman). Tekan **Sesuaikan** untuk kembali ke ukuran penuh
  agar geser kiri/kanan kembali berpindah halaman.
- Jika ada tulisan tangan yang belum disimpan, sistem akan memperingatkan
  sebelum Anda meninggalkan halaman. Tulisan tidak akan hilang diam-diam.
- Jika gambar halaman arsip belum tersedia, sistem menampilkan tautan
  **Buka Dokumen Arsip (PDF)**; halaman rekam medis lain tetap dapat digunakan.
