# LEGACY-RME-SOD-1 — Mandatory Separate Publisher Enforcement & Production Activation

**Branch** `feature/legacy-rme-sod-1-mandatory-separate-publisher-enforcement-production-activation`
**Base** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` @ `3cf57ea` (LEGACY-RME-OPS-CLI-1 GO)
**Rule mirror** `.cursor/rules/92-legacy-rme-separation-of-duties.mdc`

No migration. No new permission. No new route. No schema change. No seeder change.
KTP/NIK is never rendered. `ROLL-4-WAVE-3` remains **SKIPPED / NOT REQUIRED** and no
migration wave is created, admitted or approved by this sprint.

---

## 1. The gap this closes

OPS-CLI-1 shipped `legacy_rme_operations.require_separate_publisher` and left it
**OFF**, because switching it on changes browser behaviour on live clinical data
and that was the owner's call rather than a side effect of shipping a recovery
CLI. Its own sprint note ends *"Recommended owner follow-up: switch it on."*

That left three things unfinished, and only the first is the obvious one.

**The switch was off.** What actually enforced maker/checker in production was
the ROLE SPLIT — the maker holds `create_legacy_rme_imports` and is denied
`review`/`publish`; the checker holds `review`+`publish` and is denied `create`.
That split is load-bearing and remains so. It cannot, however, constrain an
account that holds every permission: a Super Admin's global `Gate::before` bypass
makes every policy answer yes, so exactly one class of account could file a
document and certify it alone.

**The rule sat outside the write.** The check ran as gate 5 of
`LegacyRmeImportLifecycleService::perform()` — read the import, run five gates,
*then* call `LegacyRmePublishService`, which opens the transaction and takes the
row lock. Every check that decides whether a write is lawful belongs under that
lock, beside the row it is judging. `uploaded_by` is never edited today, so
nothing is known to exploit the window; a safety invariant that depends on a
neighbouring invariant holding forever is still the wrong shape. And because
`publish()` and `review()` are public, any future caller reaching the service
directly — a job, a seeder, a recovery command, a well-meaning refactor — would
have escaped separation of duties silently, with no test noticing.

**Only publishing was covered.** Reviewing is the CHECKER's duty in the canonical
role split; Wave-1 withheld *both* `review` and `publish` from the maker. Gating
publish alone leaves the "a human other than the filer actually looked at the
rendered pages" attestation bypassable: file, review your own render, hand a
second account a rubber stamp.

---

## 2. What was decided, and why

### 2.1 One guard, consulted twice

`App\Modules\LegacyRme\Support\SeparatePublisherGuard` is now the only expression
of the comparison in the codebase. Both enforcement points ask it; neither
restates it.

```
HTTP  LegacyRmeImportController ─┐
                                 ├─► LegacyRmeImportLifecycleService (gate 5, early refusal)
CLI   LegacyRmeImportAdminCommand┘              │
                                                ▼
                                   LegacyRmePublishService
                                     review/publish transaction
                                       lockForUpdate($id)
                                       ► SeparatePublisherGuard   ← AUTHORITATIVE
                                         (judges the LOCKED row)
