# Sprint 57.1 — Cross-Branch RM Suffix Lookup Usability — Implementation Spec

Branch: `feature/sprint-57-1-cross-branch-rm-suffix-lookup`
Base: `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report`
Builds on: Sprint 57 (`sprint-57-cross-branch-rm-lookup`, merge `8dbf1e2`)

## 1. Goal
Let Admin/Kasir find a cross-branch Nomor RM by typing only the **last digits (suffix)**
of the medical record number, instead of always typing the full RM, while keeping the
lookup privacy-safe, read-only, and intentionally cross-branch.

## 2. Problem
Sprint 57 lookup requires an **exact full Nomor RM** (format `DG-{CABANG}-{TAHUN}-{NOMOR}`,
e.g. `DG-TKM1-2026-0001`). Typing the full string is long and slows the front-desk workflow.
Admins know the short manual tail (`0001`) but must still type the whole prefix.

## 3. Non-goals
- No new cross-branch edit / create / visit / payment action.
- No schema change, no migration, no permission/role/policy/middleware change.
- No new exposed patient field beyond the Sprint 57 safe set.
- No change to branch-scoped Kunjungan / Rekam Medis / Kasir list queries.
- No name/phone/KTP search. Suffix is matched against `medical_record_number` only.
- No `.env`, no deploy, no GO tag, no VPS.

## 4. Current Sprint 57 behavior
- `CrossBranchPatientLookupService::lookupByMedicalRecordNumberAcrossBranches(?string $rm)`.
- Trims input; empty → `searched=false`.
- **Exact** `where('medical_record_number', $rm)` across ALL branches (no branch filter).
- Selects only: `id, name, medical_record_number, branch_id, is_active` (+ `branch:id,code,name`).
- Returns safe rows: `medical_record_number, name, branch_label, is_active, latest_visit_date,
  is_current_branch`. `is_duplicate = results > 1`. Cap `MAX_RESULTS = 5`.
- Blade partial `cross-branch-rm-lookup.blade.php` renders a read-only table; KTP/WA/phone/
  email/address never rendered.

## 5. New suffix lookup behavior
Input is trimmed. Resolution order (single method, extended in place):
1. **Empty** → `searched=false` (unchanged).
2. **Exact match first**: `where('medical_record_number', $rm)`. If ≥1 row →
   `match_type='exact'`, return rows. Preserves Sprint 57 behavior 1:1 for full-RM input.
3. **No exact match + input shorter than minimum** → `too_short=true`, no broad query run.
4. **No exact match + input ≥ minimum** → **suffix** query
   `where('medical_record_number', 'LIKE', '%'.escapeLike($rm))` across ALL branches,
   limited to `DISPLAY_LIMIT + 1` rows.
   - rows ≤ `DISPLAY_LIMIT` → `match_type='suffix'`, return rows.
   - rows > `DISPLAY_LIMIT` → `too_many=true`, return **no** rows (do not dump list).
