# LEGACY-RME-OPS-CLI-1 — Canonical Import Lifecycle CLI & Abort-Recovery Operations

**Scope: operator recovery capability. No migration wave was executed by this
workstream, and none is authorized by it.**

---

## 1. The gap this closes

`ROLL-4-WAVE-2` was opened against live clinical data and then aborted. When the
operator went to withdraw the staged documents, they found the application had no
canonical way to do it over SSH: `cancel`, `review`, `publish` and `retry`
existed **only** as `LegacyRmeImportController` methods.

That gap matters because of what an operator reaches for instead:

```
UPDATE stg_rme_legacy_imports SET status = 'CANCELLED' WHERE ...
LegacyRmeImport::find($id)->update(['status' => 'PUBLISHED'])
->update(['published_by' => 1])
-- hand-adjusting a quota bucket
```

Every one of those bypasses the 1A transition map, the server-resolved branch
scope, `LegacyRmeImportPolicy`, the publish-time revalidation of
patient/branch/date, the quota semantics **and** the audit trail — simultaneously
— and leaves clinical evidence asserting something that never happened.

The fix is not "add four commands". It is: **give the terminal the same door the
browser already uses.**

---

## 2. Architecture — one business path, two adapters

```
LegacyRmeImportController          LegacyRmeImportAdminCommand
              │                                  │
              └────────────┬─────────────────────┘
                           ▼
           LegacyRmeImportLifecycleService          ← the ONLY implementation
                           │                          of the gates
        ┌──────────────────┴───────────────────┐
        ▼                                      ▼
LegacyRmeImportProcessingService     LegacyRmePublishService
   (retry, cancel)                     (review, publish)
        │                                      │
        └──────────────► Repository ──────► Model
```

The controller no longer calls `$this->processing->` or `$this->publishing->` at
all. That is asserted structurally by a test, not merely stated here — if a future
change reintroduces a direct call, the suite fails.

### The gate order, and why each one fails closed

| # | Gate | Refusal | Why |
|---|---|---|---|
| 1 | Feature flag (`rme.legacy_pdf_archive`) | `FEATURE_DISABLED` | A recovery tool that worked while the emergency stop was pressed would *be* the bypass this sprint removes. |
| 2 | Branch scope (`LegacyRmeWorkspaceScope`) | `IMPORT_NOT_IN_SCOPE` | An id on a command line is exactly as untrusted as an id in a URL. |
| 3 | Named route permission | `PERMISSION_DENIED` | The same door the HTTP route declares as middleware. |
| 4 | `LegacyRmeImportPolicy` | `POLICY_DENIED` | Branch scope again plus the 1A transition gate. |
| 5 | Separation of duties (config) | `SEPARATION_OF_DUTIES` | Applied to **both** surfaces or neither. |
| 6 | Canonical service | `SERVICE_REFUSED` | Only now does anything get written. |

Gate 2 fires **before** gate 3 on purpose, so an out-of-scope row is invisible
rather than "forbidden" — otherwise id probing would reveal what another branch
has staged.

---

## 3. Command surface

```bash
php artisan legacy-rme:import-admin {cancel|review|publish|retry}
    --import=<id>          # exactly one import; there is NO batch mode
    --actor=<id|email>     # a real, active application account
    [--title= --description=]   # publish only
    [--apply]              # without it: dry run, nothing is written
    [--json]               # machine-readable, PII-minimized
```

**Safe by default, three ways.**

1. **One import at a time.** No `--all`, no `--branch`, no `--wave`, no `--force`
   — asserted by a test. A recovery tool whose blast radius is "every document in
   the wave" is how one wrong command at 2am becomes an incident. An operator who
   means to loop does so in their own shell, and each iteration is separately
   authorized and separately audited.
2. **Dry run unless `--apply`.** Reports whether the action *would* be allowed and
   writes no row, no audit entry and no job — asserted by counting rows before and
   after.
3. **An explicit human actor.** The Linux user, the SSH login and root are never
   an application identity. `--actor` must name an existing, **active**,
   non-soft-deleted account, and its permissions are checked exactly as in the
   browser.

