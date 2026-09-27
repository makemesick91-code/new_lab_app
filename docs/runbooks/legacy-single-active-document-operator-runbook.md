# Operator runbook — one active legacy document per patient

Who this is for: operators uploading legacy archives under
**Master Data → Master Data RME → Impor Arsip RME Lama** and
**Master Data → Master Data RME → Arsip Odontogram Lama**.

Governing rule: `.cursor/rules/170-legacy-single-active-document-per-patient.mdc`.

---

## The rule in one line

**One patient may have one legacy RME archive and one legacy odontogram archive.**
They are counted separately.

---

## Legacy RME

Check the patient card on the upload screen before preparing a scan. It states the
slot status.

### Status: nothing yet

Upload normally. Nothing here changes the existing date, branch or document rules.

### Status: "Sedang diproses (…)" — an upload is still in flight

**Do not upload a second document.** The patient already has a legacy RME
lifecycle that has not finished.

Open it from **Impor Arsip RME Lama** and do one of:

| If the existing import is | Do |
|---|---|
| waiting for rendering / still processing | wait for it to finish |
| `FAILED` | **Coba Lagi** (retry) — it is retryable, not dead |
| ready for review | review it, then publish it |
| genuinely wrong or unwanted | **Batalkan** (cancel) it |

Cancelling releases the slot. A new upload becomes possible immediately.

> **Do not try to VOID an in-flight import.** VOID only applies to a *published*
> archive. The system will refuse it, and the screen will never offer it.

### Status: "Sudah dipublikasikan" — a published archive exists

**A new upload is not allowed, and will be refused by the server.**

If the published document is correct: there is nothing to do. The patient's
legacy RME is complete.

If the published document is **wrong** (wrong patient, wrong document, wrong
pages), the correction is a two-step procedure and requires the authorized role:

1. An operator with `void_legacy_rme_records` opens the published record and
   performs **VOID**, with a written reason.
2. The slot is released. Upload the correct document as a **fresh import**.

The voided record is **retained** as evidence and remains visible in the
patient's history. It is not deleted, and the new import does not overwrite it.

> Never ask for a published archive to be deleted from the database to make room
> for a replacement. VOID is the supported path and the only one.

---

## Legacy Odontogram

Exactly the same, against the odontogram archive:

* nothing yet → upload;
* in flight → finish, retry or cancel that import;
* published → authorized **VOID**, then a fresh upload.

---

## The two are independent

This is the part most often misread:

* A patient with a **published legacy RME** can still receive their **first legacy
  odontogram**. Upload it normally.
* A patient with a **published legacy odontogram** can still receive their **first
  legacy RME**.
* A patient may legitimately have one of each at the same time.

Being blocked on one type says nothing about the other. Each upload screen states
this explicitly.

---

## Messages you may see, and what they mean

| Message begins | Meaning | Your next action |
|---|---|---|
| "Legacy RME sudah tersedia…" | published archive exists | VOID (authorized role) then re-upload, or stop |
| "Upload Legacy RME sedang diproses…" | import still in flight | finish, retry or cancel it |
| "Legacy Odontogram sudah tersedia…" | published chart exists | VOID (authorized role) then re-upload, or stop |
| "Upload Legacy Odontogram sedang diproses…" | import still in flight | finish, retry or cancel it |
| "Dokumen ini sudah pernah diunggah…" (duplicate) | **same file** already staged or published | you are re-uploading the same scan; check which document you meant |

The duplicate message and the slot messages are different checks. A duplicate means
*this exact file* was seen before — possibly for another patient, which is the case
that matters most. A slot message means *this patient already has a lifecycle*,
whatever the file.

---

## Two things that will not release a slot

1. **Retrying** an import does not consume or release anything — it continues the
   same lifecycle. It is always allowed and is the right response to `FAILED`.
2. **Deleting** rows at the database level. Occupancy deliberately counts
   soft-deleted staging rows, so a delete cannot quietly open a slot. Use CANCEL or
   VOID, both of which are audited.

---

## Escalation

If the screen says a slot is held but you cannot find the blocking import or
record, the refusal message and the audit trail carry the blocking row id. Give
that id to the migration owner. The audit events are:

* `LEGACY_RME_NEW_UPLOAD_BLOCKED_ALREADY_PUBLISHED`
* `LEGACY_RME_NEW_UPLOAD_BLOCKED_ACTIVE_IMPORT`
* `LEGACY_ODONTOGRAM_NEW_UPLOAD_BLOCKED_ALREADY_PUBLISHED`
* `LEGACY_ODONTOGRAM_NEW_UPLOAD_BLOCKED_ACTIVE_IMPORT`

They record the reason and the blocking ids, and deliberately contain no patient
name, Nomor RM or clinical content.
