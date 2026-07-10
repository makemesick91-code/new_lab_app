<?php

namespace App\Modules\LabOrder\Notifications;

use Illuminate\Notifications\Notification;

/**
 * LAB-WORKFLOW-V2 — generic in-app (database) workflow event notification.
 *
 * In-app only by design: the DaengtisiaMS notification foundation is manual
 * WhatsApp templates, so NO auto-send WhatsApp/mail channel is ever attached.
 * Payload is minimal + non-sensitive (no patient data, no evidence binary).
 */
class LabWorkflowEvent extends Notification
{
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly ?string $url = null,
        private readonly ?int $labOrderId = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'url' => $this->url,
            'lab_order_id' => $this->labOrderId,
        ];
    }
}
