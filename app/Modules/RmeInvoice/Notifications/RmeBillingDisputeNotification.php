<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Rule 203 — in-app (database) notification of a billing dispute escalation.
 *
 * In-app ONLY, by design: no mail, WhatsApp or chat channel is ever attached
 * (the dispute concerns a patient invoice). The payload is minimal and
 * non-sensitive: dispute and invoice numbers, branch code, status — never a
 * patient name, NIK, medical record number, amount detail or the description.
 *
 * Notifications are SECONDARY. The dispute row and its payment hold are the
 * control; a failed notification never releases anything.
 */
class RmeBillingDisputeNotification extends Notification
{
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly string $url,
        private readonly int $disputeId,
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
            'rme_billing_dispute_id' => $this->disputeId,
        ];
    }
}
