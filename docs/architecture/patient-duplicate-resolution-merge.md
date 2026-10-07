# Patient Duplicate Resolution & Merge (Duplikasi Pasien)

FEATURE-PATIENT-DUPLICATE-RESOLUTION-MERGE-1 · module `app/Modules/PatientMerge`

> **1 manusia → 1 canonical patient → 1 RM aktif → seluruh histori klinis tetap utuh.**
> A patient merge is a reassignment of OWNERSHIP, not a deletion of clinical history.

## 1. Data model (additive only)

| Table / columns | Purpose |
|---|---|
| `trx_patient_merge_cases` | One request to treat two patient rows as one person. Addressed by `uuid` (never a sequential id in a URL). Holds requester/reviewer, status, risk flags, `identity_fingerprint` (sha256 at submission), before/after summaries (counts + masked identity), `moved_records` manifest (table → ids), `identity_snapshot_encrypted` (full pre-merge identity of BOTH patients, encrypted at rest, for supervised reversal only), reversal fields. |
| `trx_patient_merge_field_resolutions` | Field provenance: per field, `comparison_status` (match/conflict/missing), `source` (patient_a / patient_b / manual / matched / empty), the final value **encrypted**, a masked `final_value_display`, `manual_reason`, `resolved_by/at`. UNIQUE(case, field). |
| `mst_patient_rm_aliases` | Old Nomor RM → canonical patient. UNIQUE `alias_medical_record_number`. `source_patient_id` immutable; `canonical_patient_id` follows a later merge of the canonical patient; a reversed merge sets `revoked_at` (never deleted). |
| `mst_patients.merged_into_patient_id / merged_at / merged_by / merge_case_id` | The merged state. All nullable, no backfill (NULL = not merged). Not fillable — written only by the merge and its reversal via `forceFill`. Plain indexes on `date_of_birth` and `phone` support candidate detection. |

## 2. Workflow

```
draft ──submit──▶ pending_review ──approve & merge (ONE transaction)──▶ completed
  │                    │                                                    │
  └─cancel─▶ cancelled └─reject─▶ rejected                     request reversal review
                                                                            ▼
                                        reversed ◀── safe only ── reversal_required
```

`approved` / `merging` exist only inside the merge transaction, so no case can sit approved while the identities it was approved against drift.

1. **Select** — *Pilih Pasien Manual* (two independent server-side searches) or *Deteksi Duplikat* → **Tinjau**.
2. **Create** (`request_patient_merge`) — both patients locked; out-of-scope reads as not found; merged / deleted / same patient / already in an open case refused; cross-branch needs `approve_patient_merge`.
3. **Reconcile** — every field gets a source. MATCH auto-resolves, a non-critical one-sided field carries the present value, every CONFLICT (and a critical one-sided field) needs a human choice. Manual values are validated (NIK 16 digits, date not in future, gender enum…) and require a reason ≥ 10 chars. Critical fields (name, NIK, DOB, gender) can never be emptied.
4. **Choose the canonical patient** — which ROW and Nomor RM stay active. Independent of the final identity values.
5. **Submit** — blockers re-evaluated; identity fingerprint stored.
6. **Approve & merge** (`approve_patient_merge`, never the requester) — see §4.

## 3. Identity rules

- Canonical patient selection does **not** define final identity values; each field is reconciled individually with structured provenance.
- No merge proceeds with an unresolved critical identity conflict (`UNRESOLVED_IDENTITY_CONFLICT`).
- **Patient C collision** — a final NIK already held by any other patient (soft-deleted included) fails closed (`KTP_OWNED_BY_OTHER_PATIENT`), checked at submission AND again under the merge locks. The NIK is never taken from Patient C and the message never says who Patient C is.
- KTP/NIK and phone are masked in every screen, list, audit row and summary. Full values are compared on the server only; the full pre-merge identity exists only in the encrypted snapshot.

## 4. The merge transaction

Inside one `DB::transaction`:

