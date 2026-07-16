<?php

namespace App\Modules\Satusehat\Services;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Satusehat\Exceptions\SatusehatBuildException;
use App\Modules\Satusehat\Exceptions\SatusehatGatewayException;
use App\Modules\Satusehat\Gateways\SatusehatApiResponse;
use App\Modules\Satusehat\Gateways\SatusehatGatewayInterface;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatSubmissionBatch;
use App\Modules\Satusehat\Models\SatusehatSubmissionItem;
use App\Modules\Satusehat\Support\SatusehatOutcome;
use App\Modules\Satusehat\Support\SatusehatSourceHasher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The outbound processing core (SATUSEHAT-2). It NEVER holds a DB transaction
 * open across a network call: it (1) claims an item under a lock and builds an
 * immutable payload, (2) performs the gateway call OUTSIDE any transaction, then
 * (3) records the classified outcome under a fresh lock. Every step is
 * idempotent, source-hash-revalidated, and audited (PII-free).
 *
 * A successfully-sent resource is never resent; an ambiguous outcome becomes
 * reconciliation_required, never a blind re-POST.
 */
class SatusehatSubmissionProcessingService
{
    public function __construct(
        private readonly SatusehatReadinessService $readiness,
        private readonly SatusehatSourceHasher $hasher,
        private readonly SatusehatFhirResourceBuilder $builder,
        private readonly SatusehatGatewayInterface $gateway,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * Process a single submission item end-to-end. Returns the refreshed item.
     */
    public function processItem(int $itemId, string $correlationId): ?SatusehatSubmissionItem
    {
        // Fail-closed: if the runtime gateway is not fully enabled (kill switch
        // off / disabled binding / no token provider), nothing is claimed or
        // sent — the item stays queued and the attempt is audited.
        if (! $this->gateway->isEnabled()) {
            $this->audit->log('submission_item', $itemId, SatusehatAuditLog::EVENT_SEND_BLOCKED,
                'Pengiriman diblokir — integrasi/kill switch nonaktif.', ['correlation_id' => $correlationId], null, null);

            return SatusehatSubmissionItem::find($itemId);
        }

        $claim = $this->claim($itemId, $correlationId);
        if ($claim === null) {
            return SatusehatSubmissionItem::find($itemId);
        }

        // --- Network call OUTSIDE any transaction ---
        try {
            $response = $claim['operation'] === SatusehatSubmissionItem::OPERATION_UPDATE
                ? $this->gateway->updateResource($claim['resource_type'], $claim['remote_id'], $claim['payload'], $correlationId)
                : $this->gateway->createResource($claim['resource_type'], $claim['payload'], $correlationId);
        } catch (SatusehatGatewayException $e) {
            $response = match (true) {
                $e->isRetryable() => SatusehatApiResponse::retryable($e->getMessage(), $e->httpStatus, $correlationId),
                $e->isPermanent() => SatusehatApiResponse::permanent($e->getMessage(), $e->httpStatus, $correlationId),
                default => SatusehatApiResponse::unknown($e->getMessage(), $e->httpStatus, $correlationId),
            };
        }

        return $this->record($itemId, $response, $correlationId);
    }

    /**
     * Reconcile an unknown-outcome item: if we captured a remote id, GET it and
     * confirm; otherwise it stays reconciliation_required for operator review.
     * Never performs a blind re-POST.
     */
    public function reconcileItem(int $itemId, string $correlationId): ?SatusehatSubmissionItem
    {
        /** @var SatusehatSubmissionItem|null $item */
        $item = SatusehatSubmissionItem::find($itemId);
        if ($item === null || ! $item->needsReconciliation()) {
            return $item;
        }

        if (blank($item->remote_resource_id)) {
            // No remote id captured — cannot verify remotely. Keep for operator.
            $this->transitionItem($item, SatusehatSubmissionItem::STATUS_RECONCILIATION_REQUIRED,
                'Tidak ada id remote — perlu tinjauan operator.', SatusehatAuditLog::EVENT_RECONCILIATION_REQUIRED, $correlationId);

            return $item->refresh();
        }

        $response = $this->gateway->getResource($item->resource_type, $item->remote_resource_id, $correlationId);

        return DB::transaction(function () use ($item, $response, $correlationId) {
            $locked = SatusehatSubmissionItem::query()->lockForUpdate()->find($item->id);
            if ($locked === null) {
                return $item;
            }

            if ($response->isSuccess()) {
                $this->transitionItem($locked, SatusehatSubmissionItem::STATUS_RECONCILED,
                    'Rekonsiliasi berhasil — resource ditemukan di SATUSEHAT.', SatusehatAuditLog::EVENT_RESOURCE_RECONCILED, $correlationId, [
                        'http_status' => $response->httpStatus,
                        'remote_version_id' => $response->remoteVersionId,
                        'reconciled_at' => now(),
                    ]);
            } else {
                $this->transitionItem($locked, SatusehatSubmissionItem::STATUS_RECONCILIATION_REQUIRED,
                    'Rekonsiliasi belum tuntas — status remote belum pasti.', SatusehatAuditLog::EVENT_RECONCILIATION_REQUIRED, $correlationId);
            }

            return $locked->refresh();
        });
    }

    /**
     * Recompute a batch's rollup status + counts from its items.
     */
    public function rollupBatch(int $batchId): ?SatusehatSubmissionBatch
    {
        return DB::transaction(function () use ($batchId) {
            /** @var SatusehatSubmissionBatch|null $batch */
            $batch = SatusehatSubmissionBatch::query()->lockForUpdate()->find($batchId);
            if ($batch === null) {
                return null;
            }

            $items = $batch->items()->get();
            $sendable = $items->reject(fn (SatusehatSubmissionItem $i) => in_array($i->status, [
                SatusehatSubmissionItem::STATUS_BLOCKED,
                SatusehatSubmissionItem::STATUS_CANCELLED,
            ], true));

            $succeeded = $sendable->filter(fn ($i) => $i->isSucceeded())->count();
            $failed = $sendable->filter(fn ($i) => $i->status === SatusehatSubmissionItem::STATUS_PERMANENT_FAILED)->count();
            $unknown = $sendable->filter(fn ($i) => $i->needsReconciliation())->count();
            $total = $sendable->count();

            $status = match (true) {
                $total === 0 => $batch->status,
                $unknown > 0 => SatusehatSubmissionBatch::STATUS_RECONCILIATION_REQUIRED,
                $succeeded === $total => SatusehatSubmissionBatch::STATUS_SUCCEEDED,
                $succeeded === 0 && $failed === $total => SatusehatSubmissionBatch::STATUS_FAILED,
                $succeeded > 0 => SatusehatSubmissionBatch::STATUS_PARTIAL,
                default => SatusehatSubmissionBatch::STATUS_PROCESSING,
            };

            $batch->update([
                'succeeded_count' => $succeeded,
                'failed_count' => $failed,
                'unknown_count' => $unknown,
                'status' => SatusehatSubmissionStateMachine::batchCanTransition($batch->status, $status) ? $status : $batch->status,
                'completed_at' => in_array($status, [
                    SatusehatSubmissionBatch::STATUS_SUCCEEDED,
                    SatusehatSubmissionBatch::STATUS_FAILED,
                ], true) ? now() : $batch->completed_at,
            ]);

            return $batch->refresh();
        });
    }

    /**
     * Claim an item under a lock: validate terminal/source-drift/dependency, set
     * PROCESSING, and build the immutable payload. Returns null to skip.
     *
     * @return array{resource_type: string, operation: string, remote_id: string, payload: array<string, mixed>}|null
     */
    private function claim(int $itemId, string $correlationId): ?array
    {
        return DB::transaction(function () use ($itemId, $correlationId) {
            /** @var SatusehatSubmissionItem|null $item */
            $item = SatusehatSubmissionItem::query()->lockForUpdate()->find($itemId);

            if ($item === null || $item->isTerminal() || $item->isSucceeded()) {
                return null; // idempotent: never resend a settled item.
            }

            // Items must be QUEUED (by the Prepare job) before processing.
            if (! in_array($item->status, [
                SatusehatSubmissionItem::STATUS_QUEUED,
                SatusehatSubmissionItem::STATUS_RETRYABLE_FAILED,
                SatusehatSubmissionItem::STATUS_RECONCILIATION_REQUIRED,
            ], true)) {
                return null;
            }

            // Dependency check BEFORE claiming: a Condition/Procedure needs a
            // succeeded Encounter. Not ready → leave queued (no state change).
            $encounterRemoteId = null;
            if ($item->resource_type !== SatusehatSubmissionItem::RESOURCE_ENCOUNTER) {
                $encounter = $this->encounterItemFor($item);
                if ($encounter === null || ! $encounter->isSucceeded() || blank($encounter->remote_resource_id)) {
                    return null;
                }
                $encounterRemoteId = (string) $encounter->remote_resource_id;
            }

            // Claim → PROCESSING first, so every subsequent failure transition
            // (source drift, build failure) is a legal PROCESSING → * edge.
            $this->transitionItem($item, SatusehatSubmissionItem::STATUS_PROCESSING,
                'Mulai pengiriman resource.', SatusehatAuditLog::EVENT_SUBMISSION_STARTED, $correlationId, [
                    'attempt_count' => $item->attempt_count + 1,
                    'correlation_id' => $correlationId,
                    'locked_at' => now(),
                    'locked_by' => 'worker',
                ]);

            /** @var SatusehatCandidate|null $candidate */
            $candidate = $item->candidate;
            $visit = $candidate?->clinicVisit;
            if ($candidate === null || $visit === null) {
                $this->transitionItem($item, SatusehatSubmissionItem::STATUS_PERMANENT_FAILED,
                    'Kandidat/kunjungan tidak ditemukan.', SatusehatAuditLog::EVENT_RESOURCE_PERMANENT_FAILED, $correlationId);

                return null;
            }

            $facts = $this->readiness->evaluate($visit)->facts;

            // Source drift → abort (never send fabricated/stale data).
            if ($this->hasher->hash($facts) !== $item->source_hash) {
                $this->transitionItem($item, SatusehatSubmissionItem::STATUS_PERMANENT_FAILED,
                    'Sumber klinis berubah sejak persiapan — pengiriman dibatalkan.', SatusehatAuditLog::EVENT_SOURCE_DRIFT_ABORTED, $correlationId);

                return null;
            }

            // Build the immutable payload (terminology only from active mappings).
            try {
                [$operation, $remoteId, $payload] = $this->buildPayload($item, $visit, $facts, $encounterRemoteId);
            } catch (SatusehatBuildException $e) {
                $this->transitionItem($item, SatusehatSubmissionItem::STATUS_PERMANENT_FAILED,
                    $e->getMessage(), SatusehatAuditLog::EVENT_RESOURCE_PERMANENT_FAILED, $correlationId);

                return null;
            }

            $item->forceFill([
                'operation_type' => $operation,
                'request_payload_hash' => hash('sha256', json_encode($payload) ?: ''),
            ])->save();

            return [
                'resource_type' => $item->resource_type,
                'operation' => $operation,
                'remote_id' => (string) $remoteId,
                'payload' => $payload,
            ];
        });
    }

    /**
     * Record the classified outcome under a fresh lock.
     */
    private function record(int $itemId, SatusehatApiResponse $response, string $correlationId): ?SatusehatSubmissionItem
    {
        return DB::transaction(function () use ($itemId, $response, $correlationId) {
            /** @var SatusehatSubmissionItem|null $item */
            $item = SatusehatSubmissionItem::query()->lockForUpdate()->find($itemId);
            if ($item === null) {
                return null;
            }

            $common = [
                'http_status' => $response->httpStatus,
                'outcome_classification' => $response->outcome,
                'operation_outcome' => $response->issues,
                'correlation_id' => $correlationId,
            ];

            [$status, $event, $summary, $extra] = match ($response->outcome) {
                SatusehatOutcome::SUCCESS => [
                    SatusehatSubmissionItem::STATUS_SUCCEEDED,
                    SatusehatAuditLog::EVENT_RESOURCE_SUBMITTED,
                    'Resource berhasil dikirim.',
                    [
                        'remote_resource_id' => $response->remoteResourceId,
                        'remote_version_id' => $response->remoteVersionId,
                        'submitted_at' => now(),
                        'next_attempt_at' => null,
                        'locked_at' => null,
                    ],
                ],
                SatusehatOutcome::RETRYABLE => [
                    SatusehatSubmissionItem::STATUS_RETRYABLE_FAILED,
                    SatusehatAuditLog::EVENT_RESOURCE_RETRYABLE_FAILED,
                    'Kegagalan sementara — dijadwalkan ulang.',
                    ['next_attempt_at' => $this->nextAttemptAt($item, $response->retryAfterSeconds), 'locked_at' => null],
                ],
                SatusehatOutcome::PERMANENT => [
                    SatusehatSubmissionItem::STATUS_PERMANENT_FAILED,
                    SatusehatAuditLog::EVENT_RESOURCE_PERMANENT_FAILED,
                    'Ditolak permanen oleh SATUSEHAT.',
                    ['locked_at' => null],
                ],
                default => [
                    SatusehatSubmissionItem::STATUS_UNKNOWN_OUTCOME,
                    SatusehatAuditLog::EVENT_RESOURCE_UNKNOWN_OUTCOME,
                    'Hasil tidak dapat dipastikan — perlu rekonsiliasi.',
                    ['locked_at' => null],
                ],
            };

            $this->transitionItem($item, $status, $summary, $event, $correlationId, $common + $extra);

            return $item->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{0: string, 1: ?string, 2: array<string, mixed>}
     */
    private function buildPayload(SatusehatSubmissionItem $item, ClinicVisit $visit, array $facts, ?string $encounterRemoteId): array
    {
        if ($item->resource_type === SatusehatSubmissionItem::RESOURCE_ENCOUNTER) {
            return [SatusehatSubmissionItem::OPERATION_CREATE, null, $this->builder->buildEncounter($visit, $facts)];
        }

        if ($item->resource_type === SatusehatSubmissionItem::RESOURCE_PROCEDURE) {
            $treatments = $facts['treatments'] ?? [];
            $index = max(0, (int) $item->dependency_order - 2);
            $treatment = $treatments[$index] ?? null;
            if (! is_array($treatment)) {
                throw new SatusehatBuildException('Tindakan untuk item Procedure tidak ditemukan.');
            }

            return [SatusehatSubmissionItem::OPERATION_CREATE, null, $this->builder->buildProcedure($visit, $treatment, $facts, (string) $encounterRemoteId)];
        }

        // Condition: no structured diagnosis source → nothing to build.
        throw new SatusehatBuildException('Resource '.$item->resource_type.' belum didukung untuk pengiriman.');
    }

    private function encounterItemFor(SatusehatSubmissionItem $item): ?SatusehatSubmissionItem
    {
        return SatusehatSubmissionItem::query()
            ->where('submission_batch_id', $item->submission_batch_id)
            ->where('satusehat_candidate_id', $item->satusehat_candidate_id)
            ->where('resource_type', SatusehatSubmissionItem::RESOURCE_ENCOUNTER)
            ->first();
    }

    private function nextAttemptAt(SatusehatSubmissionItem $item, ?int $retryAfterSeconds): Carbon
    {
        if ($retryAfterSeconds !== null) {
            return now()->addSeconds(max(1, $retryAfterSeconds));
        }

        $base = max(1, (int) config('satusehat.retry.base_delay_seconds', 5));
        $maxDelay = max($base, (int) config('satusehat.retry.max_delay_seconds', 300));
        $exp = min($maxDelay, $base * (2 ** max(0, $item->attempt_count)));
        // Deterministic jitter derived from the item id (no Math.random dependency).
        $jitter = $item->id % max(1, (int) ($base));

        return now()->addSeconds(min($maxDelay, $exp + $jitter));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transitionItem(SatusehatSubmissionItem $item, string $to, string $summary, string $event, string $correlationId, array $attributes = []): void
    {
        if (! SatusehatSubmissionStateMachine::itemCanTransition($item->status, $to)) {
            return; // reject illegal transition silently (audited state stays consistent).
        }

        if (isset($attributes['attempt_count'])) {
            $item->attempt_count = (int) $attributes['attempt_count'];
            unset($attributes['attempt_count']);
        }

        $item->fill($attributes);
        $item->status = $to;
        $item->save();

        $this->audit->log('submission_item', $item->id, $event, $summary, [
            'batch_id' => $item->submission_batch_id,
            'resource_type' => $item->resource_type,
            'status' => $to,
            'correlation_id' => $correlationId,
            'http_status' => $item->http_status,
        ], null, null);
    }
}
