# FULL-PATIENT-ESTATE-RESET-1 — Operator Runbook

**Type:** one-time, owner-authorized production data reset
**Audience:** a human operator with production database access
**Authorized by:** project owner — scope correction and decisions, 2026-09-23 / 2026-09-24
**Manifest measured:** 2026-09-23, re-measured 2026-09-24 (WITA), read-only
**Revision:** 2 — one frozen value corrected (see section 3), owner re-authorized
**Status:** documentation only — awaiting human execution

---

## 1. What this is, and what it is not

Removes the entire **fictional pre-go-live patient estate** — 50 patient records and
every patient-owned dependency — so the clinic opens with an empty patient database and
the next patient created is the first real one.

> **FULL PATIENT WIPE is not a FULL DATABASE WIPE.**
> Acceptance is: **patient domain empty, non-patient system domain unchanged.**

This document is **documentation only**. The SQL below is printed for a human to run
deliberately. It must never be wired into a deploy hook, migration, seeder, CI job,
scheduled task or application startup path. Automating it is out of scope and not
authorized. **Merging the pull request that adds this document must never execute the
wipe** — the document is inert by construction.

### What is removed, and what is deliberately kept

**Removed:** the 50 patients and every patient-owned dependency, including 24 in-app
lab-workflow notifications whose payloads carry patient identifiers and deep-link to
lab orders being deleted. Notification selection is **scoped by proof** — see section 3
— and never a blanket table delete, so unrelated user notifications are untouched.

**Kept:** `PRESERVED_SATUSEHAT_AUDIT_ROWS = 43`. These are audit evidence, carry no
foreign-key obligation, and must not be deleted or rewritten to make the database look
empty. **Their survival is not a failure of "patient domain empty"** — the audit domain
is intentionally retained. Any future anonymization or retention cleanup of audit
evidence is a separate owner-approved workstream.

Also kept: 12 pre-existing unreferenced storage orphans (section 5).

### Not in scope — never modify while running this

Users, employees, doctors, roles, permissions, branches, rooms, schedules, the device
registry, WebAuthn credentials, doctor-device authorizations, Front Office branch and
device lock configuration, inventory and product and supplier masters, treatment and
service masters, price lists, payment-method masters, lab master and configuration data,
SATUSEHAT configuration, feature flags, queue configuration, migrations, application
source, CI/CD, security configuration.

### Forbidden commands

`migrate:fresh` · `db:wipe` · `DROP DATABASE` · `DROP SCHEMA` · `TRUNCATE ... CASCADE` ·
global foreign-key disabling · `RESTART IDENTITY` · any sequence reset.

Identifier sequences are **not** reset. Non-patient history may depend on monotonic ids.

---

## 2. Preconditions

| # | Check | Requirement |
|---|---|---|
| 1 | Production host | matches the known production host |
| 2 | Application directory | `/var/www/asia-dental-lab-v2` |
| 3 | Application SHA | unchanged from the SHA the manifest was measured against |
| 4 | Database | `asia_dental_lab_pilot`, PostgreSQL 16.x |
| 5 | Patient count | **exactly 50** |
| 6 | Backups | fresh, verified, readable (section 4) |
| 7 | Operator confirmation | explicit, recorded, before the destructive step |

```bash
hostname
cd /var/www/asia-dental-lab-v2
git rev-parse HEAD
git describe --tags --exact-match HEAD
```

Database credentials are entered interactively by the operator, read from the
application environment file in the app root. They are never echoed, never written to
shell history, and never recorded in this document.

```bash
cd /var/www/asia-dental-lab-v2
# Load DB credentials for this shell only. Read them from the application
# environment file in the app root. Entered interactively so they never land in
# shell history, in a log, or in this document.
read -rp  'DB user: '     DBU;        echo
read -rsp 'DB password: ' PGPASSWORD; echo
export PGPASSWORD
psql -h 127.0.0.1 -p 5432 -U "$DBU" -d asia_dental_lab_pilot -c "SELECT current_database();"
```

**STOP** if any precondition fails.

---

## 3. Frozen manifest

Re-measured 2026-09-24. **One frozen value was wrong and is corrected here.** The
correction history is deliberately retained rather than rewritten.

