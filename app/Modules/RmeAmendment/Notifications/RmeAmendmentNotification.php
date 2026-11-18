<?php

declare(strict_types=1);

namespace App\Modules\RmeAmendment\Notifications;

use Illuminate\Notifications\Notification;

/**
 * DMS-RME-COMPREHENSIVE-CLOSURE-1 memo M3.3 PR5 (rule 240 §7) — in-app
 * (database) notification about a supervised amendment: to the approvers when
 * one is requested, to the requester when it is rejected or applied.
 *
 * In-app ONLY, like RmeBillingDisputeNotification: no mail, WhatsApp or chat
 * channel. The payload carries ids only — a fixed title and message with the
 * amendment number, the link to its detail page (which is authorized on its
 * own) and the amendment id; never a patient name, an identity number, the
 * reason, the decision reason or the proposed content (CG-11). Notifications
 * are secondary: they are sent after commit, and a failed one changes nothing.
 */
class RmeAmendmentNotification extends Notification
{
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly string $url,
        private readonly int $amendmentId,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'rme_amendment_id' => $this->amendmentId,
        ];
    }
}
