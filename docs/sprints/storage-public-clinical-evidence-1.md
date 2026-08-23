# STORAGE-PUBLIC-CLINICAL-EVIDENCE-1 — Private Clinical Evidence Remediation

**Type:** clinical-data confidentiality incident remediation
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `6c66762`
**Prior authority preserved:** `restore-drill-evidence-read-state-1-go`
**STORAGE-1 GO tag:** `storage-1-object-storage-readiness-go` @ `d8a9c73` — **unchanged, not moved, not re-issued**

---

## 1. Why this sprint exists

A storage inventory run against the current authority found that native clinical
evidence was being written to Laravel's `public` disk. That disk is symlinked
into the document root (`public/storage -> storage/app/public`), so the web
server serves it directly, with no session, no policy and no branch check.

This was **proven on production**, not inferred. A synthetic 32-byte non-PII
probe was written into `storage/app/public/handwritings/`, fetched over plain
HTTPS with no credentials, and returned **HTTP 200 with byte-identical
content**. The probe was then deleted. No patient file was ever retrieved as
part of the proof.

Exposed at the time of discovery:

| Category | Files | Nature |
|---|---:|---|
| `handwritings/` | 46 | RME handwritten clinical records |
| `prescriptions/` | 8 | Prescription and doctor-signature canvases |
| `lab-orders/` | 1 | Patient-linked lab attachment image |

Object keys were only weakly unguessable —
`handwritings/{branch_id}/{visit_id}/handwriting_p{N}_{YmdHis}.png` — where the
only entropy is a one-second timestamp, against an unauthenticated endpoint with
no rate limiting.

This violated the project's own shipped rule **STORAGE-R002 — Private by
default** ("never a raw public disk URL"). That rule was written for *object*
storage; nothing enforced it for the *local* public disk, and every clinical
module that landed after STORAGE-1 (Lab evidence 2026-07-10, SATUSEHAT
2026-07-17, LegacyRme 2026-08-19) was never audited against it.

## 2. Containment (executed first, before any code)

Smallest reversible change that stops unauthenticated access, with no file
mutation:

- `location ^~ /storage/ { deny all; }` added to the canonical HTTPS server.
- Chosen over per-directory rules because **all three** top-level directories on
  that disk are clinical; one rule is both smaller and gap-free.
- Backup: `/etc/nginx/sites-available/asia-dental-lab.bak-storage-incident-20260823-151718`
- Reversal: delete the marked block, `nginx -t`, reload.

Verified after the graceful reload settled: the exact path that previously
returned 200 returns **403**, all three real artifact categories return **403**,
all **55 files intact**, `/login` `/health/live` `/health/ready` all **200**.

> Operational note: `systemctl reload nginx` is graceful. A request issued
> immediately after the reload can still be served by an old worker running the
> previous config. The first verification round produced a false 200 for exactly
> this reason. Always re-verify after the workers cycle.

## 3. Storage inventory

| Artifact | Sensitivity | Disk before | Disk after | Persistent | Access model after | Object-ready |
|---|---|---|---|---|---|---|
| RME handwriting (page 1) | Clinical | `public` ⚠️ | `clinical_evidence` | Yes | Route + `MedicalRecordPolicy::view` | Yes |
| RME handwriting (page 2+) | Clinical | `public` ⚠️ | `clinical_evidence` | Yes | Route + `MedicalRecordPolicy::view` | Yes |
| Prescription canvas | Clinical | `public` ⚠️ | `clinical_evidence` | Yes | Route + `RmePrescriptionPolicy::view` | Yes |
| Doctor signature canvas | Clinical | `public` ⚠️ | `clinical_evidence` | Yes | Route + `RmePrescriptionPolicy::view` | Yes |
| Lab order attachment | Clinical | `public` ⚠️ | `clinical_evidence` | Yes | No read route exists | Yes |
| Proof-of-delivery photo | Patient-linked | `public` ⚠️ | `clinical_evidence` | Yes | No read route exists | Yes |
| Consent signature | Clinical | private (`local`) ✓ | unchanged | Yes | Route + policy | Yes |
| Lab workflow evidence | Clinical | private ✓ | unchanged | Yes | Route + policy | Yes |
| Legacy RME PDF | Clinical | private ✓ | unchanged | Yes | Route + policy | Yes |
| Legacy odontogram | Clinical | private ✓ | unchanged | Yes | Route + policy | Yes |
| Patient documents | Clinical | private ✓ | unchanged | Yes | Route + policy | Yes |
| Logs, cache, backups, evidence packs | Operational | local | unchanged | Yes | Not web-served | Out of scope |

`storeAs($dir, $name, 'public')` was the form that hid two of these writers from
an initial `disk('public')` grep. The governance test now matches both forms.

## 4. Permanent fix

- **`clinical_evidence` disk** — local, `visibility: private`, `serve: false`,
  `throw: true`, root `storage/app/clinical-evidence-private`, deliberately not
  in `filesystems.links`.
- **`ClinicalEvidenceStorage`** — single storage authority. Call sites ask it for
  the disk; they never name one. One symbol for governance to assert against.
- **Authorized read paths** —
  `rme.handwritings.image`, `rme.handwriting-pages.image`,
  `rme.prescriptions.canvas`. Thin controllers mirroring the established
  `LabWorkflowEvidenceController@show` pattern: authorize, then stream. The
  filesystem key is never returned to the client.
- **Print/PDF** — `orderedHandwritingPages(true)` embeds inline base64.
  `print-body` is shared by the browser print view *and* the dompdf export, and
  dompdf cannot present a session cookie, so a linked image would have exported
  blank. One code path avoids that class of silent failure entirely.
- **Object keys preserved verbatim**, so the migration rewrites **no database
  column**. A migration that never touches the database cannot corrupt a
  clinical reference.

## 5. Migration

`php artisan clinical-evidence:migrate-public`

Two phases, deliberately separate:

1. `--apply` — copy, then verify by SHA-256. **Source untouched.**
2. `--purge-source` — re-verify, then delete the source.

Nothing is deleted before a byte-identical copy is proven to exist. Every phase
writes a manifest (key, size, source and target checksums, outcome) to
`storage/app/clinical-evidence-migration/` — that manifest is the rollback
evidence. The command also verifies that every stored database reference
resolves on the private disk, counting inline data-URI rows separately since
those never touched the filesystem.

## 6. Durable rules

- **STORAGE-R006** — clinical evidence never on a publicly served disk;
  private disk + authenticated, policy-gated read only.
- Object key possession is not authorization.
- A storage disk is a data authority, not a best-effort cache: no silent
  fallback to another disk.
- The `public` disk is for non-sensitive assets only. Any patient-linked
  artifact belongs on `clinical_evidence`.

## 7. Out of scope

STORAGE-1's object-storage readiness scaffold is untouched and still OFF.
Production remains on local storage. Moving any of this to S3-compatible object
storage remains a separate, separately-authorized sprint.