> **CORRECTION — `trx_lab_case_candidates` was 3, is 11. Total was 777, is 785.**
>
> Root cause: *the Sunu-scoped candidate count was mistakenly carried into the later
> all-patient manifest.* It was first measured with a `WHERE patient_id IN (…)` clause
> covering only the five Sunu candidates, and that scoped result was reused as a global
> count once scope expanded to all 50 patients. Every other table came from an unscoped
> `COUNT(*)`; this one alone did not.
>
> **This is a count correction, not a scope expansion.** All 11 rows were created
> between 2026-06-11 and 2026-09-19 — the newest predates the freeze — so no production
> data changed. All 11 belong to patients already inside the authorized 50-patient
> estate. No new patient, branch, table or ownership class enters scope.
>
> **785 is the authoritative total. 777 must not be retained anywhere as an active
> execution control.** The other 50 tables re-measured exactly.
>
> The re-measure gate is what caught this. It is the reason the gate exists.

```
EXPECTED_PATIENT_COUNT         = 50
EXPECTED_TOTAL_DB_ROWS         = 785   across 51 tables
EXPECTED_TABLE_COUNT           = 51
EXPECTED_NOTIFICATION_ROWS     = 24    scoped by proof
EXPECTED_DELETED_FILE_COUNT    = 146
EXPECTED_ABSENT_FILE_REFS      = 20    (rows whose file is already gone — not a failure)
PRE_EXISTING_STORAGE_ORPHANS   = 12    EXCLUDED — never deleted
PRESERVED_SATUSEHAT_AUDIT_ROWS = 43    PRESERVED — never deleted
ARCHIVE_FILE_COUNT             = 158
```

Per table, in mandatory delete order. Notifications are deleted **first**, while the
lab estate still exists, because the selection predicate resolves against it; the
script snapshots the in-scope lab order ids up front so the predicate is
order-independent and auditable.

| # | Table | Rows | # | Table | Rows |
|---|---|---|---|---|---|
| 0 | notifications (**scoped**) | 24 | | | |
| 1 | sys_attachments | 21 | 26 | trx_rme_invoices | 32 |
| 2 | trx_lab_work_logs | 10 | 27 | trx_lab_orders | 13 |
| 3 | trx_lab_qc_checklists | 45 | 28 | trx_rme_prescription_whatsapp_deliveries | 0 |
| 4 | trx_lab_remake_requests | 0 | 29 | trx_rme_prescriptions | 6 |
| 5 | trx_lab_quality_controls | 6 | 30 | trx_rme_visit_consents | 9 |
| 6 | trx_lab_order_assignments | 6 | 31 | trx_medical_record_handwriting_pages | 6 |
| 7 | trx_lab_production_steps | 36 | 32 | trx_medical_record_handwritings | 36 |
| 8 | trx_lab_order_status_logs | 100 | 33 | trx_medical_record_diagnoses | 0 |
| 9 | trx_lab_order_items | 15 | 34 | trx_diagnosis_requirement_overrides | 0 |
| 10 | trx_lab_model_analyses | 2 | 35 | trx_odontograms | 40 |
| 11 | trx_lab_external_dispatches | 0 | 36 | trx_medical_records | 39 |
| 12 | trx_lab_pickup_tasks | 2 | 37 | trx_rme_patient_doctor_assignments | 15 |
| 13 | trx_lab_delivery_tasks | 1 | 38 | trx_clinic_visits | 41 |
| 14 | trx_lab_deliveries | 6 | 39 | trx_rme_legacy_record_pages | 12 |
| 15 | trx_lab_workflow_evidence | 10 | 40 | trx_rme_legacy_records | 10 |
| 16 | trx_payments | 2 | 41 | stg_rme_legacy_import_pages | 17 |
| 17 | trx_invoice_items | 3 | 42 | stg_rme_legacy_imports | 15 |
| 18 | trx_invoices | 3 | 43 | trx_odontogram_legacy_record_pages | 1 |
| 19 | trx_satusehat_submission_items | 0 | 44 | trx_odontogram_legacy_records | 1 |
| 20 | trx_satusehat_data_quality_issues | 0 | 45 | stg_odontogram_legacy_import_pages | 1 |
| 21 | trx_satusehat_candidates | 14 | 46 | stg_odontogram_legacy_imports | 1 |
| 22 | trx_lab_case_candidates | 11 | 47 | mst_patient_documents | 0 |
| 23 | trx_rme_payments | 41 | 48 | mst_patients | 50 |
| 24 | trx_rme_receivable_follow_ups | 1 | 49 | stg_legacy_patient_imports | 31 |
| 25 | trx_rme_invoice_items | 39 | 50 | stg_legacy_patient_import_batches | 11 |