**Exit codes.** `0` only when the requested outcome was reached (or a dry run
found the action eligible). Every refusal exits non-zero, so a wrapper script
cannot mistake one for success. A dry run that finds the action *ineligible* also
exits non-zero — it is a successful report, but not the outcome asked for.

### The dry run predicts, and had to be taught how

`preview()` asks the Gate with `allows()` rather than `authorize()`. For most
accounts that is enough. For a **Super Admin** it is not: the global
`Gate::before` bypass makes every policy answer yes, so the policy predicts
nothing for the single account most likely to be running a 2am recovery — and a
preflight that reported "eligible" for a doomed operation would be worse than no
preflight at all.

The transition map is therefore consulted **directly** as well, producing
`TRANSITION_NOT_ALLOWED`. This is the only blocker derived from state rather than
from an authorization gate, and it exists solely so the dry run cannot lie.
`perform()` deliberately does **not** use it — the canonical services already own
that rule, and duplicating it on the write path is precisely how two sets of rules
appear.

---

## 4. What was found by running it, not by reading it

- **`LegacyRmeAuditService` had to become a container singleton.** The channel tag
  is set by the lifecycle service, but the audit rows are written by the canonical
  services from *their own injected copy*. Without a shared instance the tag was
  set on one object and read from another, and every row silently lost it. The
  state is scoped by `withChannel()` and restored in a `finally`, so a long-lived
  queue worker cannot inherit a stale value — asserted by a test.
- **`Exception::$code` cannot be redeclared readonly.** The command-level refusal
  type carries `refusalCode`, not `code`.
- **A Kasir is refused by the *scope* gate, not the permission gate.** With no
  governance permission their branch set is whatever `BranchContext` resolves, so
  the row is invisible before authorization is asked. Fail-closed twice over —
  and the test pins the branch explicitly, because `BranchContext` otherwise falls
  back to the first active RME-enabled branch and can coincidentally *be* the
  import's branch, passing for the wrong reason.
- **A FormRequest constrains one caller, not the capability.** The security pass
  found the archive labels were bounded only by `PublishLegacyRmeImportRequest`
  (title 150, description 2000). `normalizeTitle()` already capped itself, but
  `normalizeDescription()` did not — so the CLI could have written a description
  the browser would reject: precisely the "HTTP rule A, CLI rule B" drift this
  workstream exists to prevent. Closed on both sides: the command refuses with
  `INVALID_ARCHIVE_LABEL` at the same bounds, and the publish service now bounds
  the description as a backstop (unreachable from HTTP, which validates first).

---

## 5. Semantics preserved verbatim

**Cancel does not refund quota.** The bucket is a reservation taken at intake: it
records that a slot was spent shaping the day's migration, and a withdrawn
document does not un-spend the reviewer time it already cost. A CLI that refunded
it would let an operator loop `upload → cancel → upload` past the ceiling the wave
was approved for. Pinned by a test.

**Review is a real gate.** An unreviewed import cannot be published — by the
policy for a checker, and by the canonical service for a Super Admin whose
`Gate::before` bypasses the policy. Both paths are tested, because the account
that bypasses policies is exactly the one that must not be able to skip review.

**Publish is idempotent, never duplicating.** A repeat returns the record the
import already produced (`changed: false`); `UNIQUE(source_import_id)` is the
database-level backstop underneath.

**Retry means requeue, not reset.** Only canonically retryable states qualify.
`PUBLISHED` and `CANCELLED` are terminal and are refused. A retry whose source PDF
is gone is refused with a reason rather than burning a processing cycle.

**No downstream state, ever.** No `ClinicVisit`, `MedicalRecord`, odontogram,
invoice, payment, `LabOrder` or SATUSEHAT candidate is created by any action, on
either surface — asserted per action, including on refusal paths.

---

## 6. Separation of duties — recorded honestly

The prompt for this workstream assumed `imported_by != published_by` was already
enforced in code. **It was not**, and this document says so rather than implying
otherwise.

