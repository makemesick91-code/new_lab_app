<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatChangeRequest;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4D — governance change-control.
 *
 * Records and governs controlled changes (thresholds, scoring weights, wave
 * membership, pilot status, branch suspension, terminology activation, rollout
 * modes). No change request may enable production or external send during 4D:
 * the `production_guard_config` and `credential_state` categories can be logged
 * as intent but can NEVER be approved or applied here. Transactional, locked,
 * audited, PII-free payloads. Approver must differ from requester (separation
 * of duties).
 */
class SatusehatChangeControlService
{
    public function __construct(
        private readonly SatusehatAuditLogger $audit,
    ) {}

    private function env(): string
    {
        return (string) config('satusehat.environment');
    }

    public function create(array $data, User $actor): SatusehatChangeRequest
    {
        $category = (string) ($data['category'] ?? '');
        if (! in_array($category, (array) config('satusehat_pilot.change_control.categories', []), true)) {
            throw ValidationException::withMessages(['category' => 'Kategori change request tidak dikenal.']);
        }

        $reason = mb_substr(trim((string) ($data['reason'] ?? '')), 0, 1000);
        if (mb_strlen($reason) < (int) config('satusehat_pilot.change_control.min_reason_length', 10)) {
            throw ValidationException::withMessages(['reason' => 'Alasan change request wajib diisi (min. 10 karakter).']);
        }

        $scope = mb_substr(trim((string) ($data['scope'] ?? '')), 0, 500);
        if ($scope === '') {
            throw ValidationException::withMessages(['scope' => 'Scope change request wajib diisi.']);
        }

        $cr = SatusehatChangeRequest::create([
            'environment' => $this->env(),
            'category' => $category,
            'reason' => $reason,
            'scope' => $scope,
            'risk' => isset($data['risk']) ? mb_substr((string) $data['risk'], 0, 500) : null,
            'status' => SatusehatChangeRequest::STATUS_PENDING,
            'requested_by' => $actor->id,
            'effective_date' => $data['effective_date'] ?? null,
            'rollback_plan' => isset($data['rollback_plan']) ? mb_substr((string) $data['rollback_plan'], 0, 1000) : null,
            'payload' => $this->scalarPayload($data['payload'] ?? []),
        ]);

        $this->log($cr, SatusehatAuditLog::EVENT_CHANGE_REQUEST_CREATED, 'Change request dibuat', $actor);

        return $cr->refresh();
    }

    public function review(SatusehatChangeRequest $cr, User $actor): SatusehatChangeRequest
    {
        return DB::transaction(function () use ($cr, $actor) {
            $locked = SatusehatChangeRequest::query()->lockForUpdate()->findOrFail($cr->id);
            if ($locked->status !== SatusehatChangeRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'Change request tidak dalam status pending.']);
            }
            $locked->update([
                'status' => SatusehatChangeRequest::STATUS_REVIEWED,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ]);
            $this->log($locked, SatusehatAuditLog::EVENT_CHANGE_REQUEST_REVIEWED, 'Change request ditinjau', $actor);

            return $locked->refresh();
        });
    }

    public function approve(SatusehatChangeRequest $cr, User $actor): SatusehatChangeRequest
    {
        return DB::transaction(function () use ($cr, $actor) {
            $locked = SatusehatChangeRequest::query()->lockForUpdate()->findOrFail($cr->id);

            if (in_array($locked->category, (array) config('satusehat_pilot.change_control.blocked_categories', []), true)) {
                throw ValidationException::withMessages([
                    'category' => 'Kategori ini tidak dapat disetujui pada SATUSEHAT-4D — produksi/kredensial eksternal tetap terblokir.',
                ]);
            }
            if (! in_array($locked->status, [SatusehatChangeRequest::STATUS_PENDING, SatusehatChangeRequest::STATUS_REVIEWED], true)) {
                throw ValidationException::withMessages(['status' => 'Change request tidak dapat disetujui pada status ini.']);
            }
            if ((int) $locked->requested_by === (int) $actor->id) {
                throw ValidationException::withMessages(['approved_by' => 'Pemohon tidak boleh menyetujui change request-nya sendiri (segregation of duties).']);
            }

            $locked->update([
                'status' => SatusehatChangeRequest::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);
            $this->log($locked, SatusehatAuditLog::EVENT_CHANGE_REQUEST_APPROVED, 'Change request disetujui', $actor);

            return $locked->refresh();
        });
    }

    public function reject(SatusehatChangeRequest $cr, string $reason, User $actor): SatusehatChangeRequest
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < (int) config('satusehat_pilot.change_control.min_reason_length', 10)) {
            throw ValidationException::withMessages(['reason' => 'Alasan penolakan wajib diisi (min. 10 karakter).']);
        }

        return DB::transaction(function () use ($cr, $reason, $actor) {
            $locked = SatusehatChangeRequest::query()->lockForUpdate()->findOrFail($cr->id);
            if (in_array($locked->status, [SatusehatChangeRequest::STATUS_APPLIED, SatusehatChangeRequest::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages(['status' => 'Change request sudah final.']);
            }
            $locked->update(['status' => SatusehatChangeRequest::STATUS_REJECTED]);
            $this->log($locked, SatusehatAuditLog::EVENT_CHANGE_REQUEST_REJECTED, 'Change request ditolak', $actor, ['reason' => $reason]);

            return $locked->refresh();
        });
    }

    /** Mark an approved change request applied (blocked categories can never reach here). */
    public function markApplied(SatusehatChangeRequest $cr, User $actor): SatusehatChangeRequest
    {
        return DB::transaction(function () use ($cr, $actor) {
            $locked = SatusehatChangeRequest::query()->lockForUpdate()->findOrFail($cr->id);
            if (in_array($locked->category, (array) config('satusehat_pilot.change_control.blocked_categories', []), true)) {
                throw ValidationException::withMessages([
                    'category' => 'Kategori ini tidak dapat diterapkan pada SATUSEHAT-4D.',
                ]);
            }
            if ($locked->status !== SatusehatChangeRequest::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => 'Hanya change request yang disetujui yang dapat diterapkan.']);
            }
            $locked->update(['status' => SatusehatChangeRequest::STATUS_APPLIED, 'audit_reference' => 'applied:'.now()->timestamp]);
            $this->log($locked, SatusehatAuditLog::EVENT_CHANGE_REQUEST_APPLIED, 'Change request diterapkan', $actor);

            return $locked->refresh();
        });
    }

    /** Keep only scalar values in the payload (no PII/objects). */
    private function scalarPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }
        $clean = [];
        foreach ($payload as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $clean[(string) $k] = is_string($v) ? mb_substr($v, 0, 500) : $v;
            }
        }

        return $clean;
    }

    private function log(SatusehatChangeRequest $cr, string $event, string $summary, User $actor, array $context = []): void
    {
        $this->audit->log(
            'satusehat_change_request',
            (int) $cr->id,
            $event,
            $summary,
            $context + ['category' => $cr->category, 'status' => $cr->status],
            null,
            $actor,
        );
    }
}