```

Gate 5 stays because refusing before any transaction opens is what lets both
surfaces report a clean refusal. It is no longer the only thing standing between
an uploader and their own document.

Two structural tests pin this: `require_separate_publisher` may appear in exactly
one file under `app/`, and neither the controller nor the CLI command may
reference `uploaded_by` at all.

### 2.2 Review is guarded too (option B)

`SeparatePublisherGuard::GUARDED_ACTIONS` is `[review, publish]`. `cancel` and
`retry` are deliberately excluded — they carry the *intake* permission because
they re-run the operator's own housekeeping, and sweeping them in would strand a
maker who needs to withdraw a document they filed.

**Not retroactive.** Publishing does *not* additionally demand
`reviewed_by != uploaded_by`. Rows reviewed by their own uploader before
activation must keep a lawful way forward; stranding them, or rewriting their
attribution to satisfy a rule that did not exist when they were filed, is exactly
the history-editing this module forbids. Pinned by a test.

### 2.3 The code default is now ON, and unset means ON

`config/legacy_rme_operations.php` resolves the switch through
`SeparatePublisherGuard::resolveEnabledFromEnv()` instead of
`(bool) env(..., false)`.

The failure mode that matters is not someone deliberately writing `false` — it is
a typo. `env()` returns `null` for a misspelled key and `''` for a key with no
value, and PHP casts both to `false`. Under the old expression,
`LEGACY_RME_REQUIRE_SEPARATE_PUBLISHR=true` or a truncated line would have
silently disabled a production safety invariant with nothing to say so.

Now, anything that is not one of four unambiguous falsy words resolves to
**enabled**:

| raw environment value | resolves to |
|---|---|
| unset / misspelled key | **enabled** |
| empty, whitespace | **enabled** |
| `maybe`, `tru`, anything unparseable | **enabled** |
| `false`, `FALSE`, `0`, `off`, `no` | disabled |
| `true`, `1`, anything else truthy | **enabled** |

Turning the rule off now requires writing one of those four words on purpose.
The production environment sets `LEGACY_RME_REQUIRE_SEPARATE_PUBLISHER=true`
explicitly anyway — belt and braces, so the posture is legible in the file *and*
safe if the line is ever lost.

### 2.4 Account separation is not human separation

This is the distinction the GO evidence must not blur.

| | enforced by | can be proven |
|---|---|---|
| **Account SOD** — `imported_by != acting user` | the server, on every surface | yes, and it is |
| **Human SOD** — maker human ≠ checker human | operational governance | **no** — no identity mechanism in this system establishes it |

Two humans sharing one login therefore **cannot** satisfy the rule — desirable,
since shared accounts destroy accountability. One human holding two logins
**can** — which is precisely why controlled migration operations still require
genuinely separate human operators as a governance control with a human
attestation, and why no GO evidence from this sprint claims otherwise.

---

## 3. Refusal behaviour

| surface | refused how | exit / status |
|---|---|---|
| Browser | `ValidationException` on field `actor` | redirect back with the error |
| CLI | classified `SEPARATION_OF_DUTIES` from the `actor` field | non-zero |
| CLI dry run | `eligible: false`, blocker `SEPARATION_OF_DUTIES` | non-zero |
| Direct service call | `ValidationException` from the in-transaction refusal | — |

Stable codes: `LegacyRmeLifecycleRefusal::SEPARATION_OF_DUTIES` (surface-level),
`LegacyRmePdfFailure::SEPARATE_PUBLISHER_REQUIRED` /
`SEPARATE_REVIEWER_REQUIRED` (recorded in the refusal trail).

**No new audit event was invented.** An authorization gate refusing early stays
unaudited exactly as `PERMISSION_DENIED` and `POLICY_DENIED` already do; a
refusal that reaches the canonical service is recorded as `LEGACY_RME_PUBLISH_REJECTED`
the way every other service refusal is, carrying the failure code and no PII.
Exactly one row per refusal — asserted.

**The screen explains, it never decides.** The uploader sees a warning panel and
no checker buttons; the POST is still refused twice if they craft it by hand.

---

## 4. Files changed

| file | change |
|---|---|
| `app/Modules/LegacyRme/Support/SeparatePublisherGuard.php` | **new** — the rule, the fail-closed resolver, the messages, the failure codes |
| `app/Modules/LegacyRme/Support/LegacyRmePdfFailure.php` | `SEPARATE_PUBLISHER_REQUIRED`, `SEPARATE_REVIEWER_REQUIRED` + messages |
| `app/Modules/LegacyRme/Services/LegacyRmePublishService.php` | in-transaction re-assert under the row lock, for review and publish |
| `app/Modules/LegacyRme/Services/LegacyRmeImportLifecycleService.php` | gate 5 delegates to the guard; covers review; preview reports it |
| `app/Modules/LegacyRme/Controllers/LegacyRmeImportController.php` | computes the two view flags — no rule logic in Blade |
| `resources/views/settings/rme/legacy-imports/show.blade.php` | explains the refusal, hides the doomed buttons |
| `config/legacy_rme_operations.php` | fail-closed resolution, default ON |
| `tests/Feature/LegacyRme/LegacyRmeSeparationOfDutiesTest.php` | **new** — 44 tests |

---

## 5. Existing published records

Activation is forward-only. Historical rows published before this rule existed
are **pre-enforcement evidence**: they are not rewritten, not re-attributed, and
not voided to satisfy a rule that did not apply when they were filed. The
pre-activation inventory is recorded in the GO evidence.

---

## 6. What this sprint deliberately does not do

- Does not create, admit or approve a migration wave. `ROLL-4-WAVE-3` stays SKIPPED.
- Does not change the ROLL-3 branch admission allowlist or the migration capability flag.
- Does not add a permission, a role, a route, a table or a column.
- Does not create a ClinicVisit, MedicalRecord, odontogram, invoice, payment, LabOrder or SATUSEHAT resource — a legacy publish never did and still never does.
- Does not claim to prove that two accounts belong to two people.