**What actually enforces maker/checker today is the ROLE SPLIT.** Wave-1 gave the
maker `create_legacy_rme_imports` and deliberately withheld `review`/`publish`;
the checker got `review` + `publish` and was denied `create`. A maker therefore
cannot publish anything at all, on any surface — the policy refuses first. That
split is the load-bearing control and this sprint does not change it.

**What this sprint adds** is `legacy_rme_operations.require_separate_publisher`
(env `LEGACY_RME_REQUIRE_SEPARATE_PUBLISHER`), mirroring the existing
`require_separate_approver` switch exactly, including its "turn this on once two
staffed accounts exist" posture:

- **Default OFF.** Enabling it changes *browser* behaviour on live clinical data,
  which is the owner's decision rather than a side effect of shipping a CLI. Off,
  both surfaces behave precisely as they do today.
- **On, both tighten together.** Enforcement lives in the shared lifecycle
  service, so there is deliberately no way to have the rule in the browser but not
  over SSH, or the reverse. Tested on both surfaces.
- It closes the one real hole the role split cannot: a **Super Admin**, whose
  `Gate::before` makes every policy answer yes, can hold both duties.

**Recommended follow-up for the owner:** switch it on. It is the only remaining
account-level way a single human could file and certify the same clinical
document, and it costs nothing while the role split is respected.

**Human attestation is never synthesized.** Reviewing and publishing remain acts
of certification by an authorized person. This command lets that person perform
them over SSH; it does not let anyone perform them on that person's behalf.

---

## 7. Audit

Every successful mutation writes the canonical event through the existing
`sys_audit_logs` trail (`AuditLogService`) — no new audit table, no parallel
system, no second event invented for the CLI. Existing events keep their exact
meaning and simply carry one more structural field:

```
channel = HTTP | CLI
```

Audit payloads stay structure-only, filtered against
`LegacyRmeAuditEvent::ALLOWED_METADATA_KEYS`. Never a patient name, KTP/NIK,
medical record number, filename, absolute path or clinical content — asserted.
The source document appears as a 12-character checksum prefix: enough for an
operator to match against their own manifest, not enough to reconstruct anything.

**One deliberate exception, stated rather than glossed over.** Every *structured*
field of the CLI's `--json` output is structure-only, but `refusal_message` is the
canonical service's operator-facing explanation verbatim — the same sentence the
browser shows — and the date rules legitimately quote the dates a refusal is
about, including, in one case, the patient's date of birth ("tanggal RME lama
tidak boleh mendahului tanggal lahir pasien"). Redacting it would leave an
operator holding a refusal they cannot act on.

That is not a widening. The message only ever reaches a caller who has already
passed the capability, branch-scope, permission and policy gates for that import —
the same audience, with the same authority, that reads the identical sentence in
the workspace. A name, Nomor RM or KTP/NIK still never appears, and that boundary
is asserted by a test rather than assumed.

---

## 8. Forbidden operational paths

The CLI exists specifically to remove the temptation. These remain forbidden as
recovery paths, whatever the hour:

```
UPDATE / DELETE on stg_rme_legacy_imports or trx_rme_legacy_records
php artisan tinker  → ->update(['status' => ...])
editing uploaded_by / published_by / reviewed_by / cancelled_by by hand
adjusting a quota bucket by hand
migrate:fresh · db:wipe
```

---

## 9. Wave-3

**`LEGACY-RME-PDF-ROLL-4-WAVE-3` is `SKIPPED / NOT REQUIRED`.** See
`docs/sprints/legacy-rme-pdf-roll-4-wave-3-skipped.md`. No production wave was
created, no approval consumed, no branch admitted, no quota spent, no document
published, and no GO tag exists or is required.

---

## 10. Scope boundaries

Not started and not authorized by this workstream: any migration wave; TKM1 /
ATG3 / SUN4 migration; bulk or unattended import; OCR as a clinical-date
authority; conversion of legacy archives into native RME; RBAC redesign; schema
change (**no migration was added**); infrastructure change.