LIKE wildcards in input (`% _ \`) are escaped so they are matched literally.

Return shape (superset of Sprint 57 — additive keys only):
`searched, query, results, is_duplicate, match_type('exact'|'suffix'|null), too_short(bool),
too_many(bool), min_length(int)`.

## 6. Minimum input length decision
**Minimum = 4 characters.** RM format is `DG-{CABANG}-{TAHUN}-{NOMOR}`; the manual tail is
typically a 4-digit zero-padded number (`0001`). 4 chars:
- Matches the common manual-tail length, so admins type what they already know.
- Is long enough to keep cross-branch suffix matches narrow (avoids dumping the table).
Note: manual tails are NOT auto-padded and may be short (e.g. `DG-LDK2-2026-25`). A 4-char
suffix on such a record includes separator/year digits (`26-25`), which is acceptable —
`LIKE '%input'` handles it uniformly and the `too_many` guard protects breadth. We keep the
preferred **4** rather than dropping to 3, because 3 widens cross-branch matches without a
real format need.

## 7. Duplicate result behavior
- **Exact** match with >1 row → genuine duplicate RM → keep Sprint 57 amber "kemungkinan
  duplikat" warning (`is_duplicate=true`).
- **Suffix** match with >1 row (≤ limit) → not a duplicate; show all safe candidates +
  warning: *"Ditemukan beberapa pasien dengan akhiran Nomor RM yang sama. Cocokkan nama dan
  cabang pasien sebelum melanjutkan."*

## 8. Too-many-result behavior
Suffix rows > `DISPLAY_LIMIT` (10) → return `too_many=true` and **no rows**; blade shows
*"Terlalu banyak hasil. Masukkan lebih banyak digit Nomor RM."* No large list is rendered.

## 9. Privacy rule
Select list unchanged: `id, name, medical_record_number, branch_id, is_active` + branch
code/name. Result rows expose only Nomor RM, nama, cabang, status aktif, latest visit date,
current-vs-other branch note. NEVER `ktp_number`, `whatsapp_number`, `phone`, `email`,
`address`, diagnosis, treatment, notes, password/token/.env.

## 10. Branch isolation rule
The deliberate cross-branch exception stays **only** inside
`CrossBranchPatientLookupService`. All other list/index queries remain branch-scoped and
untouched. No cross-branch write/visit/payment is added. Result flags whether each row is the
current branch or another branch.

## 11. Files to inspect
- `app/Modules/Patient/Services/CrossBranchPatientLookupService.php`
- `resources/views/rme/partials/cross-branch-rm-lookup.blade.php`
- `app/Modules/ClinicVisit/Controllers/ClinicVisitController.php`
- `app/Modules/MedicalRecord/Controllers/MedicalRecordController.php`
- `app/Modules/RmeInvoice/Controllers/RmeInvoiceController.php`
- `app/Modules/Patient/Services/PatientMedicalRecordNumberService.php` (RM format)
- `tests/Feature/RME/CrossBranchRmLookupTest.php`

## 12. Files expected to change
- `app/Modules/Patient/Services/CrossBranchPatientLookupService.php` — extend method with
  exact→suffix resolution, min-length + too-many guards, additive return keys.
- `resources/views/rme/partials/cross-branch-rm-lookup.blade.php` — placeholder/help text,
  too_short / too_many / suffix-duplicate messaging.
- `tests/Feature/RME/CrossBranchRmLookupTest.php` — add suffix tests; adjust the old
  "no substring" test to suffix semantics (suffix now matches by design; prefix still does not).
- `docs/sprint_57_1_cross_branch_rm_suffix_lookup_spec.md` — this spec.
- (Controllers unchanged — they already pass `rm_lookup` through the service.)

## 13. Test plan
1. Full exact Nomor RM lookup still works (`match_type=exact`).
2. Last-4-digit suffix finds patient across all branches.
3. Suffix finds a patient from another branch (`is_current_branch=false`).
4. Multiple suffix matches return multiple safe candidates (`match_type=suffix`).
5. Too-short input (<4) with no exact match → `too_short=true`, no broad results.
6. Unknown suffix → safe empty (`searched=true`, no rows, no `too_many`).
7. Row never exposes `ktp_number`.
8. Row never exposes WA/phone/email/address.
9. Row never exposes diagnosis/treatment/notes (only safe keys present).
10. Too-many suffix matches (>10) → `too_many=true`, no rows.
11. Prefix input still does NOT match (suffix-only semantics).
12. Existing branch-scoped Kunjungan/Rekam Medis/Kasir lists unchanged (HTTP renders, KTP hidden).

## 14. Risk checklist
- [ ] Cross-branch exception stays inside the service only.
- [ ] No schema / migration / permission / role / policy / middleware change.
- [ ] No new sensitive field selected or rendered.
- [ ] LIKE wildcards escaped (no injection / unbounded match).
- [ ] Results limited; too_many guarded (no list dump).
- [ ] Exact full-RM behavior preserved (back-compat keys intact).
- [ ] Read-only; no write/visit/payment path added.
- [ ] Pint clean; `git diff --check` clean.

## 15. Rollback plan
Revert the feature commit on `feature/sprint-57-1-cross-branch-rm-suffix-lookup`
(`git revert <hash>` or reset before merge). No migration/schema/permission to undo. Sprint 57
exact-match behavior (`sprint-57-cross-branch-rm-lookup-go`, `8dbf1e2`) is the fallback baseline.
