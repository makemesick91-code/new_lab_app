<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Notifications\LabWorkflowEvent;
use App\Modules\LabOrder\Support\LabWorkflowNotificationDestinationResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * LAB-WORKFLOW-V2 (Phase 5) — in-app notification fan-out.
 *
 * Notifies every active user holding one of the given permissions (direct or
 * via role). SAFETY RULES: in-app database channel only (never auto-WhatsApp),
 * failures are logged and NEVER break the workflow transition that triggered
 * them, and the payload carries no patient data or evidence content.
 *
 * HOTFIX-LAB-V2-NOTIFICATION-DESTINATION-ROUTING: destinations are resolved
 * PER RECIPIENT through the canonical
 * {@see LabWorkflowNotificationDestinationResolver} via the *Routed methods —
 * services must NOT hand-build notification URLs. The legacy string-URL methods
 * are retained (deprecated) only for backward compatibility.
 */
class LabWorkflowNotificationService
{
    /** Upper bound so a mis-scoped permission can never fan out unbounded. */
    private const MAX_RECIPIENTS = 100;

    public function __construct(
        private readonly LabWorkflowNotificationDestinationResolver $destinations,
    ) {}

    /**
     * Notify every active permission holder, resolving each recipient's own
     * openable destination for the given workflow event.
     *
     * @param  list<string>  $permissions
     * @param  array{delivery_task_id?: int|null, pickup_task_id?: int|null}  $context
     */
    public function notifyPermissionHoldersRouted(array $permissions, string $title, string $message, LabOrder $order, string $event, array $context = []): void
    {
        try {
            $recipients = User::query()
                ->where('is_active', true)
                ->permission($permissions)
                ->limit(self::MAX_RECIPIENTS)
                ->get();

            $this->dispatchRouted($recipients, $title, $message, $order, $event, $context);
        } catch (\Throwable $e) {
            $this->logFailure($title, $e);
        }
    }

    /**
     * Notify specific users (e.g. the assigned technician), resolving each
     * recipient's own openable destination for the given workflow event.
     *
     * @param  iterable<User|null>  $users
     * @param  array{delivery_task_id?: int|null, pickup_task_id?: int|null}  $context
     */
    public function notifyUsersRouted(iterable $users, string $title, string $message, LabOrder $order, string $event, array $context = []): void
    {
        try {
            $targets = collect($users)->filter()->unique('id')->take(self::MAX_RECIPIENTS);

            $this->dispatchRouted($targets, $title, $message, $order, $event, $context);
        } catch (\Throwable $e) {
            $this->logFailure($title, $e);
        }
    }

    /**
     * Send one database notification per recipient with a recipient-resolved
     * (or null) destination URL. Database channel only, small bounded set.
     *
     * @param  iterable<mixed>  $recipients
     * @param  array{delivery_task_id?: int|null, pickup_task_id?: int|null}  $context
     */
    private function dispatchRouted(iterable $recipients, string $title, string $message, LabOrder $order, string $event, array $context): void
    {
        foreach ($recipients as $recipient) {
            if (! $recipient instanceof User) {
                continue;
            }

            $url = $this->destinations->resolve($recipient, $order, $event, $context);

            $recipient->notify(new LabWorkflowEvent($title, $message, $url, $order->id));
        }
    }

    /**
     * @deprecated Use {@see notifyPermissionHoldersRouted()} — do not hand-build
     *             notification URLs. Retained for backward compatibility only.
     *
     * @param  list<string>  $permissions
     */
    public function notifyPermissionHolders(array $permissions, string $title, string $message, ?string $url = null, ?LabOrder $order = null): void
    {
        try {
            $recipients = User::query()
                ->where('is_active', true)
                ->permission($permissions)
                ->limit(self::MAX_RECIPIENTS)
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, new LabWorkflowEvent($title, $message, $url, $order?->id));
        } catch (\Throwable $e) {
            $this->logFailure($title, $e);
        }
    }

    /**
     * @deprecated Use {@see notifyUsersRouted()} — do not hand-build
     *             notification URLs. Retained for backward compatibility only.
     *
     * @param  iterable<User|null>  $users
     */
    public function notifyUsers(iterable $users, string $title, string $message, ?string $url = null, ?LabOrder $order = null): void
    {
        try {
            $targets = collect($users)->filter()->unique('id')->take(self::MAX_RECIPIENTS);

            if ($targets->isEmpty()) {
                return;
            }

            Notification::send($targets, new LabWorkflowEvent($title, $message, $url, $order?->id));
        } catch (\Throwable $e) {
            $this->logFailure($title, $e);
        }
    }

    private function logFailure(string $title, \Throwable $e): void
    {
        // A notification problem must never roll back / break the workflow.
        Log::warning('LabWorkflowNotificationService: gagal mengirim notifikasi in-app', [
            'title' => $title,
            'error' => $e->getMessage(),
        ]);
    }
}
