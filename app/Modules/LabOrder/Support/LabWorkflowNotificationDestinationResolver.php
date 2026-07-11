<?php

namespace App\Modules\LabOrder\Support;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\LabOrder\Models\LabOrder;
use Illuminate\Support\Facades\Route;

/**
 * HOTFIX-LAB-V2-NOTIFICATION-DESTINATION-ROUTING — the single canonical,
 * recipient-aware resolver for in-app Lab Workflow notification destinations.
 *
 * Durable rules enforced here:
 *  1. Notification URLs are NEVER built ad hoc in the workflow services; every
 *     Lab Workflow notification destination is resolved through this class.
 *  2. Destination is resolved PER RECIPIENT. The same event fanned out to Admin
 *     Lab and to a branch operator yields different, individually-openable
 *     routes: the Workflow V2 detail page for lab staff vs the recipient's own
 *     branch request page for a branch operator of THIS order's branch.
 *  3. Admin Lab is Lab-only — a Workflow V2 event never points Admin Lab at a
 *     branch/legacy route it cannot open (the branch page 404s on branch
 *     isolation). No permission is ever widened to make a link openable.
 *  4. Every returned URL is an internal named-route URL (route()) honoring
 *     config('app.url') — never a hardcoded host. When no safe destination
 *     exists the resolver returns null and the notification renders link-less
 *     (a broken/unauthorized link is never produced).
 *
 * Stateless + queue-safe: it takes only the recipient, the order, an event key
 * and scalar context ids, so callers may resolve the URL string just before
 * constructing the (database-only) notification.
 */
class LabWorkflowNotificationDestinationResolver
{
    // Lab-side pipeline events (lab staff / Admin Lab audience) -> V2 order detail.
    public const EVENT_MODEL_TO_LAB = 'model_to_lab';                 // pickup in transit to lab

    public const EVENT_TECHNICIAN_ASSIGNED = 'technician_assigned';

    public const EVENT_QC_PENDING = 'qc_pending';

    public const EVENT_QC_REWORK = 'qc_rework';

    public const EVENT_MODEL_DONE = 'model_done';                     // QC passed / external returned

    // Courier task events.
    public const EVENT_PICKUP_TASK = 'pickup_task';                   // pickup task detail (courier)

    public const EVENT_DELIVERY_TASK = 'delivery_task';               // delivery task detail (courier)

    // Mixed-audience: model delivered back to the branch (branch operators + lab staff).
    public const EVENT_DELIVERED_TO_BRANCH = 'delivered_to_branch';

    /** Every event key this resolver understands (used by the repair audit). */
    public const EVENTS = [
        self::EVENT_MODEL_TO_LAB,
        self::EVENT_TECHNICIAN_ASSIGNED,
        self::EVENT_QC_PENDING,
        self::EVENT_QC_REWORK,
        self::EVENT_MODEL_DONE,
        self::EVENT_PICKUP_TASK,
        self::EVENT_DELIVERY_TASK,
        self::EVENT_DELIVERED_TO_BRANCH,
    ];

