# Cabang Sunu — Legacy Migration Operator Checklist

**For the Sunu front-office operator (Admin Sunu) migrating the paper estate.**
Covers all three legacy import types. Read once before the first batch; then
use the per-type sections as a working checklist.

Authority: REVISION-SUNU-LEGACY-IMPORT-UNLIMITED-ADMIN-ACCESS-1.

---

## What changed for you

**There is no longer a daily limit of 100 records.** Previously each import
type stopped at 100 records per day and you had to wait until the next day.
That limit is gone for Legacy Pasien, Legacy RME and Legacy Odontogram. You can
work through the whole estate at your own pace.

**Everything else is exactly as before.** Every check that protected the data
still runs, and will still stop you when something is wrong:

- you can only import for **Cabang Sunu**
- the patient's Nomor RM still decides which branch a document belongs to
- duplicates are still detected and refused
- dates are still validated against the patient's existing records
- file type, size and page limits still apply
- someone else still reviews and publishes — uploading is not publishing

If an import is refused, it is refused for a **real reason**. Read the message;
do not retry the same file unchanged.

---

## Before you start a session

1. Log in as Admin Sunu. Confirm the branch shown is **Cabang Sunu**.
2. Open **Legacy** in the sidebar. You should see three entries:
   - Upload Legacy Pasien
   - Upload Legacy RME
   - Upload Legacy Odontogram
3. If a surface is missing or refuses you, **stop and report it** — do not try
   another account.

---

## A. Legacy Pasien (patient master data, CSV)

1. Prepare the CSV from the source records.
2. Check the file is **5 MB or smaller**. Split it if it is larger — this is a
   file-size rail, not a daily quota, so the next part can be uploaded
   immediately.
3. Upload. The file goes to **staging** first; nothing is written to the
   patient master yet.
   > A batch commits **all or nothing**. Smaller files therefore mean smaller
   > commits and easier recovery: if one row is bad, you re-cut one small file
   > rather than re-running the whole estate. Aim for a few hundred rows per
   > file even though nothing forces you to.
4. Review the preview. Check especially:
   - rows flagged as **duplicates** (matching an existing patient)
   - rows flagged with warnings
5. Resolve or exclude flagged rows. **Do not invent an identity number to make
   a row pass** — see "Patients without a NIK" below.
6. Commit. Only now are patients created.
7. Confirm the imported count matches what you expected.

Repeat immediately with the next file. There is no daily cap.

### Patients without a NIK

Minors and some older records genuinely have no NIK.

- If a real number exists on a KK or KIA, use the real number.
- If there is genuinely none, **leave the Nomor KTP column empty**. That is
  fully supported: a blank value is stored as "none recorded", the row imports
  normally, and no identity collision check is run against it. There is no
  special code or placeholder to enter — empty is the correct entry.
- **Never** enter an all-zeros or all-nines filler, or any other made-up
  identity number, to get a row past validation. Nothing requires it, and a
  fabricated identity number is worse than a blank one: it will collide with
  another patient later and is very hard to unpick.

Because a blank row cannot be duplicate-checked by identity number, those rows
are matched on the medical record number instead, and a matching name and date
of birth is raised as a warning for you to judge. **Read those warnings** — for
no-NIK patients they are the only duplicate signal there is.

If a patient genuinely cannot be imported without a fabricated NIK, **stop and
report it** as a product gap rather than working around it.

---

## B. Legacy RME (scanned medical record PDFs)

1. Confirm the patient **already exists** in the system (import them via
   section A first if not).
2. Verify the **Nomor RM** on the document matches the patient. The branch is
   derived from the RM — it is not something you choose, and a mismatched RM
   will be refused.
3. Read the document and identify the dates it actually covers:
   - **earliest** date shown → the record's start
   - **latest** date shown → the record's end
   These are read by a human from the document. Do not guess, and do not use
   today's date or the scan date.
4. If the patient already has native (in-system) RME, the legacy dates must all
   fall **before** their earliest native record. An overlap is refused.
5. Upload the PDF. It is accepted into **staging** and queued for page
   rendering.
6. Wait for rendering to finish. If the queue is busy you may be told to wait —
   that is **render capacity**, not a daily quota. Continue shortly after.
7. The reviewer checks the rendered pages, then **publishes**. You do not
   publish; that separation is deliberate.
8. Confirm the record appears in the patient's RME history.

### If a document was published wrongly

Corrections are made by **VOID plus a fresh import**, with a reason recorded.
Nothing is edited in place and nothing is deleted. Raise it with the reviewer.

---

## C. Legacy Odontogram (scanned odontogram charts)

1. Confirm the patient exists and the Nomor RM is correct.
2. Check the PDF is within the size and page limits (20 MB, 50 pages).
3. Upload. It is staged and queued for rendering, like Legacy RME.
4. The reviewer checks and publishes.
5. Confirm the chart appears in the patient's odontogram history as a
   **read-only legacy entry**. It does not become an editable native
   odontogram, and it does not create a visit, invoice or lab order.

---

## Working in batches — recommended, not enforced

There is no daily cap. There is also no longer a daily counter on the hub page:
with no ceiling declared, the hub shows "no limit" rather than a countdown.

So set your own batch size — **25 to 50 documents** is a practical unit — and
after each batch:

- confirm the expected number of records actually arrived
- clear any refusals before starting the next batch
- for RME and Odontogram, let the render queue drain

This is for **your** visibility, not a system restriction. Batching makes a
mistake cost one batch instead of an afternoon.

---

## When to stop and ask

Stop and report rather than working around any of these:

- a legacy upload surface is missing, or refuses Admin Sunu
- the branch shown is not Cabang Sunu
- a document's correct branch is not Sunu
- a patient cannot be imported without inventing a NIK
- the same file is refused twice and the message is not clear
- rendering never completes for a document
- anything already published looks wrong

---

## For whoever supports this migration

Run these **as the runtime user `daengtisiams`**, on the VPS. Run as any other
user and the disk probe reports UNKNOWN and the whole check reads NO_GO for a
reason that is not real.

```bash
cd /var/www/asia-dental-lab-v2
runuser -u daengtisiams -- php artisan legacy-rme:rollout-readiness --expect=on
runuser -u daengtisiams -- php artisan legacy-rme:ops-readiness
```

### Verifying there is no business quota (read-only)

No new tooling was added for this: Laravel's own `config:show` reports the
effective, cache-aware value. All three must read **null**.

```bash
runuser -u daengtisiams -- php artisan config:show legacy_import_hub \
  | grep -A2 daily_limit
```

```
daily_limit ⇁ legacy_patient ..... null
daily_limit ⇁ legacy_rme ......... null
daily_limit ⇁ legacy_odontogram .. null
```

`null` means no ceiling. A number there means a ceiling **is** in force — check
the environment file for a `LEGACY_IMPORT_*_DAILY_LIMIT` override.

Do **not** prove "unlimited" by uploading hundreds of test documents. The
config value is the policy; fake records in a clinical database are not
evidence, they are cleanup.

### Reimposing a ceiling

No deploy needed — declare an integer in the environment file and clear the
config cache:

```
LEGACY_IMPORT_HUB_DAILY_LIMIT=100
```
