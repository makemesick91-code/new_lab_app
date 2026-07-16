<?php

namespace App\Modules\Satusehat\Services;

use App\Models\User;
use App\Modules\Satusehat\Gateways\SatusehatGatewayInterface;
use App\Modules\Satusehat\Interfaces\SatusehatCandidateRepositoryInterface;
use App\Modules\Satusehat\Jobs\PrepareSatusehatSubmissionBatchJob;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatSubmissionBatch;
use App\Modules\Satusehat\Models\SatusehatSubmissionItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Prepares approved candidates into a submission batch for a FUTURE SATUSEHAT-2
 * send. In SATUSEHAT-1 this is FAIL-CLOSED: the batch is prepared locally but
 * the external submission never runs — the disabled gateway blocks it and the
 * attempt is audited as queue_attempted + queue_blocked. No network call is made.
 */
class SatusehatSubmissionService
{
    public function __construct(
        private readonly SatusehatCandidateRepositoryInterface $candidates,
        private readonly SatusehatReadinessService $readiness,
        private readonly SatusehatFhirPreviewBuilder $preview,
        private readonly SatusehatGatewayInterface $gateway,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * @param  list<int>  $candidateIds
     * @param  list<int>  $branchIds  allowed (RME-enabled) branch ids
     */
    public function prepare(array $candidateIds, array $branchIds, User $actor): SatusehatSubmissionBatch
    {
        // Server-side IDOR boundary: only in-scope candidates survive.
        $eligible = $this->candidates->idsInBranches($candidateIds, $branchIds)
            ->filter(fn (SatusehatCandidate $c) => $c->isApproved() && $c->isReady() && ! $c->isSourceChanged());

        if ($eligible->isEmpty()) {
            throw ValidationException::withMessages([
                'candidates' => 'Tidak ada kandidat yang disetujui & siap untuk disiapkan.',
            ]);
        }

        $branchIdSet = $eligible->pluck('branch_id')->unique();
        if ($branchIdSet->count() > 1) {
            throw ValidationException::withMessages([
                'candidates' => 'Pilih kandidat dari satu cabang untuk satu batch persiapan.',
            ]);
        }

        $env = (string) config('satusehat.environment');
        $branchId = (int) $branchIdSet->first();

        return DB::transaction(function () use ($eligible, $env, $branchId, $actor) {
            $batch = SatusehatSubmissionBatch::create([
                'environment' => $env,
                'branch_id' => $branchId,
                'requested_by' => $actor->id,
                'status' => SatusehatSubmissionBatch::STATUS_PREPARED,
                'prepared_at' => now(),
                'created_by' => $actor->id,
            ]);

            $resourceCount = 0;

            foreach ($eligible as $candidate) {
                $this->audit->log('satusehat_candidate', $candidate->id, SatusehatAuditLog::EVENT_QUEUE_ATTEMPTED,
                    'Permintaan penyiapan pengiriman', ['batch_id' => $batch->id], $candidate->branch_id, $actor);

                $preview = $this->preview->build($candidate->clinicVisit, $this->readiness->evaluate($candidate->clinicVisit));

                foreach ($preview['resources'] as $resource) {
                    if (! ($resource['supported'] ?? false)) {
                        continue;
                    }

                    SatusehatSubmissionItem::firstOrCreate(
                        ['idempotency_key' => $this->idempotencyKey($env, $candidate, $resource)],
                        [
                            'submission_batch_id' => $batch->id,
                            'satusehat_candidate_id' => $candidate->id,
                            'dependency_order' => $resource['order'],
                            'resource_type' => $resource['resource_type'],
                            'local_source_type' => 'clinic_visit',
                            'local_source_id' => $candidate->clinic_visit_id,
                            'source_hash' => $candidate->source_hash,
                            'payload_hash' => $resource['payload_hash'] ?? null,
                            'status' => SatusehatSubmissionItem::STATUS_PREPARED,
                        ]
                    );
                    $resourceCount++;
                }

                $candidate->update(['review_status' => SatusehatCandidate::REVIEW_QUEUED]);

                // FAIL-CLOSED: external submission is disabled — nothing is sent.
                if (! $this->gateway->isEnabled()) {
                    $this->audit->log('satusehat_candidate', $candidate->id, SatusehatAuditLog::EVENT_QUEUE_BLOCKED,
                        'Pengiriman eksternal dinonaktifkan (SATUSEHAT-1) — batch disiapkan lokal, tidak dikirim.',
                        ['batch_id' => $batch->id], $candidate->branch_id, $actor);
                }
            }

            $batch->update([
                'candidate_count' => $eligible->count(),
                'resource_count' => $resourceCount,
            ]);

            return $batch->refresh();
        });
    }

    public function cancel(SatusehatSubmissionBatch $batch, User $actor, ?string $reason = null): SatusehatSubmissionBatch
    {
        return DB::transaction(function () use ($batch, $actor, $reason) {
            $locked = SatusehatSubmissionBatch::query()->lockForUpdate()->findOrFail($batch->id);

            SatusehatCandidate::query()
                ->whereIn('id', $locked->items()->pluck('satusehat_candidate_id'))
                ->where('review_status', SatusehatCandidate::REVIEW_QUEUED)
                ->update(['review_status' => SatusehatCandidate::REVIEW_APPROVED]);

            $locked->update([
                'status' => SatusehatSubmissionBatch::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancellation_reason' => $reason,
            ]);

            $this->audit->log('submission_batch', $locked->id, SatusehatAuditLog::EVENT_CANCELLED,
                'Batch dibatalkan', [], $locked->branch_id, $actor);

            return $locked->refresh();
        });
    }

    /**
     * SATUSEHAT-2 — queue a PREPARED batch for outbound submission. Fail-closed:
     * if the runtime gateway is not fully enabled (send kill switch off /
     * disabled binding), queuing is refused with a clear message so nothing is
     * dispatched. On success the batch moves to QUEUED and the driver job is
     * dispatched AFTER commit (never inside the transaction).
     *
     * @param  list<int>  $branchIds
     */
    public function queue(SatusehatSubmissionBatch $batch, array $branchIds, User $actor): SatusehatSubmissionBatch
    {
        if (! in_array((int) $batch->branch_id, $branchIds, true)) {
            throw ValidationException::withMessages(['batch' => 'Batch di luar cakupan cabang Anda.']);
        }

        if (! $this->gateway->isEnabled()) {
            throw ValidationException::withMessages([
                'batch' => 'Pengiriman eksternal SATUSEHAT belum diaktifkan (send_enabled=false).',
            ]);
        }

        if (! SatusehatSubmissionStateMachine::batchCanTransition($batch->status, SatusehatSubmissionBatch::STATUS_QUEUED)) {
            throw ValidationException::withMessages([
                'batch' => 'Batch tidak dalam status yang dapat dikirim.',
            ]);
        }

        $correlationId = (string) Str::uuid();

        $batch = DB::transaction(function () use ($batch, $actor, $correlationId) {
            /** @var SatusehatSubmissionBatch $locked */
            $locked = SatusehatSubmissionBatch::query()->lockForUpdate()->findOrFail($batch->id);

            $locked->update([
                'status' => SatusehatSubmissionBatch::STATUS_QUEUED,
                'correlation_id' => $correlationId,
            ]);

            $this->audit->log('submission_batch', $locked->id, SatusehatAuditLog::EVENT_SUBMISSION_QUEUED,
                'Batch diantre untuk pengiriman sandbox.', ['correlation_id' => $correlationId], $locked->branch_id, $actor);

            return $locked;
        });

        // Dispatched AFTER the (outermost) transaction above commits. queue()
        // owns its own transaction and runs outside any request-level tx, so
        // dispatching here is post-commit — and it stays testable (DB::afterCommit
        // would never fire under the test's wrapping transaction).
        PrepareSatusehatSubmissionBatchJob::dispatch($batch->id, $correlationId);

        return $batch->refresh();
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function idempotencyKey(string $env, SatusehatCandidate $candidate, array $resource): string
    {
        return hash('sha256', implode('|', [
            $env,
            $candidate->id,
            $resource['resource_type'],
            $resource['order'],
            (string) $candidate->source_hash,
        ]));
    }
}