**TOTAL = 785** — 761 patient-domain rows plus 24 scoped notifications, across 51 tables.

Ordering notes:

- 43 of the foreign keys in this domain are `RESTRICT` (a wrong order aborts, which is
  safe) and 17 are `CASCADE` (a wrong order silently takes children). The order above is
  derived from the live constraint graph and must not be rearranged.
- `mst_patients.import_batch_id` references `stg_legacy_patient_import_batches`, so
  patients (48) are deleted **before** the batches (50).
- `sys_attachments` is polymorphic with **no foreign key**, so it is invisible to a
  constraint-graph walk. It is deleted first, explicitly.

### Ownership proofs recorded during the audit

| Domain | Question | Result |
|---|---|---|
| Lab billing | are `trx_invoices` exclusively patient-derived? | yes — each of the 3 has exactly one item and every item references a lab order; all lab orders are patient-owned |
| Lab billing | are the 2 `trx_payments` in scope? | yes — all reference those 3 invoices |
| Attachments | any `sys_attachments` row outside the estate? | none — all 21 are lab-delivery or lab-order attachments |
| Import staging | any import referencing a patient outside the estate? | none |
| Import staging | any import outside the selected batches? | none |
| Import staging | any empty or mixed batch? | none |
| Import staging | every batch has one canonical file? | yes — 11 batches, 11 distinct paths, 11 files, no extras |
| Notifications | any row of an unrelated type? | none — 24 rows, one type, all lab-workflow |
| Notifications | any row missing a lab-order payload key? | none |
| Notifications | any row resolving outside the frozen lab estate? | none — all 24 resolve in |

Every one of these is **re-asserted inside the transaction** and aborts it on breach.

The notification predicate deletes only rows resolving into the lab-order snapshot. If
the total is not exactly 24, or any row lacks the payload key, or any row resolves
outside the estate, the transaction aborts and the operator returns to the owner. An
unrelated user notification arriving between the freeze and execution must never be
swept up — which is why this is a scoped predicate and not a table-wide delete.

---

## 4. Backup — mandatory, verified before anything destructive

```bash
cd /var/www/asia-dental-lab-v2
# Load DB credentials for this shell only. Read them from the application
# environment file in the app root. Entered interactively so they never land in
# shell history, in a log, or in this document.
read -rp  'DB user: '     DBU;        echo
read -rsp 'DB password: ' PGPASSWORD; echo
export PGPASSWORD
TS=$(TZ=Asia/Makassar date +%Y%m%d-%H%M%S)
B=storage/app/backups/manual/pre_full_patient_estate_wipe_${TS}
mkdir -p "$B"

pg_dump -h 127.0.0.1 -p 5432 -U "$DBU" -d asia_dental_lab_pilot -Fc -f "$B/full_db.dump"

cd storage/app
tar czf "backups/manual/pre_full_patient_estate_wipe_${TS}/patient_files.tar.gz" \
  legacy-rme-private legacy-odontogram-private clinical-evidence-private \
  private/rme-consents private/lab-workflow-evidence private/legacy-patient-imports
find legacy-rme-private legacy-odontogram-private clinical-evidence-private \
     private/rme-consents private/lab-workflow-evidence private/legacy-patient-imports \
     -type f -exec sha256sum {} \; | sort -k2 \
     > "/var/www/asia-dental-lab-v2/$B/file_sha256.txt"
cd /var/www/asia-dental-lab-v2
(cd "$B" && sha256sum full_db.dump patient_files.tar.gz file_sha256.txt > SHA256SUMS)
echo "BACKUP=$B"
```

Validate — **all four must pass**:

```bash
pg_restore -l "$B/full_db.dump" | wc -l              # non-trivial TOC
(cd "$B" && sha256sum -c SHA256SUMS)                 # all OK, 0 FAILED
stat -c '%s' "$B/full_db.dump"                       # plausible size
tar tzf "$B/patient_files.tar.gz" | grep -vc '/$'    # >= deletion manifest count
```

The archive deliberately covers **more** than the deletion manifest — it includes the 12
preserved orphans — so backup coverage is always a superset of what is removed.

A backup command returning exit 0 is **not** evidence. Only the four checks above are.

---

## 5. Build the deletion file manifest — from the database, before COMMIT

The database is the only deletion authority. **Directory enumeration must never be used
to decide what to delete.** This is what keeps the 12 pre-existing orphans safe.

