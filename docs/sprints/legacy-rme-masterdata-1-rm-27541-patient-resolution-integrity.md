# LEGACY-RME-MASTERDATA-1 — RM 27541 patient resolution investigation & master-data integrity

**Status: COMPLETE — investigation conclusive, classification owner-confirmed, tooling shipped. GO.**

Base `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
(`9c803fe58600bb8fa4e3f212a8f3bf77b32fe042`, exact-match tag
`legacy-rme-sod-1-mandatory-separate-publisher-enforcement-production-activation-go`).

```
FINAL_CLASSIFICATION = DOCUMENT_NOT_ELIGIBLE
```

`ROLL-4-WAVE-2` stays **CLOSED and IMMUTABLE**. `ROLL-4-WAVE-3` stays
**SKIPPED / NOT REQUIRED**. No wave was opened, no import was created, no record
was published, and no patient was created, edited or merged.

---

## The question

Wave-2 rejected one document, `W2-003` (`RM Landak 3.pdf`), because the Nomor RM
printed on it — `27541` — resolved to no patient. That rejection was correct and
is not reopened here. What was never established is **why** production could not
resolve it, and whether the failure pointed at a defect in DaengtisiaMS rather
than at the document.

Three outcomes had to be told apart, because they lead to opposite actions:

| If | then |
| --- | --- |
| the patient is registered but the resolver cannot find them | a defect to fix in code |
| the patient is genuinely unregistered | a human registration, then a future migration |
| the number names no patient at all | a durable disposition, and the document stays out |

---

## Source authority

The frozen source was re-verified before any conclusion was drawn about what it
says.

```
SOURCE_FILE          RM Landak 3.pdf   (original filename, display label only)
RECORDED_SHA256      96c78cc097983275611a5ab96f8ec79d2d9a3d65eb0d58c9e9ffc470335dc05f
RECOMPUTED_SHA256    96c78cc097983275611a5ab96f8ec79d2d9a3d65eb0d58c9e9ffc470335dc05f
SOURCE_HASH_MATCH    YES
SIZE_BYTES           288754        PAGE_COUNT 1
DISK                 legacy_rme_private   (still retained, still private)
```

The file is byte-identical to the artefact Wave-2 reasoned about, so its recorded
reading carries over rather than being re-derived:

```
RAW_RM                  27541
SOURCE_RM_CONFIRMED     YES
SOURCE_RM_CONFIRMED_BY  400 DPI re-render of the No. RM field (Wave-2) +
                        the owner's independent reading, which agreed
```

The document was **not** re-rendered again for this sprint. The digit was already
settled by a human at 400 DPI with an independent second reading on record;
re-rendering would have added no evidentiary value while creating another
temporary copy of a real patient's clinical page.

---

## Production investigation — read-only throughout

`srv1730088`, `APP_ENV=pilot`, `VPS_HEAD=9c803fe`. Every query below was a
`SELECT`. Nothing was inserted, updated, soft-deleted or repaired.

**`27541` exists nowhere.** Not as a whole Nomor RM, not as a manual segment, not
even as a substring — and not only among live patients:

```
EXACT_RAW_MATCHES                 0
SUFFIX_MATCHES  (LIKE '%27541')   0
SUBSTRING_MATCHES                 0
SCOPE                             all 43 mst_patients rows
                                  (21 live + 22 soft-deleted)
```

**Nor in registration history.** The legacy patient import staging carries the
complete paper-era intake, and was scanned across every column that could hold a
number, not just the convenient one:

```
stg_legacy_patient_imports        31 rows
  raw_payload                     0 hits
  normalized_payload              0 hits
  manual_rm_number                0 hits
  generated_medical_record_number 0 hits
  legacy_patient_id               0 hits
sys_audit_logs                    447 rows → 0 hits
```

The LDK2 manual-number neighbourhood is dense — `22541`, `22623`, `22676`,
`22681`, `12020`, `8445`, `7505`, `2242`, `14099`, `13499`, `15231`, `15232`,
`14200`, `14230`, `14823` — and `27541` is in none of it.

**The one-digit neighbour was never bound.**

```
patient 43   DG-LDK2-2026-22541   LDK2   active   not soft-deleted
```

`22541` differs from `27541` in exactly one digit. It was not matched, and could
not have been: neither exact matching nor whole-manual-segment matching relates
the two, and no fuzzy or edit-distance path exists anywhere in the resolution
chain. It is reported by the new audit **only** as an investigative signal,
stamped `bindable => false`.

### Root cause

```
ROOT_CAUSE = No patient with Nomor RM 27541 has ever existed in DaengtisiaMS,
             in any branch, in any state, in any historical import.
             The resolver returned nothing because there is nothing.
