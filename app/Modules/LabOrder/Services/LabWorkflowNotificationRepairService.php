<?php

namespace App\Modules\LabOrder\Services;

use App\Models\User;
use App\Modules\LabOrder\Models\LabDeliveryTask;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Models\LabPickupTask;
use App\Modules\LabOrder\Notifications\LabWorkflowEvent;
use App\Modules\LabOrder\Support\LabWorkflowNotificationDestinationResolver;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * HOTFIX-LAB-V2-NOTIFICATION-DESTINATION-ROUTING — safe, idempotent repair of
 * previously-stored Lab Workflow notifications whose `data.url` points at a
 * destination the recipient cannot open (e.g. the "Model tiba di cabang"
 * notification stored `/lab/workflow-requests/{id}` for Admin Lab, which 404s
 * on branch isolation).
 *
 * SAFETY GUARANTEES:
 *  - Only Lab Workflow notifications ({@see LabWorkflowEvent}) are ever scanned;
 *    non-Lab notifications are untouched.
 *  - The correct destination is recomputed with the SAME canonical resolver the
 *    live workflow now uses, PER RECIPIENT — so an already-correct notification
 *    (e.g. a branch operator's branch page) is never changed (idempotent).
 *  - Only the `data.url` value is rewritten. id / read_at / created_at /
 *    notifiable / title / message / lab_order_id are all preserved.
 *  - Fail closed: a missing order, missing recipient, non-V2 order, or unknown
 *    event title is SKIPPED (never blindly overwritten).
 *  - Each mutation runs in its own transaction with a row lock.
 */
class LabWorkflowNotificationRepairService
{
    /**
     * Notification title -> canonical resolver event key. Kept in sync with the
     * workflow services that emit each notification. A title not in this map is
     * skipped (unknown event → never touched).
     */
    private const TITLE_EVENT_MAP = [
        'Model tiba di cabang' => LabWorkflowNotificationDestinationResolver::EVENT_DELIVERED_TO_BRANCH,
        'Tugas pengiriman baru' => LabWorkflowNotificationDestinationResolver::EVENT_DELIVERY_TASK,
        'Tugas pickup baru' => LabWorkflowNotificationDestinationResolver::EVENT_PICKUP_TASK,
        'Model menuju lab' => LabWorkflowNotificationDestinationResolver::EVENT_MODEL_TO_LAB,
        'QC menunggu review' => LabWorkflowNotificationDestinationResolver::EVENT_QC_PENDING,
        'Model selesai (QC lulus)' => LabWorkflowNotificationDestinationResolver::EVENT_MODEL_DONE,
        'Model selesai (hasil eksternal diterima)' => LabWorkflowNotificationDestinationResolver::EVENT_MODEL_DONE,
        'QC gagal — rework' => LabWorkflowNotificationDestinationResolver::EVENT_QC_REWORK,
        'Penugasan produksi baru' => LabWorkflowNotificationDestinationResolver::EVENT_TECHNICIAN_ASSIGNED,
    ];

    private const MAX_SAMPLES = 50;

    public function __construct(
        private readonly LabWorkflowNotificationDestinationResolver $destinations,
    ) {}

    /**
     * @param  array{apply?: bool, limit?: int|null, notification_id?: string|null, lab_order_id?: int|null, user_id?: int|null}  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);

        $query = DatabaseNotification::query()
            ->where('type', LabWorkflowEvent::class)
            ->orderBy('created_at');

        if (! empty($options['notification_id'])) {
            $query->where('id', $options['notification_id']);
        }
        if (! empty($options['user_id'])) {
            $query->where('notifiable_id', (int) $options['user_id']);
        }
        if (! empty($options['limit'])) {
            $query->limit((int) $options['limit']);
        }

        $labOrderFilter = ! empty($options['lab_order_id']) ? (int) $options['lab_order_id'] : null;

        $summary = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'scanned' => 0,
            'repairable' => 0,
            'applied' => 0,
            'already_correct' => 0,
            'skipped_unknown_event' => 0,
            'skipped_non_v2' => 0,
            'skipped_missing_order' => 0,
            'skipped_missing_recipient' => 0,
            'anomalies' => 0,
            'samples' => [],
        ];

        $query->each(function (DatabaseNotification $row) use (&$summary, $apply, $labOrderFilter): void {
            $data = is_array($row->data) ? $row->data : (array) $row->data;

            $orderId = isset($data['lab_order_id']) ? (int) $data['lab_order_id'] : null;

            if ($labOrderFilter !== null && $orderId !== $labOrderFilter) {
                return; // outside the requested lab_order_id scope, not counted
            }

            $summary['scanned']++;

            $title = (string) ($data['title'] ?? '');
            $event = self::TITLE_EVENT_MAP[$title] ?? null;

            if ($event === null) {
                $summary['skipped_unknown_event']++;

                return;
            }

            if ($orderId === null) {
                $summary['skipped_missing_order']++;
                $summary['anomalies']++;

                return;
            }

            $order = LabOrder::find($orderId);
            if ($order === null) {
                // Fail closed: the resource is gone — never guess a destination.
                $summary['skipped_missing_order']++;
                $summary['anomalies']++;

                return;
            }

            // Legacy (non-V2) orders keep their legacy destination by design.
            if (! $order->isV2Workflow()) {
                $summary['skipped_non_v2']++;

                return;
            }

            $recipient = $row->notifiable;
            if (! $recipient instanceof User) {
                $summary['skipped_missing_recipient']++;
                $summary['anomalies']++;

                return;
            }

            $resolved = $this->destinations->resolve($recipient, $order, $event, $this->contextForOrder($event, $orderId));

            $storedUrl = isset($data['url']) ? (string) $data['url'] : null;

            if ($this->samePath($storedUrl, $resolved)) {
                $summary['already_correct']++;

                return;
            }

            $summary['repairable']++;

            if (count($summary['samples']) < self::MAX_SAMPLES) {
                $summary['samples'][] = [
                    'id' => $row->id,
                    'title' => $title,
                    'notifiable_id' => $recipient->id,
                    'lab_order_id' => $orderId,
                    'before' => $storedUrl,
                    'after' => $resolved,
                ];
            }

            if ($apply) {
                $this->applyRepair($row->id, $resolved);
                $summary['applied']++;
            }
        });

        return $summary;
    }

    /**
     * Rewrite ONLY data.url, under a row lock, in its own transaction. All other
     * fields (id, read_at, created_at, notifiable, title, message, lab_order_id)
     * are preserved untouched. Idempotent: re-running resolves to the same url.
     */
    private function applyRepair(string $notificationId, ?string $newUrl): void
    {
        DB::transaction(function () use ($notificationId, $newUrl): void {
            $locked = DatabaseNotification::query()->lockForUpdate()->find($notificationId);

            if ($locked === null) {
                return;
            }

            $data = is_array($locked->data) ? $locked->data : (array) $locked->data;
            $data['url'] = $newUrl;

            // Direct column update preserves read_at / created_at / notifiable.
            $locked->forceFill(['data' => $data])->save();
        });
    }

    /**
     * @return array{delivery_task_id?: int|null, pickup_task_id?: int|null}
     */
    private function contextForOrder(string $event, int $orderId): array
    {
        return match ($event) {
            LabWorkflowNotificationDestinationResolver::EVENT_DELIVERED_TO_BRANCH,
            LabWorkflowNotificationDestinationResolver::EVENT_DELIVERY_TASK => [
                'delivery_task_id' => LabDeliveryTask::query()->where('lab_order_id', $orderId)->value('id'),
            ],
            LabWorkflowNotificationDestinationResolver::EVENT_PICKUP_TASK,
            LabWorkflowNotificationDestinationResolver::EVENT_MODEL_TO_LAB => [
                'pickup_task_id' => LabPickupTask::query()->where('lab_order_id', $orderId)->value('id'),
            ],
            default => [],
        };
    }

    /**
     * Compare two URLs by path (+query), ignoring the host/scheme so an
     * env-dependent host difference is never mistaken for a broken destination.
     */
    private function samePath(?string $a, ?string $b): bool
    {
        return $this->pathOf($a) === $this->pathOf($b);
    }

    private function pathOf(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        if ($path === false || $path === null) {
            return null;
        }

        return $query ? $path.'?'.$query : $path;
    }
}
