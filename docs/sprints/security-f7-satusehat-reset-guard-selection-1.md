# SECURITY-F7-SATUSEHAT-RESET-GUARD-SELECTION-1

**Status:** IMPLEMENTED AND VERIFIED LOCALLY. Not pushed, not merged.

**Type:** `SECURITY_FIX` (CI governance, tests only). **Base:** `19a2ed1c`
(`feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`). Audit item F-7
(`docs/audit/full-system-audit-2026-10-27/POST-AUDIT-EXECUTION-ROADMAP.md`, PR-07).

No runtime change, no migration, no route, no permission.

## 1. Finding

`SatusehatSyntheticPilotService::reset()` force-deletes visits, medical records, invoices,
SATUSEHAT candidates and patients. It is safe today: every delete is keyed on the synthetic
markers of the `SYN4A` branch, and a stray-data check aborts the whole reset when anything
non-synthetic sits in that branch. `Satusehat4aSyntheticRehearsalTest` and
`Satusehat4aRemediationLifecycleTest` prove it — but neither matched a critical-gate filter
token, and the Full Suite is deferred (CG-18). A scoping regression could therefore merge
with every required gate green and hard-delete real clinical rows on its first run in
production.

## 2. Change

- `.github/workflows/foundation-evidence-gates.yml`: tokens `Satusehat4aSyntheticRehearsal`
  and `Satusehat4aRemediationLifecycle` in BOTH critical-gate variants (filters identical).
- `config/ci_runner.php`: both files declared in `critical_gate_mandatory_suites`.
- Two guard tests in `Satusehat4aRemediationLifecycleTest`:
  - a non-synthetic visit in `SYN4A` — for the synthetic patient, in the synthetic room, so
    only the visit check can catch it — aborts the reset and deletes nothing;
  - a reset never touches a visit or patient outside `SYN4A`.

## 3. Verification

`pest --list-tests` with the exact critical filter selects 0 cases from these two files on the
base and 20 after (18 existing + 2 new); the two filter variants are identical.

Mutation matrix against `SatusehatSyntheticPilotService` (reverted by copy after each run):

| Mutant | Before this change | After |
|---|---|---|
| stray guard disabled | killed | killed |
| visit scope widened to the branch (`visit_number` filter removed) | **survived** | killed |
| patients deleted without the branch scope | — | killed |
| visits deleted without the id scope | — | killed |
| patient stray check removed | — | killed |

## 4. Rollback

`git revert`; CI only.
