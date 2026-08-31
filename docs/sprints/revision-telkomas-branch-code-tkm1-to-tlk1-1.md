# REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1

**Cabang Telkomas' canonical branch code is `TLK1`. `TKM1` is deprecated.**

Branch: `revision/telkomas-branch-code-tkm1-to-tlk1-1`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Base authority: `2fb8c4c72a26d86bb5194281d50cdce844415cfb` (tree `80e5404b…`,
GO tag `bugfix-legacy-odontogram-queue-consumer-1-go`) — independently confirmed
as the live production HEAD on `srv1730088` before any work began.

---

## 1. What this revision actually found

The task was framed as a rename. The inventory found that **production had
already been renamed by hand, and the codebase had not been** — so the two had
drifted into three live defects, all of which this revision closes.

Production `mst_branches` row id 1 is `Cabang Telkomas` with code **`TLK1`**.
Everything in the repository still said `TKM1`.

| # | Defect | Consequence in production |
|---|--------|---------------------------|
| 1 | `RmeBranchSeeder` maps `'TKM1' => 'Cabang Telkomas'` and looks the branch up by that code alone | The next `db:seed --class=RmeBranchSeeder --force` (documented as a routine post-deploy step) finds no `TKM1` row and **creates a second "Cabang Telkomas"**. Branch id is the isolation boundary, so one clinic's patients would be split across two branches, each operator seeing half. |
| 2 | Legacy archive branch derivation resolves `Branch::where('code', $codeFromRm)` | Patient 36 holds `DG-TKM1-2024-9985`. No branch has that code, so derivation returned `BRANCH_NOT_FOUND` — that patient's legacy RME and legacy odontogram archive was **unreachable**. |
| 3 | The rollout allowlist admits the literal token declared in the environment (`TKM1,LDK2,ATG3,SUN4`) | Derivation now reports a branch's actual code, so Telkomas would be measured as `TLK1` against an allowlist naming `TKM1` — **locked out of the active WAVE-4 it is fully approved for**, with an error about admission rather than about a rename. |

Defect 2 was already live. Defects 1 and 3 were latent and would have fired on
the next seed run and the next Telkomas import respectively.

---

## 2. The rule

```
INPUT  TLK1  → Cabang Telkomas → canonical_code = TLK1
INPUT  TKM1  → HISTORICAL ALIAS → Cabang Telkomas → canonical_code = TLK1
INPUT  other → FAIL CLOSED
```

One branch identity. One canonical code. `TKM1` is **accepted** because it is
printed on cards patients already carry and on documents already archived; it is
**never emitted**.

The mapping is declared once, in `App\Modules\Branch\Support\BranchCodeAlias`.
No call site carries `$code === 'TKM1' || $code === 'TLK1'` — that is precisely
how the two halves of a rename drift apart.

---

## 3. Occurrence classification

727 occurrences of `TKM1` across 111 files were inventoried and classified
before anything changed. **No blind `grep | sed` was run over the repository or
the database.**

| Category | Where | Action |
|---|---|---|
| **A. Active canonical data** | `mst_branches.code`; `mst_patients.medical_record_number` (1 row); live WAVE-4 rollout rows | Migrated |
| **E. Runtime config** | `RmeBranchSeeder` registry; `config/legacy_rme_rollout.php` normalizers; production environment declarations | Changed / canonicalized |
| **User-facing generators** | legacy patient CSV template row; 3 Blade placeholders | Changed to `TLK1` |
| **F. Test / fixture** | 530 occurrences in `tests/` | Renamed to `TLK1`; dedicated alias tests added |
| **G. Documentation (canonical statements)** | `CLAUDE.md` registry block; `.cursor` rules 73, 95, 96 | Superseded |
| **B/C. Immutable & historical** | published legacy evidence + stored source RM; `sys_audit_logs` (5 rows); `trx_clinic_visits.visit_number` (4 rows); `stg_legacy_patient_imports` (2 rows); terminal waves; historical sprint/wave/incident records | **Preserved verbatim** |

### Why `visit_number` is preserved

