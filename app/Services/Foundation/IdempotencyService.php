<?php

namespace App\Services\Foundation;

use App\Models\Foundation\IdempotencyKey;
use Illuminate\Support\Facades\DB;

/**
 * QUEUE-1 — Idempotency foundation service.
 *
 * Reserve/complete/fail a scoped idempotency key without ever storing the
 * raw key (only its SHA-256 hash). Not wired to any domain flow yet — this
 * is governance/readiness infrastructure for future queue jobs/outbox
 * dispatch per config/queue_governance.php.
 */
class IdempotencyService
{
    public function hashKey(string $rawKey): string
    {
        return hash('sha256', $rawKey);
    }

    /**
     * Reserve a key for a scope. If an active (non-expired) reservation
     * already exists, it is returned as-is (conflict-safe, no reprocessing).
     *
     * @param  array<string, mixed>  $metadata
     * @return array{created: bool, record: IdempotencyKey}
     */
    public function reserve(string $scope, string $rawKey, array $metadata = [], ?int $ttlSeconds = 3600): array
    {
        $keyHash = $this->hashKey($rawKey);

        return DB::transaction(function () use ($scope, $keyHash, $metadata, $ttlSeconds) {
            $existing = IdempotencyKey::query()
                ->where('key_hash', $keyHash)
                ->where('scope', $scope)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && ! $this->isExpired($existing)) {
                return ['created' => false, 'record' => $existing];
            }

            $now = now();
            $expiresAt = $ttlSeconds !== null ? $now->copy()->addSeconds($ttlSeconds) : null;

            if ($existing !== null) {
                $existing->update([
                    'status' => IdempotencyKey::STATUS_RESERVED,
                    'metadata' => $this->safeMetadata($metadata),
                    'locked_until' => $expiresAt,
                    'expires_at' => $expiresAt,
                    'completed_at' => null,
                    'failed_at' => null,
                ]);

                return ['created' => true, 'record' => $existing->refresh()];
            }

            $record = IdempotencyKey::create([
                'key_hash' => $keyHash,
                'scope' => $scope,
                'status' => IdempotencyKey::STATUS_RESERVED,
                'metadata' => $this->safeMetadata($metadata),
                'locked_until' => $expiresAt,
                'expires_at' => $expiresAt,
            ]);

            return ['created' => true, 'record' => $record];
        });
    }

    public function complete(string $scope, string $rawKey, ?string $responseFingerprint = null): ?IdempotencyKey
    {
        $keyHash = $this->hashKey($rawKey);

        return DB::transaction(function () use ($scope, $keyHash, $responseFingerprint) {
            $record = IdempotencyKey::query()
                ->where('key_hash', $keyHash)
                ->where('scope', $scope)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return null;
            }

            $record->update([
                'status' => IdempotencyKey::STATUS_COMPLETED,
                'response_fingerprint_hash' => $responseFingerprint !== null ? hash('sha256', $responseFingerprint) : null,
                'completed_at' => now(),
            ]);

            return $record->refresh();
        });
    }

    public function fail(string $scope, string $rawKey, ?string $reason = null): ?IdempotencyKey
    {
        $keyHash = $this->hashKey($rawKey);

        return DB::transaction(function () use ($scope, $keyHash, $reason) {
            $record = IdempotencyKey::query()
                ->where('key_hash', $keyHash)
                ->where('scope', $scope)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return null;
            }

            $metadata = (array) $record->metadata;
            if ($reason !== null) {
                $metadata['failure_reason'] = substr($reason, 0, 200);
            }

            $record->update([
                'status' => IdempotencyKey::STATUS_FAILED,
                'metadata' => $this->safeMetadata($metadata),
                'failed_at' => now(),
            ]);

            return $record->refresh();
        });
    }

    public function check(string $scope, string $rawKey): ?IdempotencyKey
    {
        $keyHash = $this->hashKey($rawKey);

        return IdempotencyKey::query()
            ->where('key_hash', $keyHash)
            ->where('scope', $scope)
            ->first();
    }

    /**
     * Sweep reserved rows whose TTL has elapsed to "expired" state. Read/write
     * only touches status/expired_at style fields — never mutates domain data.
     */
    public function expireOld(): int
    {
        return DB::transaction(function () {
            return IdempotencyKey::query()
                ->where('status', IdempotencyKey::STATUS_RESERVED)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now())
                ->update(['status' => IdempotencyKey::STATUS_EXPIRED]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function audit(): array
    {
        $byStatus = IdempotencyKey::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $byScope = IdempotencyKey::query()
            ->selectRaw('scope, count(*) as total')
            ->groupBy('scope')
            ->pluck('total', 'scope')
            ->all();

        $total = (int) IdempotencyKey::query()->count();

        $checks = [];
        $checks[] = $this->checkPass('IDEMPOTENCY-TABLE-READABLE', 'sys_idempotency_keys table is readable.');

        $config = (array) config('queue_governance.idempotency', []);
        $checks[] = ($config['raw_key_storage_allowed'] ?? true) === false
            ? $this->checkPass('IDEMPOTENCY-NO-RAW-KEY-POLICY', 'Governance confirms raw key storage is banned.')
            : $this->checkFail('IDEMPOTENCY-NO-RAW-KEY-POLICY', 'Governance must ban raw key storage.');

        $errors = count(array_filter($checks, fn (array $c) => $c['status'] === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => $c['status'] === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => $c['status'] === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'sprint' => 'QUEUE-1',
            'total_records' => $total,
            'by_status' => $byStatus,
            'by_scope' => $byScope,
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

    private function isExpired(IdempotencyKey $record): bool
    {
        return $record->expires_at !== null && $record->expires_at->isPast();
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function safeMetadata(array $metadata): array
    {
        $forbidden = ['ktp', 'nik', 'password', 'token', 'secret', 'email', 'phone', 'address'];

        return collect($metadata)
            ->reject(fn ($value, $key) => collect($forbidden)->contains(fn ($needle) => str_contains(strtolower((string) $key), $needle)))
            ->all();
    }

    private function checkPass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'blocking' => false, 'message' => $message];
    }

    private function checkFail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'blocking' => true, 'message' => $message];
    }
}