1. lock the case row; status must be `pending_review`; approver ≠ requester (enforced in the service — Super Admin passes every policy via `Gate::before`);
2. lock both patients FOR UPDATE in id order (no deadlock between overlapping merges);
3. re-run every blocker; compare the identity fingerprint (an edit after submission → `IDENTITY_CHANGED_SINCE_SUBMISSION`);
4. count every registered group for both patients + every table total;
5. release the source's NIK slot, then write the reconciled identity onto the canonical patient (re-checking Patient C);
6. close (never delete) a source doctor assignment that duplicates an active canonical one (partial unique index);
7. reassign ownership table by table from `PatientOwnedRecordRegistry` — only the owner column changes;
8. repoint aliases that resolved to the source; create the source's Nomor RM as an alias (re-arming a revoked one);
9. mark the source merged + inactive (row and Nomor RM kept — no future registration can be issued it);
10. **prove conservation**: for every group `canonical_after == canonical_before + source_before`, `source_after == 0`, and every table total unchanged — any mismatch throws and **everything rolls back**;
11. record before/after summaries, the moved-records manifest, the encrypted snapshot, and the audit row.

A refusal (`PatientMergeBlockedException`) is audited **after** the rollback so the trail survives it.

### Patient-owned record registry

`App\Modules\PatientMerge\Support\PatientOwnedRecordRegistry` lists every patient reference and what a merge does with it (all REASSIGN): visits, medical records, RME invoices, RME payments, prescriptions (+ WhatsApp deliveries), visit consents, patient documents, lab case candidates, lab orders, legacy RME records + imports, legacy odontogram records + imports, legacy batch review/publish/mass-upload staging, SATUSEHAT candidates + data-quality issues, doctor assignments. Odontograms, receivable follow-ups and invoice items follow their visit/invoice and are counted through them. `PRESERVE` lists provenance columns deliberately left alone (merge pointers, alias columns, merge-case columns, `stg_legacy_patient_imports.committed_patient_id`).

**Before every merge the registry is checked against the LIVE schema** (PostgreSQL `information_schema`; SQLite foreign-key introspection). A foreign key to `mst_patients` the registry does not know refuses the merge (`OWNERSHIP_REGISTRY_INCOMPLETE`). Adding a patient-owned table therefore requires adding it to the registry — the test `covers every foreign key to mst_patients in the live schema` fails otherwise.

### Domain semantics

- **RME / odontogram** — ownership reassigned; content never merged, overwritten, renumbered or deleted. Both timelines become one under the canonical patient; the RM workspace resolves its canonical anchor dynamically.
- **Billing** — same invoice, same payment, same amount, status and timestamps. Receivables keep following invoice authority.
- **Legacy** — stays legacy, keeps its `origin_branch_id`, source Nomor RM and source import. Two active archives of the same type (one per patient) block the merge (`LEGACY_SLOT_CONFLICT`) — the single-active-document rule is never broken by a merge; an in-flight legacy import blocks too (`LEGACY_IMPORT_IN_FLIGHT`). The operator resolves both through the archive's own flow (VOID / publish / cancel).
- **Cross-branch** — explicit privilege; visit/billing/legacy branch history is never rewritten.

## 5. After the merge