CODE_DEFECT_IN_RESOLUTION = NONE
```

The negative is correct. `CrossBranchPatientLookupService` and
`LegacyRmeBranchResolver` behaved exactly as specified.

---

## Classification

```
FINAL_CLASSIFICATION      DOCUMENT_NOT_ELIGIBLE
DECIDED_BY                clinic owner, 2026-08-16
WHY                       The number cannot be tied to any real patient of the
                          clinic. With no canonical identity to attach to, the
                          document has nothing to be archived against.
AUTHORITATIVE_EVIDENCE    Owner determination, on top of a complete negative
                          across patients (incl. soft-deleted), the full legacy
                          registration history and the audit trail.
```

`SOURCE_RM_ERROR_CONFIRMED` was explicitly **not** available: no authoritative
register, amendment or ledger contradicts the printed `27541`, and a one-digit
resemblance to `22541` is not evidence of anything. Inventing that correction to
make the document migratable is precisely what the discrepancy rules forbid.

```
RM_27541_FUTURE_MIGRATION = NOT_ELIGIBLE
```

Recorded here so a future operator does not rediscover this from scratch. Only
new **authoritative external evidence** — a registration register, a signed
amendment, an official patient ledger — may reopen it, and it would then belong
to a fresh, separately approved migration operation. Never to Wave-2.

---

## What shipped

Production could only be interrogated with ad-hoc SQL. `LEGACY-RME-OPS-CLI-1`
closed exactly that gap for import lifecycle actions; this sprint closes it for
the identity question, so the next operator who meets an unresolvable Nomor RM
gets a reviewed, reproducible, PII-bounded answer instead of a psql prompt.

```
php artisan legacy-rme:patient-resolution-audit [--rm=] [--json] [--strict]
```

- `App\Modules\LegacyRme\Services\LegacyRmePatientResolutionAuditService`
- `App\Modules\LegacyRme\Support\LegacyRmePatientResolution` (stable codes)
- `App\Console\Commands\LegacyRmePatientResolutionAuditCommand`

**Identity is exact.** A match is reported only when the stored Nomor RM equals
the input, or the input equals a **whole manual segment**. Matching on the whole
segment — rather than a raw `LIKE '%…'` — is what keeps `2541` from swallowing
`22541`: a shorter number may never stand in for a longer one, because dropping a
significant digit is exactly how a stranger's history gets filed against a real
patient. Rows rejected for that reason are still shown, under
`suffix_crossed_manual_segment`, so the operator sees *why* they were not used.

**Ambiguity is terminal.** `EXACT_AMBIGUOUS` / `SEGMENT_AMBIGUOUS` are `resolved`
but never `bindable`. There is no first-row-wins.

**Soft-deleted rows count.** Existence is asked with `withTrashed()`, the same way
`PatientMedicalRecordNumberService::exists()` asks it — a soft-deleted patient
still owns its Nomor RM, and answering "not found" for one invites a duplicate
registration.

**It changes nothing.** No insert, update, soft delete or repair, and a test pins
that the whole patient table is byte-identical after a full audit run.

**PII policy.** Patient id, canonical Nomor RM, branch code, counts, booleans and
stable codes. Never a name, KTP/NIK, phone, address, birth date or clinical
detail — pinned by a test that fails if a name or KTP reaches the JSON.

---

## Master-data findings

### 1. A live patient whose Nomor RM cannot be parsed

```
patient 23   medical_record_number 77727222   branch_id NULL   active
             4 completed visits (ids 1–4) · 4 medical records · 4 odontograms
             3 lab orders · 1 PAID invoice Rp 8.000.000 · 1 payment