`VIS-TKM1-20260820-001` and three siblings are **issued transactional
identifiers** on completed visits — the same class of value as an invoice
number. They were printed on documents already handed to patients; nothing
derives a branch from them (the generator reads the branch master and already
emits `TLK1`); and their uniqueness is scoped per branch and date, so no
sequence conflict exists. Rewriting them would invalidate paper already issued
to buy nothing.

### Why the source RM on a legacy document is preserved

`LegacyRmeSourceRmNormalizer` folds transcription noise and deliberately does
**not** canonicalize the branch code. The document really does say `TKM1`; an
archive that rewrote it would be claiming the document says something it does
not. Reachability is restored by alias-aware **matching**, not by editing
evidence.

---

## 4. Collision analysis (production, read-only)

```
TLK1_BRANCH_COLLISION        = 0   (TLK1 is held by Cabang Telkomas itself)
RM_COLLISION_COUNT           = 0
VISIT_NUMBER_COLLISION       = 0
```

Both preflights are enforced in the migration and abort the whole transaction if
they ever fail. Nothing is overwritten, merged, deleted or renumbered.

---

## 5. Changes

**New**

- `app/Modules/Branch/Support/BranchCodeAlias.php` — the one-way alias policy.
- `database/migrations/2026_08_31_100001_revise_telkomas_branch_code_tkm1_to_tlk1.php`
- `tests/Feature/Branch/TelkomasBranchCodeAliasTest.php`
- `tests/Feature/Branch/TelkomasBranchCodeMigrationTest.php`
- `.cursor/rules/92-telkomas-branch-code-canonical.mdc`

**Changed**

- `PatientMedicalRecordNumberService` — `branchCodeFrom()` now reports the
  canonical code; adds `literalBranchCodeFrom()`, `canonicalizeBranchCode()`
  (parser-driven, segment-only) and `equivalentNumbers()`.
- `LegacyRmePatientResolutionAuditService` — exact identity resolution spans
  every spelling of the same number.
- `CrossBranchPatientLookupService` — same, for the canonical patient search.
- `LegacyRmeBranchAdmissionService` — canonicalizes the allowlist, the approved
  set, the forbidden set and the resolved code.
- `config/legacy_rme_rollout.php` — allowlist and pilot-scope canonicalization.
- `RmeBranchSeeder` — canonical registry; matches every equivalent code so it can
  never create a duplicate Telkomas.
- `LegacyPatientImportService` CSV template + 3 Blade placeholders.

`LegacyRmeBranchResolver` and `LegacyOdontogramBranchBindingService` required
**no change**: both derive the branch through `branchCodeFrom()`, so one fix
propagates to legacy RME and legacy odontogram alike.

---

## 6. Migration design

Single `DB::transaction`. Every target enumerated; no column scan; no
`replace(col,'TKM1','TLK1')` anywhere.

1. Assert no branch-code collision → else `RuntimeException`, nothing written.
2. Rename `mst_branches.code` in place (primary key untouched).
3. Migrate patient Nomor RM via `parse()`/`compose()` — branch segment only,
   soft-deleted rows included (the unique index spans them), **all** collisions
   proven absent before the **first** write.
4. Canonicalize branch codes on **non-terminal** rollout waves only.

**Irreversible by design.** After this runs, the application legitimately issues
`TLK1`; nothing in the data distinguishes "TLK1 because migrated" from "TLK1
because created that way", so a `down()` would corrupt every record created
since. `down()` is a documented no-op; rollback is the pre-migrate backup the
deploy already takes.

**Idempotent.** Every step selects only rows still holding a deprecated code —
which is what made it safe against a production that had already been renamed by
hand.

---

## 7. Durable rules

Recorded in `.cursor/rules/92-telkomas-branch-code-canonical.mdc`, which
supersedes the earlier "Telkomas = TKM1" registry statements in rule 73 and
`CLAUDE.md`. Historical records — wave scopes, the cancelled
`ROUTINE-20260819-TKM1-01` batch, past pilot evidence, audit rows — keep their
`TKM1` text: they describe what was true when they were written, and an audit
trail edited to look tidy is false.