Produce `/root/delete_files.txt` by unioning every canonical file-path column in the
patient domain: handwritings and handwriting pages, prescription canvas and signature,
consent signatures, lab workflow evidence, legacy-patient-import batch files, polymorphic
attachments, legacy RME source PDFs plus page and thumbnail images, and the legacy
odontogram equivalents — each prefixed with its storage disk directory.

Then:

```bash
cd /var/www/asia-dental-lab-v2/storage/app
present=0; absent=0
while read -r f; do
  if [ -f "$f" ]; then present=$((present+1)); else absent=$((absent+1)); fi
done < /root/delete_files.txt
echo "present=$present absent=$absent"      # expect present=146 absent=20
```

`absent=20` is expected: `sys_attachments` seed rows whose files were never present.
Deleting a row with no file is not a failure.

**Orphan proof — must be exactly 12:**

```bash
find legacy-rme-private legacy-odontogram-private clinical-evidence-private \
     private/rme-consents private/lab-workflow-evidence private/legacy-patient-imports \
     -type f | sort > /root/on_disk.txt
comm -23 /root/on_disk.txt <(sort /root/delete_files.txt) > /root/orphans.txt
wc -l /root/orphans.txt                     # MUST be 12
```

If it is not 12, **STOP** and report to the owner. These 12 are unreferenced handwriting
images with no ownership path into the authorized estate. They are excluded by owner
decision, are **not** a reset failure, and belong to a future dedicated orphan-storage
audit.

---

## 6. Dry-run

The destructive script is a single `BEGIN` … `COMMIT` transaction that:

1. asserts the database name;
2. asserts the patient count is exactly **50** — any other number aborts with
   *return to owner*, and the number is never silently adapted;
3. snapshots the in-scope lab order ids into a temp table, before anything is deleted;
4. re-asserts every ownership proof from section 3, including the three notification
   checks;
5. deletes the 24 notifications by scoped predicate and asserts the count;
6. for each of the 50 remaining tables in order: prechecks the row count against the
   frozen manifest, deletes, and asserts the deleted count matches;
7. asserts the running total is exactly 785;
8. asserts every one of those tables — and `notifications` — is empty;
9. asserts preserved audit evidence still reads **43**, aborting if it does not;
10. prints a protected-domain drift table that must come back empty.

Run it with `ROLLBACK` appended and `ON_ERROR_STOP=1`. It must report all guards passed,
`TOTAL ROWS DELETED: 785`, patient domain empty, audit evidence preserved at 43, an
empty drift table, and `ROLLBACK`.

Any mismatch aborts the whole transaction. A partially deleted estate is not reachable.

---

## 7. Execute

Only after: a green dry-run, verified backups, the orphan proof returning 12, and
**explicit recorded operator confirmation**.

Re-run the same script with `COMMIT` appended instead of `ROLLBACK`.

---

## 8. Post-delete verification

**Database** — all must be `0`: patients, visits, medical records, odontograms,
consents, prescriptions, lab orders, RME invoices, RME payments, SATUSEHAT candidates,
legacy RME records, legacy odontogram records, attachments, staging imports, staging
batches, **notifications**.

**Preserved — these must NOT be zero:** the SATUSEHAT audit table must read **43** and
the system audit table must be unchanged. `PRESERVED_SATUSEHAT_AUDIT_ROWS = 43` is a
success criterion, not leftover residue. A run that emptied it would be a **failure**,
not a cleaner result.

**Referential integrity** — revalidate every foreign key; PostgreSQL reports any that
would not hold:

```sql
DO $v$
DECLARE r record;
BEGIN
  FOR r IN SELECT conrelid::regclass AS t, conname FROM pg_constraint WHERE contype='f' LOOP
    EXECUTE format('ALTER TABLE %s VALIDATE CONSTRAINT %I', r.t, r.conname);
  END LOOP;
  RAISE NOTICE 'ALL FK CONSTRAINTS VALID';
END $v$;
```

**Files** — delete strictly from `/root/delete_files.txt`, never with a recursive remove
of a storage root:

```bash
cd /var/www/asia-dental-lab-v2/storage/app
while read -r f; do [ -f "$f" ] && rm -f -- "$f"; done < /root/delete_files.txt
while read -r f; do [ -e "$f" ] && echo "REMAINING: $f"; done < /root/delete_files.txt
echo "orphans_present=$(while read -r f; do [ -f "$f" ] && echo x; done < /root/orphans.txt | wc -l)"
find legacy-rme-private legacy-odontogram-private clinical-evidence-private \
     private/rme-consents private/lab-workflow-evidence private/legacy-patient-imports \
     -type d -empty -delete
```

