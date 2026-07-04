# DQ-3.1 — Manual Review & Repair Playbook — Deploy Evidence

## Sprint status

| Field | Value |
| --- | --- |
| Status | **COMPLETE / MERGED / GO TAGGED / DEPLOYED / SMOKE PASS** |
| Decision | **WATCH** (playbook ready; 4 ambiguous rows remain; no approved mapping executed) |
| Feature branch | `feature/dq-3-1-manual-review-repair-ambiguous-batch-rows` |
| PR | [#166](https://github.com/makemesick91-code/new_lab_app/pull/166) |
| Merge commit | `b4d4d8c` |
| GO tag | `dq-3-1-manual-review-repair-ambiguous-batch-rows-go` |
| Post-deploy hotfix | `765dffe` — fix `--output` path for `storage/app/...` |

## VPS deploy

| Field | Value |
| --- | --- |
| Path | `/var/www/asia-dental-lab-v2` |
| Previous HEAD | `e284d55` (`dq-3-source-document-batch-linkage-closure-go.1`) |
| GO tag checkout | `b4d4d8c` |
| Deployed HEAD (final) | `765dffe` (GO tag + output-path hotfix) |
| DB backup | `storage/app/backups/deploy/pre_dq31_20260704-050620.sql` (**582K**) |
| Migrate | `2026_07_04_140001_add_dq31_manual_repair_provenance` — DONE |
| npm build | PASS (Node 18 EBADENGINE warning — non-blocking) |
| composer | PASS |
| php8.3-fpm / nginx | restarted, `nginx -t` OK |
| Smoke | `php artisan about` OK; `curl -I http://127.0.0.1` → HTTP 302 |

## Pre-repair audits

| Audit | Result |
| --- | --- |
| DQ-1 | **GO** (20 PASS) |
| DQ-2 | **WATCH** (2 WARN — source-document transfer/opname) |
| DQ-3 | **WATCH** (3 WARN — DQ3-SRC-002/003 + related) |
| DQ-3.1 review | **WATCH** — 4 ambiguous rows |

### Ambiguous rows (production)

| Type | Item ID | Reason |
| --- | --- | --- |
| transfer | 2 | OUT/IN batch lineage disagrees (candidates: 16, 17) |
| transfer | 12 | OUT/IN batch lineage disagrees (candidates: 18, 19) |
| opname | 5 | No deterministic opname batch recovery path |
| opname | 8 | No deterministic opname batch recovery path |

## Review pack

| Path | Size |
| --- | --- |
| `storage/app/backups/deploy/dq31/dq31_review_pack_20260704-050710.json` | **7.1K** |

Mapping templates (repo): `docs/templates/dq-3-1-ambiguous-batch-repair-mapping-template.csv`

## Repair execution

| Item | Value |
| --- | --- |
| Approved mapping | **NOT AVAILABLE** at `storage/app/backups/deploy/dq31/dq31_approved_mapping.csv` |
| Dry-run | N/A (no mapping file) |
| Execute | **NOT EXECUTED** — owner approval mapping required per NSF-R024 |
| Production mutation | **NONE** on ambiguous rows |

## Post-deploy audits

| Audit | Result |
| --- | --- |
| DQ-1 | **GO** |
| DQ-2 | **WATCH** |
| DQ-3 | **WATCH** |
| DQ-3.1 | **WATCH** (4 ambiguous) |
| Foundation summary | NSF WATCH \| DMO WATCH \| Combined **WATCH** |

## Local validation

```
php artisan test --filter=Dq31          → 19 passed
php artisan test --filter=Dq3SourceDocumentBatch → 15 passed
php artisan test --filter=Dq2BatchGovernance → 11 passed
./vendor/bin/pint --dirty               → clean
```

## Governance rules added

- **NSF-R024** — Ambiguous inventory batch linkage repair requires approved mapping and audit log
- DQ-3.1 commands: `inventory:ambiguous-batch-review-pack`, `inventory:repair-ambiguous-batch-links`
- Deploy gates updated in `docs/architecture/nsf-governance-deploy-gates.md`

## Warnings / risks

- VPS repair blocked until owner provides approved mapping CSV with `approval_reference`, `approved_by`, `approved_at`, `reason`.
- Transfer rows require OUT/IN resolution documented in `reason`.
- Opname rows require manual opname mapping approval in `reason`.
- GO tag `b4d4d8c` deployed; hotfix `765dffe` applied on VPS for export path resolution (not re-tagged).

## Final decision

**WATCH / PLAYBOOK READY** — DQ-3.1 workflow deployed and verified; DQ-2/DQ-3 remain WATCH until owner-approved mapping repairs all 4 rows.

## Next recommended sprint

Owner review of `dq31_review_pack_20260704-050710.json` → fill approved mapping → dry-run → backup → `--execute` repair → re-audit for DQ-2/DQ-3/DQ-3.1 **GO**.