- The source patient is a read-only pointer: edit → redirect to the canonical patient; RME workspace → redirect; list shows "Digabungkan ke …".
- `PatientMergeGuard` refuses new activity on a merged patient in the **services**: visit registration (re-read under FOR SHARE, which queues behind a running merge's FOR UPDATE), patient identity edit/activate/delete, legacy RME and legacy odontogram intake. Merged patients are excluded from the New Visit selector and its submit-time re-authorization.
- **RM alias** — typing an old Nomor RM in the New Visit selector or *Pilih Pasien Manual* returns the canonical patient (same authorization as any other result); the *RM Alias* page lists them.

## 6. Reversal review (no "Undo")

`request` assesses the merge; it is **SAFE** only when provable: the source still points at the canonical patient, the canonical patient was not merged again, nothing was created for the canonical patient since the merge (rows the merge itself moved are excluded by id), and every moved row is still owned by the canonical patient. A safe reversal is executed by a **different** reviewer: exactly the manifest rows move back, closed doctor assignments reopen, aliases are repointed back and the case's alias revoked, both identities restored from the encrypted snapshot (NIK re-checked against third patients). Anything else is **SUPERVISED MANUAL RECONCILIATION** — the system never guesses ownership; the review can be dismissed (merge stands).

## 7. Duplicate prevention at registration

Master patient registration and Kunjungan Baru (pasien baru) check for STRONG matches only — near-identical name (≥ 0.85) on the same birth date, or a similar name (≥ 0.75) on the same phone/WhatsApp. On a match the operator sees a masked summary and can *Gunakan pasien ini*, *Review duplikat*, or continue with a written reason (audited as `PATIENT_DUPLICATE_WARNING_OVERRIDDEN`). Sharing a birthday alone never blocks.

## 8. Candidate detection

Pairs are formed only inside blocks of patients sharing an indexed key (birth date, phone, WhatsApp) — one query per key, capped blocks and pairs — so cost never grows with the square of the patient table and no full-table `LIKE '%name%'` runs. A candidate needs a similar name AND a shared key (or shared birth date AND phone). The score ranks; it is never identity authority, and there is no code path from a score to a merge.

## 9. Permissions & scope

| Permission | Roles | Grants |
|---|---|---|
| `view_patient_duplicate_resolution` | Admin Klinik, Front Office, Supervisor RME | read pages |
| `request_patient_merge` | Admin Klinik, Front Office, Supervisor RME | select, reconcile, submit, withdraw, cancel own |
| `approve_patient_merge` | Supervisor RME | review queue, approve & merge, reject, reversal review, cross-branch cases |

Super Admin via `Gate::before`; Owner, Doctor, Kasir, Perawat, Admin Lab have none. Scope = `RmeWorkingBranchScope` (context-bound operators pinned to their working branch and fail closed; governance roles all active RME branches); every case action also requires BOTH patients in scope. Routes: `patient-merge.*` under `/rme/patient-merge`, permission middleware on every route, policy re-check on every action, `{patientMergeCase}` constrained to UUID.

## 10. Review hardening (security + data-integrity review)

Two adversarial reviews ran before merge; every HIGH and MEDIUM finding was fixed and pinned by `tests/Feature/PatientMerge/PatientMergeHardeningTest.php`.

| Finding | Fix |
|---|---|
| A reviewer could edit another's draft, then approve it | Only the requester resolves/submits/withdraws; every author (requester, submitter, field resolver) is refused approval and rejection in the service |
| Values resolved earlier could be stale at submission | A/B/matched values re-derived from the locked rows at submit |
| Concurrent legacy upload could break the single-active-document rule or land on the source | Merge takes the slot advisory locks before the patient rows; intake re-reads the patient `FOR SHARE` after its slot lock |
| Writers taking `patient_id` from a route-bound visit could write onto the source after the merge | `ACTIVE_ENCOUNTER` blocker: no non-terminal visit on either patient |
| Reversal assessment covered seven tables, by `created_at` only | Registry-driven: id high-water mark, moved rows changed after merge, child rows via parents, a parent-reference consistency probe, deleted patients, post-merge identity edits |
| Legacy patient batch rollback could soft-delete a merged patient | Rollback refuses any batch touching merge involvement |
| Whole-table conservation totals refused a correct merge under concurrent writes; non-blocker failures were not audited | Conservation over the two locked patients; `PATIENT_MERGE_FAILED` audited after rollback |
| SATUSEHAT identifiers are a non-FK patient reference | `SATUSEHAT_IDENTIFIER_PRESENT` blocker on the source |
| Merge could break the legacy-before-native date rule | `LEGACY_DATE_RULE_VIOLATION` blocker |
| Doctor-assignment notes were overwritten | Appended, original kept in the manifest and restored on reversal |
| Registration warning disclosed DOB / phone / NIK fragments outside scope | Out-of-scope matches show name, Nomor RM, branch only |
| Merged-patient RME redirect revealed existence/target | Redirects only when the canonical workspace opens for the actor; else 404 |
| Manual values could exceed registration limits / contain control characters | Registration limits and control-character refusal in the service |
| `x-data` built from string interpolation | `@js()` |

Accepted (documented, not changed): the KTP-collision blocker confirms that *some* other patient holds a given NIK to an actor already allowed to request merges (it never says which); the losing source KTP is cleared, not kept as a searchable historical NIK; `canonical_visit_id` / `sheet_number` on moved medical records are not restamped (no navigation reads them — `PatientRmWorkspaceResolver` derives the anchor live); a SATUSEHAT candidate refreshed for an already-completed visit during a merge can still carry the source id (SATUSEHAT is disabled in production and candidates are re-hashed before any send).