```

`77727222` is not `DG-{KODE_CABANG}-{TAHUN}-{NOMOR}`, so
`PatientMedicalRecordNumberService::parse()` returns null and
`LegacyRmeBranchResolver` fails closed. **This patient can never receive a legacy
archive** until the Nomor RM is corrected. The audit reports it as
`ARCHIVE_BRANCH_UNRESOLVABLE` and `--strict` exits non-zero on it.

The owner has decided this record should be deleted and accepts the loss of the
attached clinical and financial history. That deletion is **authorised but
deliberately NOT executed here**: MASTERDATA-1 may not touch native clinical,
billing or lab rows, and SQL/Tinker repair is not a supported production path. It
needs its own scoped operation with a fresh pre-change backup and a canonical
path. Until then the record stands and the audit keeps flagging it.

### 2. Nothing ties a document's printed Nomor RM to the patient chosen for it

The aborted first Wave-2 attempt staged the `W2-003` document against a patient
whose Nomor RM is not the one printed on it:

```
import  9   RM Landak 3.pdf   sha 96c78cc0…   → patient 46  DG-LDK2-2026-22676   CANCELLED
import 11   RM Landak 4.pdf   sha 92502ebc…   → patient 46  DG-LDK2-2026-22676   CANCELLED
```

Both were withdrawn when that wave was cancelled, and `W2-003` was never
published — the outcome Wave-2 recorded is unchanged. But it happened because
`stg_rme_legacy_imports` has **no column for the Nomor RM printed on the
document**: the operator picks a patient, and nothing compares that choice to the
source. The system therefore cannot verify a binding it cannot see.

Closing this properly means capturing the declared Nomor RM at upload and
asserting it against the selected patient — a schema and workflow change to the
migration write path, which is out of scope for an investigation and belongs to
its own sprint. Recorded as a **product gap**. What *is* detectable today is
shipped: the audit reports distinct source documents bound to the same patient
within one wave, split by published and withdrawn.

```
MULTI_DOCUMENT_PATIENTS  published 0   withdrawn 1 (patient 46, imports 9 & 11)
```

### 3. A correction to a Wave-2 sentence — not to its outcome

Wave-2 states the rejected file's hash "appears in **zero** live imports". It in
fact appears on import 9, which is `CANCELLED` but not soft-deleted
(`imports_trashed = 0`). The substance is unaffected — never published, never
admitted, never substituted — but the wording is imprecise, and the correction is
recorded **here**, in new evidence. Wave-2's document, reconciliation and outcome
are not edited.

---

## Production state

```
SOD                       LEGACY_RME_REQUIRE_SEPARATE_PUBLISHER=true
CAPABILITY                OFF
ADMISSION                 EMPTY
ACTIVE_WAVE               NONE
jobs 0   failed_jobs 0
```

Side-effect baseline, unchanged end to end:

```
patients_live 21   patients_all 43
visits 27   medical_records 27   odontograms 27
invoices 19   payments 27   lab_orders 13   satusehat 1
legacy_imports 15   legacy_records 10   (9 PUBLISHED + 1 VOID)

PATIENT_DELTA 0   LEGACY_IMPORT_DELTA 0   LEGACY_RECORD_DELTA 0
NATIVE_CLINICAL_DELTA 0   BILLING_DELTA 0   LAB_DELTA 0   SATUSEHAT_DELTA 0
```

---

## Durable rules

1. Legacy RME patient resolution requires **exact** canonical patient identity.
2. Fuzzy or edit-distance similarity is an **investigative signal only** and never
   identity authority. A one-digit-similar Nomor RM is never auto-bound.
3. Normalization may trim and normalize syntax. It may **never** change, drop,
   add or guess a significant digit — a shorter number must not match a longer
   manual segment.
4. Unknown resolution fails closed. Ambiguous resolution fails closed; there is no
   first-row-wins.
5. Existence is asked including soft-deleted patients — a soft-deleted row still
   owns its Nomor RM.
6. Branch authority stays derived from the patient's Nomor RM and is never
   overridden manually to force a match.
7. Historical source documents are immutable evidence. Correcting a source
   requires authoritative external evidence plus an audit trail.
8. **Master-data repair and Legacy RME migration are separate operations.** Fixing
   or creating a patient never authorises importing a document.
9. A missing patient is created only through canonical human-authorised
   registration. No SQL/Tinker patient or Nomor RM repair is a supported
   production path.
10. Wave evidence is never rewritten after a later master-data correction.
11. `RM 27541` is `DOCUMENT_NOT_ELIGIBLE`; reopening requires new authoritative
    external evidence and a fresh migration approval.
12. `ROLL-4-WAVE-3` remains `SKIPPED / NOT REQUIRED`.
