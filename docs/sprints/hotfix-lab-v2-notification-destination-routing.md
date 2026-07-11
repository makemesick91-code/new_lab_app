# HOTFIX — Lab Workflow V2 Notification Destination Routing

**Branch:** `feature/hotfix-lab-v2-notification-destination-routing`
**Base:** `feature/sprint-26-phase-26-8-stabilization-closure-go-watch-no-go-report` (do NOT target main)
**Baseline:** `fix-admin-lab-lab-only-access-go` @ `2f5e39d`
**GO tag on merge:** `hotfix-lab-v2-notification-destination-routing-go`

## Problem

The Lab Workflow V2 in-app notification **"Model tiba di cabang"** (fired when a
delivery is completed) stored a single destination URL for a **mixed audience**.
The `create_lab_branch_requests` permission is held by branch operators
(Perawat / Admin Klinik) **and** by Admin Lab. The stored URL pointed at
`lab-workflow-requests.show` (`/lab/workflow-requests/{id}`) — the **branch**
request page — which `LabWorkflowRequestController@show` guards with branch
isolation (`$order->branch_id !== BranchContext::requireId()` → 404). Admin Lab
resolves to MAIN (not the order's branch), so the link **404'd for Admin Lab**.

Root cause: `LabDeliveryWorkflowService::completeDelivery()` built the URL
**ad hoc** with `route('lab-workflow-requests.show', …)` and used the same URL
for every recipient regardless of role/branch.

Every other V2 notification already used a correct route (`lab-v2-orders.show`,
`lab-pickup-tasks.show`, `lab-delivery-tasks.show`).

## Fix

1. **Canonical resolver** `App\Modules\LabOrder\Support\LabWorkflowNotificationDestinationResolver`
   — the single place a Lab Workflow notification destination is computed,
   **per recipient**. It returns an internal named-route URL the recipient can
   actually open, or `null` (link-less) when none is safe. No permission is ever
   widened to make a link openable.
2. **Routed fan-out** on `LabWorkflowNotificationService`
   (`notifyPermissionHoldersRouted` / `notifyUsersRouted`) resolve each
   recipient's own destination and send one database notification per recipient.
   All 9 lab notification call-sites (6 services) migrated off the deprecated
   string-URL methods — services no longer hand-build notification URLs.
3. **Open-redirect protection** in `NotificationController@read`: the stored URL
   is only followed when same-origin internal (rejects external hosts,
   `//host`, `javascript:` / `data:` / `vbscript:`).
4. **Idempotent repair command** `php artisan lab-workflow:repair-notification-destinations`
   (dry-run default; `--apply`, `--limit`, `--notification-id`, `--lab-order-id`,
   `--user-id`, `--json`, `--strict`). Scans only `LabWorkflowEvent`
   notifications, recomputes the correct destination via the same resolver
   per recipient, and rewrites **only** `data.url` under a row lock. Preserves
   id / read_at / created_at / notifiable / title / message / lab_order_id.
   Fails closed on a missing/soft-deleted order or missing recipient; skips
   legacy (non-V2) and unknown-event notifications.

## Destination matrix

| Event (title) | Audience | Destination |
|---|---|---|
| Tugas pickup baru | Courier (`manage_lab_pickups`) | `lab-pickup-tasks.show` |
| Model menuju lab | Lab staff (`manage_lab_orders`) | `lab-pickup-tasks.show` → v2 fallback |
| Penugasan produksi baru | Technician | `lab-v2-orders.show` |
| QC menunggu review | QC / Admin Lab | `lab-v2-orders.show` |
| QC gagal — rework | Technician | `lab-v2-orders.show` |
| Model selesai (QC lulus / eksternal) | Delivery Coord / Admin Lab | `lab-v2-orders.show` |
| Tugas pengiriman baru | Courier (`start_delivery`) | `lab-delivery-tasks.show` |
| **Model tiba di cabang** | **branch operator** of the order branch | `lab-workflow-requests.show` |
| **Model tiba di cabang** | **Admin Lab / lab staff** | `lab-v2-orders.show` **(fix)** |

## Guarantees

- **No migration, no new/removed permission, no route change, no role change.**
- Admin Lab stays **Lab-only** (`fix-admin-lab-lab-only-access-go` preserved).
- Branch recipients keep their branch-scoped page; cross-branch recipients fall
  back to their own queue index (never a 404 detail link).
- Workflow V2 remains canonical; legacy workflow stays inactive for new orders.
- KTP/NIK never rendered; notification stays in-app only (no auto-WhatsApp).

## Tests

- `tests/Feature/LabWorkflow/LabWorkflowNotificationDestinationRoutingTest.php` (17)
- `tests/Feature/LabWorkflow/LabWorkflowRepairNotificationDestinationsTest.php` (10)
- Full `tests/Feature/LabWorkflow` regression green (157 passed / 8 GD-skipped).

## Deploy note

No migration/seed needed. Post-deploy on VPS:

```
php artisan lab-workflow:repair-notification-destinations            # dry-run review
php artisan lab-workflow:repair-notification-destinations --apply    # persist
php artisan lab-workflow:repair-notification-destinations --strict   # verify 0 pending (exit 0)
```