    public function __construct(
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * Resolve the destination URL for one recipient, or null when no safe,
     * authorized, internal destination exists.
     *
     * @param  array{delivery_task_id?: int|null, pickup_task_id?: int|null}  $context
     */
    public function resolve(User $recipient, LabOrder $order, string $event, array $context = []): ?string
    {
        $deliveryTaskId = isset($context['delivery_task_id']) ? (int) $context['delivery_task_id'] : null;
        $pickupTaskId = isset($context['pickup_task_id']) ? (int) $context['pickup_task_id'] : null;

        return match ($event) {
            self::EVENT_PICKUP_TASK => $this->pickupTaskDetail($recipient, $pickupTaskId)
                ?? $this->pickupIndex($recipient),

            self::EVENT_MODEL_TO_LAB => $this->pickupTaskDetail($recipient, $pickupTaskId)
                ?? $this->v2OrderDetail($recipient, $order)
                ?? $this->v2OrderIndex($recipient),

            self::EVENT_DELIVERY_TASK => $this->deliveryTaskDetail($recipient, $deliveryTaskId)
                ?? $this->deliveryIndex($recipient),

            self::EVENT_TECHNICIAN_ASSIGNED,
            self::EVENT_QC_PENDING,
            self::EVENT_QC_REWORK,
            self::EVENT_MODEL_DONE => $this->v2OrderDetail($recipient, $order)
                ?? $this->v2OrderIndex($recipient),

            // Mixed audience: a branch operator of THIS order's branch gets the
            // branch request page; lab staff (Admin Lab) fall through to the V2
            // order detail, which they can always open (no branch isolation).
            self::EVENT_DELIVERED_TO_BRANCH => $this->branchRequestDetail($recipient, $order)
                ?? $this->v2OrderDetail($recipient, $order)
                ?? $this->branchRequestIndex($recipient)
                ?? $this->v2OrderIndex($recipient),

            default => null,
        };
    }

    // --- Candidate destinations (return a URL only when actually reachable) ---

    private function v2OrderDetail(User $recipient, LabOrder $order): ?string
    {
        if (! $order->isV2Workflow() || ! $this->routeExists('lab-v2-orders.show')) {
            return null;
        }

        if (! $recipient->canAny(['view_lab_orders', 'manage_lab_orders'])) {
            return null;
        }

        return route('lab-v2-orders.show', $order->id);
    }

    private function branchRequestDetail(User $recipient, LabOrder $order): ?string
    {
        if (! $order->isV2Workflow() || ! $this->routeExists('lab-workflow-requests.show')) {
            return null;
        }

        if (! $recipient->canAny(['create_lab_branch_requests', 'manage_lab_orders'])) {
            return null;
        }

        // The branch request page 404s unless the viewer's active branch equals
        // the order's branch (LabWorkflowRequestController@show isolation).
        if (! $this->recipientBranchMatches($recipient, $order)) {
            return null;
        }

        return route('lab-workflow-requests.show', $order->id);
    }

    private function pickupTaskDetail(User $recipient, ?int $pickupTaskId): ?string
    {
        if ($pickupTaskId === null || ! $this->routeExists('lab-pickup-tasks.show')) {
            return null;
        }

        if (! $recipient->canAny(['manage_lab_pickups', 'manage_lab_orders'])) {
            return null;
        }

        return route('lab-pickup-tasks.show', $pickupTaskId);
    }

    private function deliveryTaskDetail(User $recipient, ?int $deliveryTaskId): ?string
    {
        if ($deliveryTaskId === null || ! $this->routeExists('lab-delivery-tasks.show')) {
            return null;
        }

        if (! $recipient->canAny(['view_delivery', 'manage_delivery', 'manage_lab_orders'])) {
            return null;
        }

        return route('lab-delivery-tasks.show', $deliveryTaskId);
    }

    // --- Index fallbacks: the recipient's own queues, so they never 404. ------

    private function v2OrderIndex(User $recipient): ?string
    {
        return $this->routeExists('lab-v2-orders.index') && $recipient->canAny(['view_lab_orders', 'manage_lab_orders'])
            ? route('lab-v2-orders.index')
            : null;
    }

    private function branchRequestIndex(User $recipient): ?string
    {
        return $this->routeExists('lab-workflow-requests.index') && $recipient->canAny(['create_lab_branch_requests', 'manage_lab_orders'])
            ? route('lab-workflow-requests.index')
            : null;
    }

    private function pickupIndex(User $recipient): ?string
    {
        return $this->routeExists('lab-pickup-tasks.index') && $recipient->canAny(['manage_lab_pickups', 'manage_lab_orders'])
            ? route('lab-pickup-tasks.index')
            : null;
    }

    private function deliveryIndex(User $recipient): ?string
    {
        return $this->routeExists('lab-delivery-tasks.index') && $recipient->canAny(['view_delivery', 'manage_delivery', 'manage_lab_orders'])
            ? route('lab-delivery-tasks.index')
            : null;
    }

    private function recipientBranchMatches(User $recipient, LabOrder $order): bool
    {
        if ($order->branch_id === null) {
            return true; // an unbranched order has no branch-isolation gate
        }

        return $this->branchContext->forUser($recipient) === (int) $order->branch_id;
    }

    private function routeExists(string $name): bool
    {
        return Route::has($name);
    }
}
