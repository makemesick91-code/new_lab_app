# LEGACY-RME-PDF-ROLL-4-WAVE-2 — Controlled Production Migration Wave-2

**Status: READY / WAITING FOR OWNER APPROVAL AND GENUINE INPUT — NOT EXECUTED.**

No wave was opened, no capability enabled, no document admitted, no record
published, and no GO tag created. Production was read only. This document records
the verified pre-wave baseline so a future Wave-2 starts from evidence instead of
re-deriving it.

Wave-2 is blocked on two prerequisites that are **not delegable to an agent** and
were confirmed absent by the owner on 2026-08-15:

1. **No genuine un-migrated candidate documents exist** for any branch.
2. **No fresh Wave-2 owner approval exists.**

Per `.cursor/rules/100-legacy-rme-production-migration-wave.mdc`: *zero genuine
documents is WATCH, never GO*, and *every future production wave needs a fresh,
exact owner approval*. Wave-1's approval
(`ROLL-4-WAVE-1-OWNER-APPROVAL-2026-08-14`) covers LDK2 **for that wave alone**
and cannot authorize this one.

---

## Authority

| Field | Value |
| --- | --- |
| `BASE_SOURCE` | canonical remote-tracking ref (DEVFLOW-FIX-BASE-REF-1) |
| `BASE_BRANCH` | `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` |
| `BASE_SHA` | `6089cbb3f4a56aacd37229ab76a589e473b1ece3` |
| `RUNTIME_DEPLOYED_SHA` | `6089cbb3f4a56aacd37229ab76a589e473b1ece3` |
| `CODE_CHANGE_REQUIRED` | NO — no runtime defect found; no deploy manufactured |
| `GO_TAG` | **none — correctly withheld** |

The local base branch was stale at `a3d1723`. The canonical remote ref was used,
which is exactly the failure DEVFLOW-FIX-BASE-REF-1 exists to prevent. No local
fallback was accepted.

## Immutable authorities — all verified byte-exact

| Workstream | Peeled tag SHA | Result |
| --- | --- | --- |
| ROLL-4-WAVE-1 | `2ffe00c198eb8a0e78703d8c4555a0f6c5c08744` | intact |
| INFRA-SEC-ENV-1 | `19d18a41e97a90a54bb8ce4b0145a935545a2a78` | intact |
| INFRA-SEC-RUNTIME-1 | `acf1e224cbf54d4e201a20fcaae697dc55e01069` | intact |
| DEPLOY-HARDEN-1 | `b11bbbcffe41599f4a3f9999224f4c7503106bbf` | intact |
| LEGACY-RME-DATE-TZ-1 | `b8038c567360b323bc56a185cd518ed1c4b41a28` | intact |
| DEVFLOW-FIX-BASE-REF-1 | `6089cbb3f4a56aacd37229ab76a589e473b1ece3` | intact |

None was moved, recreated or reopened.

## Production baseline (srv1730088, `pilot`, read-only, 2026-08-15)

`VPS_HEAD` = `6089cbb3` — exact-match tag
`devflow-fix-base-ref-1-canonical-remote-base-resolution-go`. `APP_ENV=pilot`,
`APP_DEBUG=false`.

### Resting state — already correct, nothing to restore

| Signal | Value |
| --- | --- |
| capability enabled | `false` |
| migration capability enabled | `false` |
| admission wave | `null` |
| admitted branch codes | `[]` |
| approval reference | `null` |
| approved branch codes | `[]` |
| wave row registered | `false` |
| awaiting review / reviewed / processing / queued / failed | `0 / 0 / 0 / 0 / 0` |
| `failed_jobs` / `jobs` | `0 / 0` |

### Foundation gates

| Gate | Result |
| --- | --- |
| `legacy-rme:rollout-readiness` | **GO** (16/16, incl. poppler, private disk, queue contract, closed pre-wave admission) |
| `foundation:deployment-entrypoint-check` | **safe / ENT-11 GO**, exit 0 — snapshot execution, exact-SHA pin, no pre-pull |
| `clinical:date-diagnose` | clinical timezone `Asia/Makassar`, canonical **yes**; instants stay UTC |
| health `/login` `/health/live` `/health/ready` `/health/lb` | `200 / 200 / 200 / 200` |
| nginx / php8.3-fpm / queue worker | active / active / active |
| free disk | 95 GB (floor 2 GB) |

### Separation of duties — staffed and satisfiable

Both halves exist as distinct accounts, so the Wave-1 rule has something to bite
on. Neither holds `manage_legacy_rme_migration_operations` (Super Admin only), so
the account that shapes a wave is never the account that signs it.

