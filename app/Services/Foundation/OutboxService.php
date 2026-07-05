<?php

namespace App\Services\Foundation;

use App\Models\Foundation\OutboxEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * QUEUE-1 — Outbox foundation service.
 *
 * Creates/transitions safe outbox event records. No domain event is wired
 * to this service yet, and dispatch/external dispatch stay disabled per
 * config/queue_governance.php (dispatch_enabled=false, external_dispatch_
 * enabled=false) regardless of what a caller passes in.
 */
class OutboxService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createSafeEvent(
        string $eventType,
        array $payload,
        string $payloadClassification,
        ?string $aggregateType = null,
        ?string $aggregateId = null,
        string $eventVersion = 'v1',
        ?string $idempotencyKeyHash = null,
    ): OutboxEvent {
        $this->assertAllowedCategory($payloadClassification);
        $this->assertSafePayload($payload);

        return DB::transaction(function () use ($eventType, $payload, $payloadClassification, $aggregateType, $aggregateId, $eventVersion, $idempotencyKeyHash) {
            return OutboxEvent::create([
                'event_uuid' => (string) Str::uuid(),
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'event_type' => $eventType,
                'event_version' => $eventVersion,
                'status' => OutboxEvent::STATUS_PENDING,
                'payload' => $payload,
                'payload_classification' => $payloadClassification,
                'idempotency_key_hash' => $idempotencyKeyHash,
                'available_at' => now(),
                'attempts' => 0,
                'max_attempts' => (int) config('queue_governance.outbox.max_attempts', 5),
            ]);
        });
    }

    public function markProcessing(string $eventUuid): ?OutboxEvent
    {
        return DB::transaction(function () use ($eventUuid) {
            $event = OutboxEvent::query()->where('event_uuid', $eventUuid)->lockForUpdate()->first();
            if ($event === null) {
                return null;
            }

            $event->update([
                'status' => OutboxEvent::STATUS_PROCESSING,
                'attempts' => $event->attempts + 1,
                'locked_until' => now()->addMinutes(5),
            ]);

            return $event->refresh();
        });
    }

    public function markDispatched(string $eventUuid): ?OutboxEvent
    {
        if (! app(FeatureFlagService::class)->enabled('foundation.queue.external_dispatch_enabled')) {
            throw new InvalidArgumentException('External dispatch is disabled by governance (foundation.queue.external_dispatch_enabled=false).');
        }

        return DB::transaction(function () use ($eventUuid) {
            $event = OutboxEvent::query()->where('event_uuid', $eventUuid)->lockForUpdate()->first();
            if ($event === null) {
                return null;
            }

            $event->update([
                'status' => OutboxEvent::STATUS_DISPATCHED,
                'dispatched_at' => now(),
                'locked_until' => null,
            ]);

            return $event->refresh();
        });
    }

    public function markFailed(string $eventUuid, ?string $errorCode = null, ?string $errorSummary = null): ?OutboxEvent
    {
        return DB::transaction(function () use ($eventUuid, $errorCode, $errorSummary) {
            $event = OutboxEvent::query()->where('event_uuid', $eventUuid)->lockForUpdate()->first();
            if ($event === null) {
                return null;
            }

            $status = $event->attempts >= $event->max_attempts ? OutboxEvent::STATUS_FAILED : OutboxEvent::STATUS_PENDING;

            $event->update([
                'status' => $status,
                'last_error_code' => $errorCode !== null ? substr($errorCode, 0, 64) : null,
                'last_error_summary' => $errorSummary !== null ? substr($errorSummary, 0, 500) : null,
                'failed_at' => $status === OutboxEvent::STATUS_FAILED ? now() : null,
                'locked_until' => null,
            ]);

            return $event->refresh();
        });
    }

    /**
     * @return Collection<int, OutboxEvent>
     */
    public function pending(int $limit = 50)
    {
        return OutboxEvent::query()
            ->where('status', OutboxEvent::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $byStatus = OutboxEvent::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $byClassification = OutboxEvent::query()
            ->selectRaw('payload_classification, count(*) as total')
            ->groupBy('payload_classification')
            ->pluck('total', 'payload_classification')
            ->all();

        $total = (int) OutboxEvent::query()->count();
        $denied = (array) config('queue_governance.outbox.denied_event_categories', []);

        $unsafeClassifications = array_intersect(array_keys($byClassification), $denied);

        $checks = [];
        $checks[] = $this->pass('OUTBOX-TABLE-READABLE', 'sys_outbox_events table is readable.');
        $checks[] = $unsafeClassifications === []
            ? $this->pass('OUTBOX-NO-DENIED-CLASSIFICATION-ROWS', 'No stored event uses a denied payload classification.')
            : $this->fail('OUTBOX-NO-DENIED-CLASSIFICATION-ROWS', 'Denied classification(s) present in outbox: '.implode(', ', $unsafeClassifications));

        $dispatchedWithoutFlag = ! app(FeatureFlagService::class)->enabled('foundation.queue.external_dispatch_enabled')
            && ($byStatus[OutboxEvent::STATUS_DISPATCHED] ?? 0) > 0;
        $checks[] = ! $dispatchedWithoutFlag
            ? $this->pass('OUTBOX-NO-DISPATCH-WITHOUT-FLAG', 'No dispatched events exist while external dispatch flag is off.')
            : $this->fail('OUTBOX-NO-DISPATCH-WITHOUT-FLAG', 'Dispatched events found while external dispatch flag is off — investigate.');

        $errors = count(array_filter($checks, fn (array $c) => $c['status'] === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => $c['status'] === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => $c['status'] === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'sprint' => 'QUEUE-1',
            'total_records' => $total,
            'by_status' => $byStatus,
            'by_payload_classification' => $byClassification,
            'dispatch_enabled' => (bool) config('queue_governance.outbox.dispatch_enabled', false),
            'external_dispatch_enabled' => app(FeatureFlagService::class)->enabled('foundation.queue.external_dispatch_enabled'),
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    private function assertAllowedCategory(string $classification): void
    {
        $allowed = (array) config('queue_governance.outbox.allowed_event_categories', []);
        $denied = (array) config('queue_governance.outbox.denied_event_categories', []);

        if (in_array($classification, $denied, true)) {
            throw new InvalidArgumentException("Payload classification \"{$classification}\" is denied by outbox governance.");
        }

        if (! in_array($classification, $allowed, true)) {
            throw new InvalidArgumentException("Payload classification \"{$classification}\" is not in the allowed outbox category list.");
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSafePayload(array $payload): void
    {
        $forbidden = ['ktp', 'nik', 'password', 'token', 'secret', 'email', 'phone', 'address'];

        foreach (array_keys($payload) as $key) {
            foreach ($forbidden as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    throw new InvalidArgumentException("Outbox payload key \"{$key}\" looks like PII/secret and is not allowed.");
                }
            }
        }
    }

    private function pass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'blocking' => false, 'message' => $message];
    }

    private function fail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'blocking' => true, 'message' => $message];
    }
}
