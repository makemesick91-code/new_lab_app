# Legacy RME Routine Batch — Evidence Record (template)

Copy this file per batch. Fill every field. Leave a field **`NOT AVAILABLE`**
rather than guessing — an invented number is worse than a gap.

> **PII POLICY — NOT NEGOTIABLE.** Counts, branch codes, batch codes, statuses,
> account ids and timings only. **Never** a patient name, a Nomor RM, a KTP/NIK,
> a filename, a document path or clinical content. This record is expected to be
> readable by anyone auditing the migration.
>
> **This record is not a GO tag.** A routine batch produces operational evidence
> and an approval closure. GO tags are reserved for software and governance
> changes that went through CI.

---

## 1. Identity

| Field | Value |
|---|---|
| `BATCH_REFERENCE` | e.g. `LRME-LDK2-20260817-01` |
| `APPROVAL_REFERENCE` | governance ticket / decision id (never a patient) |
| `APPROVAL_DATE` | |
| `BRANCH(ES)` | exact branch codes |
| `PLANNED_START_DATE` | |
| `PLANNED_END_DATE` | |
| `DECLARED_DAILY_QUOTA` | |
| `WITHIN_ROUTINE_ENVELOPE` | `YES` (≤100) / `NO — elevated approval: <ref>` |

## 2. People

| Role | Account id | Human named in the approval |
|---|---|---|
| `MAKER` | | |
| `CHECKER / PUBLISHER` | | |
| `SUPERVISOR` | | |

- [ ] Maker and checker are **two different humans** with two different accounts
- [ ] No shared account was used

> Account separation is enforced by the server. **Human** separation is a
> governance control the application cannot observe — this tick is a human
> attestation, not a system assertion.

## 3. Pre-flight (before opening)

| Field | Value |
|---|---|
| `OPS_READINESS_DECISION` | `GO` / `WATCH` / `NO_GO` |
| `READY_FOR_ROUTINE_BATCH` | `YES` / `NO` |
| `RESTING_STATE_BEFORE` | `AT_REST` / … |
| `STOP_THE_LINE_CODES` | `none` / list |
| `WATCH_FINDINGS` | list, or `none` |
| `BACKUP_TAKEN_AT` | |
| `BACKUP_VERIFIED` | `YES` / `NO` |
| `BACKUP_AGE_AT_OPEN_HOURS` | |
| `DEPLOYMENT_READINESS_DECISION` | from `legacy-rme:rollout-readiness` |
| `RUNTIME_SHA` | deployed commit |

## 4. Source set

| Field | Value |
|---|---|
| `SOURCE_COUNT` | documents in the frozen candidate set |
| `SOURCE_SET_FROZEN_AT` | |
| `SOURCE_HASHES_RECORDED` | `YES` / `NO` (hashes stored with the batch, not here) |

## 5. Outcome

| Field | Value |
|---|---|
| `ACCEPTED` | |
| `REVIEWED` | |
| `PUBLISHED` | |
| `CANCELLED` | |
| `FAILED_UNRESOLVED` | |
| `IN_FLIGHT` | must be `0` at closure |
| `QUOTA_CONSUMED` | |
| `QUOTA_DRIFT` | must be `0` |
| `UNEXPLAINED` | must be `0` |
| `STALE_PROCESSING` | |

> `ACCEPTED = PUBLISHED + CANCELLED + FAILED_UNRESOLVED + IN_FLIGHT`.
> A non-zero remainder is `UNEXPLAINED` and blocks closure.
>
> **A cancelled import still consumes quota** — quota counts what was accepted
> into staging. Do not read `QUOTA_CONSUMED` as a count of published documents.

## 6. Refusals encountered

Refusals are evidence that the controls worked. Record them.

| Refusal code | Count | Resolution |
|---|---|---|
| `SOURCE_RM_NOT_FOUND` | | e.g. escalated to master data |
| `SOURCE_RM_PATIENT_MISMATCH` | | |
| `SOURCE_RM_AMBIGUOUS` | | |
| branch refusal | | |
| duplicate document | | |
| date / native-cutoff refusal | | |
| quota exhausted | | |
| capacity backpressure | | |

- [ ] **No refusal was overridden**
- [ ] No digit was adjusted to make an RM resolve
- [ ] No file was renamed to defeat duplicate detection

## 7. Side effects — archive-only boundary

Legacy migration is archive-only. Each of these must be **0**.

| Domain | Delta | Expected |
|---|---|---|
| `ClinicVisit` | | 0 |
| `MedicalRecord` (native) | | 0 |
| Odontogram | | 0 |
| Prescription | | 0 |
| Invoice | | 0 |
| Payment | | 0 |
| `LabOrder` / candidate | | 0 |
| SATUSEHAT candidate / submission | | 0 |

> **Attribution, not naïve counting.** Clinics may be operating during a batch,
> so a global row-count difference is not evidence of a migration side effect.
> Attribute by batch id, import ids, audit reference, time window and branch.
> Do not blame ordinary clinic traffic on the migration, and do not freeze
> clinics merely to make the arithmetic easy.

Any non-zero value is **STOP-THE-LINE**.

## 8. Incidents

| Field | Value |
|---|---|
| `INCIDENTS` | `none` / list |
| `SEVERITY` | `INFO` / `WARNING` / `BLOCKER` / `INCIDENT` |
| `ADMISSION_PAUSED` | `YES` / `NO` |
| `RECOVERY_PATH` | canonical lifecycle CLI only |
| `SQL_OR_TINKER_USED` | must be `NO` |
| `OWNER_ESCALATED` | |

## 9. Closure

| Field | Value |
|---|---|
| `RECONCILIATION_BALANCED` | `YES` / `NO` |
| `SIGNOFF_NOTE` | the written why |
| `SIGNED_OFF_BY` | account id |
| `SIGNED_OFF_AT` | |
| `CAPABILITY_AFTER` | `OFF` |
| `ADMISSION_AFTER` | `EMPTY` |
| `ACTIVE_BATCH_AFTER` | `NONE` |
| `RESTING_STATE_AFTER` | `AT_REST` |
| `HEALTH_AFTER` | `/login`, `/health/live`, `/health/ready`, `/health/lb` |
| `NEW_APPLICATION_ERRORS` | `0` expected |

## 10. Lessons

Anything that made the batch harder than it should have been. If the runbook or
the checklist was wrong or incomplete, say so here and raise the correction —
that is how both stay true.

| Field | Value |
|---|---|
| `RUNBOOK_GAPS` | |
| `MEASURED_THROUGHPUT` | documents/hour reviewed + published, if measured |
| `SIZING_RECOMMENDATION` | evidence for revising the 25 / 100 defaults |
