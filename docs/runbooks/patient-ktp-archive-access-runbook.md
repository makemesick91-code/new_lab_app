# Patient KTP archive access — operator runbook

Owner rule: `.cursor/rules/183-patient-ktp-archive-branch-isolation.mdc`.
Sprint: `docs/sprints/security-patient-ktp-archive-branch-isolation-1.md`.

## Who can open a stored KTP card

| Account | Opens a card captured at… |
|---|---|
| Front Office / Admin Klinik with a working branch committed for today | **only** cards captured at that branch |
| The same account with no working branch (offline, context released) | nothing |
| Perawat (working branch self-selected, never committed for the day) | nothing |
| Super Admin | any branch (existing privilege) |
| Supervisor RME **without** `view_cross_branch_patient_ktp_documents` | nothing |
| A Supervisor RME **directly** granted `view_cross_branch_patient_ktp_documents` | any branch |
| An account that gets the permission only through a **role** | nothing — role-level grants are ignored |
| Doctor, Kasir, Owner, any role without `manage patients` | nothing (unchanged) |

A refused request answers **404**, exactly like a card that does not exist.
The "Dokumen KTP Tersimpan" panel on *Master Data → Pasien → Ubah* is shown only
to an account that may open the card.

## Granting cross-branch access to one account

Only on an explicit owner decision, only for a Supervisor RME account. There
is no screen that assigns a permission to ONE account (the role editor grants
to every holder of a role, and a role-level grant is ignored by design), so
the governed path is the command, run as the runtime user on the server:

```bash
# preview (writes nothing)
php artisan rbac:patient-ktp-cross-branch-access grant --user=<id|email> --actor=<super-admin id|email> --reason="<why, 10-1000 chars>"
# apply
php artisan rbac:patient-ktp-cross-branch-access grant --user=<id|email> --actor=<super-admin id|email> --reason="<why>" --apply
# check
php artisan rbac:patient-ktp-cross-branch-access status --user=<id|email>
# revoke
php artisan rbac:patient-ktp-cross-branch-access revoke --user=<id|email> --actor=<super-admin id|email> --reason="<why>" --apply
```

Each change is audited (`PATIENT_KTP_CROSS_BRANCH_ACCESS_GRANTED/REVOKED`,
reason length only). No separate `permission:cache-reset` is needed —
the command clears the permission cache itself.

## Cards with unknown provenance

Cards archived before this release carry no capture branch and are readable
only by a privileged account until the evidence backfill places them:

```bash
# dry run — writes nothing; aggregate counts + reason codes only
php artisan patient-documents:backfill-capture-branch
# apply — records a branch only where every piece of evidence agrees, audited per document
php artisan patient-documents:backfill-capture-branch --apply --actor=<super-admin id|email>
```

Run as the runtime user on the server (`sudo -u daengtisiams php artisan …`).
A document the evidence cannot place stays unknown; that is the correct,
fail-closed outcome — do not set it by hand.

## Reading the audit trail

`sys_audit_logs` with `entity_type = mst_patient_documents`:

| action | meaning |
|---|---|
| `PATIENT_DOCUMENT_VIEWED` | same-branch view |
| `PATIENT_DOCUMENT_VIEWED_PRIVILEGED` | cross-branch view by a privileged account |
| `PATIENT_DOCUMENT_ACCESS_DENIED` | refused view or delete; `new_values.reason` says why |
| `PATIENT_DOCUMENT_DELETED` | card deleted by an authorized account |
| `PATIENT_DOCUMENT_CAPTURE_BRANCH_BACKFILLED` | provenance recorded by the backfill |

Payloads carry branch ids and reason codes only — never a NIK, name, image or path.
Requests are throttled to 30 per minute per account per route.

## Troubleshooting

- *"Saya Front Office di cabang ini tapi tidak bisa membuka KTP."* — check the
  account's working branch (online context) is this branch, and that the card
  was captured here (`new_values.captured_branch_id` in the denial row). A card
  captured at another branch is refused by design.
- *Denial reason `unknown_provenance`* — a pre-release card the backfill could
  not place (for example a Master Data registration, which has no registration
  visit to anchor it). Only a privileged account can open it.
- *Denial reason `uncommitted_working_branch`* — the account's working branch
  is not committed for today (a Perawat always; or a context that disagrees
  with the day's lock). By design.
- Never edit `captured_branch_id` in the database: the model refuses a rewrite,
  and a hand edit is exactly the silent re-homing this control exists to stop.
