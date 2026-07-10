<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Notifications\LabWorkflowEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * LAB-WORKFLOW-V2 (Phase 5) — in-app notification fan-out.
 *
 * Notifies every active user holding one of the given permissions (direct or
 * via role). SAFETY RULES: in-app database channel only (never auto-WhatsApp),
 * failures are logged and NEVER break the workflow transition that triggered
 * them, and the payload carries no patient data or evidence content.
 */
class LabWorkflowNotificationService
{
    /** Upper bound so a mis-scoped permission can never fan out unbounded. */
    private const MAX_RECIPIENTS = 100;

    /**
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
            // A notification problem must never roll back / break the workflow.
            Log::warning('LabWorkflowNotificationService: gagal mengirim notifikasi in-app', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify specific users (e.g. the assigned technician). Same safety rules.
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
            Log::warning('LabWorkflowNotificationService: gagal mengirim notifikasi in-app', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