Required invariants: **`UNMANIFESTED_FILES_DELETED = 0`**, zero manifested files
remaining, **12 orphans still present**.

**Protected domain** — counts identical before and after for users, doctors, branches,
rooms, permissions, role and model role assignments, doctor devices, WebAuthn
credentials, device authorizations, doctor-branch links, migrations, and both audit
tables. `notifications` is deliberately **not** in this set — 24 of its rows are in
scope, and it must read 0 afterwards.

Audit logs are preserved. `sys_audit_logs` and the SATUSEHAT audit table have no foreign
key into the patient domain, so references to removed patients simply become historical.
No foreign-key treatment is needed and none is authorized. Do not delete or rewrite
audit rows to make the database appear empty.

**Health** — `/login`, `/health/live`, `/health/ready` and `/health/lb` return 200; zero
pending migrations; PHP-FPM and nginx active; no new application errors; application SHA
unchanged.

**Empty state** — patient list, patient search, visit lists, RME and odontogram views,
the lab operational queue and patient billing all show nothing.

> **Do not create a replacement test patient to prove emptiness.** The next patient
> created must be the first real go-live patient.

---

## 9. Rollback

```bash
cd /var/www/asia-dental-lab-v2
php artisan down
# Load DB credentials for this shell only. Read them from the application
# environment file in the app root. Entered interactively so they never land in
# shell history, in a log, or in this document.
read -rp  'DB user: '     DBU;        echo
read -rsp 'DB password: ' PGPASSWORD; echo
export PGPASSWORD
pg_restore -h 127.0.0.1 -p 5432 -U "$DBU" -d asia_dental_lab_pilot \
  --clean --if-exists --no-owner "$B/full_db.dump"
cd storage/app
tar xzf "/var/www/asia-dental-lab-v2/$B/patient_files.tar.gz"
sha256sum -c "/var/www/asia-dental-lab-v2/$B/file_sha256.txt"
cd /var/www/asia-dental-lab-v2 && php artisan up
```

Then re-run section 8. File deletion is not transactional, which is exactly why the
verified archive is taken first and the file phase runs only after a successful commit.

---

## 10. Pre-execution freeze — must be reported before the destructive step

```
EXPECTED_PATIENT_COUNT         = 50
EXPECTED_TOTAL_DB_ROWS         = 785
EXPECTED_TABLE_COUNT           = 51
EXPECTED_NOTIFICATION_ROWS     = 24
EXPECTED_DELETED_FILE_COUNT    = 146
EXPECTED_ABSENT_FILE_REFS      = 20
PRE_EXISTING_STORAGE_ORPHANS   = 12
PRESERVED_SATUSEHAT_AUDIT_ROWS = 43
```

Plus, captured at freeze time: fresh database backup timestamp and checksum; backup
readability; file archive equality; production application SHA; database identity.

**Every value is re-measured at freeze time. None is carried forward by arithmetic.**

---

## 11. Final status

On success:

```
FULL-PATIENT-ESTATE-RESET-1
COMPLETE / 50 FICTIONAL PATIENTS REMOVED /
PATIENT DOMAIN EMPTY /
PATIENT NOTIFICATION RESIDUE REMOVED /
AUDIT EVIDENCE PRESERVED /
PRE-EXISTING STORAGE ORPHANS PRESERVED /
NON-PATIENT DOMAIN UNCHANGED /
READY FOR FIRST REAL PATIENT
```

Otherwise:

```
FULL-PATIENT-ESTATE-RESET-1
NO-GO / <exact reason>
```

---

## Appendix — decision log and remaining item

**Resolved by the owner on 2026-09-24:**

- **In-app notifications — INCLUDED, scoped.** 24 lab-workflow rows, proven to reference
  only lab orders inside the frozen manifest. Deleted by scoped predicate with a
  fail-closed count assertion; unrelated user notifications are never touched.
- **SATUSEHAT audit rows — PRESERVED.** 43 rows retained as audit evidence. Their
  survival is asserted by the transaction, not merely tolerated.

**Remaining open item:**

- **Pre-existing storage orphans (12).** Unreferenced handwriting files with no
  ownership path into the authorized estate. Excluded by owner decision; not a reset
  failure. Recommend a separate orphan-storage audit after go-live.