| Half | Account | Scope | Legacy permissions held |
| --- | --- | --- | --- |
| maker / intake | user 7 (Admin Klinik) | branch-pinned to LDK2 (`branch_id=2`) | `view_legacy_rme_imports`, `create_legacy_rme_imports` |
| checker / publisher | user 11 (Supervisor RME) | RME-wide | `view` + `review` + `publish` + `approve_legacy_rme_migration_wave` |

7 ≠ 11, so a live Wave-2 can satisfy separation of duties without Super Admin.
Super Admin's `Gate::before` bypass remains no substitute — it grants abilities,
it cannot make one identity into two.

## Candidate inventory — empty, and why

A full read-only sweep of the production filesystem for PDFs found **only the six
already-migrated sources** under
`storage/app/legacy-rme-private/rme-legacy/imports/` (patients p36×2, p37, p38,
p39, p40). Anything already in the private store is *migrated, not a candidate*.

- No intake directory exists on production.
- No PDFs exist in `/home`, `/root`, `/tmp` or the private import area outside
  those six.
- No designated Wave-2 intake directory exists on the operator workstation.
- The workstation's general `~/Downloads` folder holds unrelated personal files.
  Two have clinical-sounding names, but neither was designated by the owner as a
  Wave-2 source, and one maps to ATG3 — outside the recommended single-branch
  LDK2 scope. **A file found in a general-purpose folder is not an authorized
  production clinical source.** Neither was opened, hashed into an inventory, or
  treated as a candidate.

Already-migrated distribution (context only, not candidates): ATG3 1, LDK2 2,
SUN4 1, TKM1 2 — 6 imports PUBLISHED, 5 records published, 1 void.

Measured store cost: 6 documents, 8 rendered pages, 2,822,525 B source total
(~470 KB average source per document). Size a future wave from the **rendered**
total, which historically runs ~4× the source.

## Reconciliation

Every counter is zero because nothing was admitted. These are *not* claims of a
successful run — they are the untouched baseline.

```
TOTAL_APPROVED_CANDIDATES = 0
ADMITTED = 0      PUBLISHED = 0      REJECTED = 0      FAILED = 0
UNEXPLAINED = 0   QUOTA_DRIFT = 0 (no quota consumed; no wave registered)
DUPLICATE_ADMISSIONS = 0             DUPLICATE_PUBLISHED = 0
WRONG_BRANCH = 0  WRONG_PATIENT = 0  UNAUTHORIZED_PUBLISH = 0
NATIVE_CLINICAL_DELTA = 0  BILLING_DELTA = 0  LAB_DELTA = 0  SATUSEHAT_DELTA = 0
LIVE_QUOTA_EXHAUSTION = NOT_EXERCISED
PAUSE_EXERCISED = NO   RESUME_EXERCISED = NO   QUEUE_DRAINED = N/A (never filled)
```

## Exact prerequisite checklist to start Wave-2

Blocking, in order. Items 1–2 are the owner's; the rest are already satisfied and
need only re-verification at execution time.

1. **Genuine un-migrated LDK2 source documents**, placed in a directory named by
   the owner. Not copies of a Wave-1 PDF, not edited derivatives, not clones of a
   database row. Wave-1's published LDK2 record (canonical RM
   `DG-LDK2-2024-22681`, patient 40) must never be re-imported.
2. **A fresh, scope-bound Wave-2 owner approval** naming: exact branch (LDK2),
   maximum candidate count (a ceiling, never a target), execution window, maker,
   checker/publisher, and stop conditions. Recorded as
   `legacy_rme_rollout.admission` config — the authority — with the wave row as
   its operational mirror; disagreement fails closed.
3. Re-verify the foundation gates above (all currently GO).
4. Confirm maker 7 / checker 11 are still distinct, active and correctly
   permissioned.
5. Read-only candidate preflight per candidate: source integrity, RM resolution,
   RM-derived branch (operator override forbidden), patient resolution (exact
   only), date parse, `selected_rme_date` = earliest human-confirmed date,
   `latest_rme_date` = latest, historical-age gate `latest < clinicalToday`
   strictly on the Asia/Makassar calendar, native cutoff, duplicate check.
6. Freeze the approved candidate set and its source hashes before admission.
7. Only then open capability and admission — last, never first.

## Notes for the next attempt

- The clinical date on the run day was 2026-08-15 (Asia/Makassar). A document
  whose `latest_rme_date` equals the clinical today is **not** yet historical
  under the strict `<` rule.
- `legacy-rme:wave-admin` is dry-run unless `--apply`, and checks the named
  `--actor`'s real permissions — there is no CLI identity that bypasses
  authorization. Use it for registration and staffing; do not hand-edit state.
- Quota counts **accepted-into-staging**; retries are not charged again. `NULL`
  (unlimited) is not `0` (admits nothing).
- Do not widen beyond LDK2 without a new approval. Multi-branch scale-up is a
  later wave.
