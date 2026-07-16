<?php

namespace App\Modules\Satusehat\Services;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Support\DeveloperConsole\SensitiveValueMasker;
use Illuminate\Support\Facades\Auth;

/**
 * Writes append-only SATUSEHAT audit entries. Context is restricted to a
 * PII-free whitelist of scalars (ids, codes, hashes, reason codes) and every
 * free-text field is masked. Never records NIK/token/raw payloads.
 */
class SatusehatAuditLogger
{
    public function __construct(
        private readonly SensitiveValueMasker $masker,
    ) {}

    /**
     * @param  array<string, scalar|array|null>  $context
     */
    public function log(
        string $entityType,
        ?int $entityId,
        string $event,
        ?string $summary = null,
        array $context = [],
        ?int $branchId = null,
        ?User $actor = null,
    ): SatusehatAuditLog {
        $actor = $actor ?? Auth::user();

        return SatusehatAuditLog::create([
            'environment' => config('satusehat.environment'),
            'branch_id' => $branchId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event' => $event,
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->getRoleNames()->first(),
            'summary' => $summary !== null ? $this->masker->mask($summary) : null,
            'context' => $this->sanitizeContext($context),
            'ip_address' => optional(request())->ip(),
            'user_agent' => optional(request())->userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Keep only scalar/array values and mask any string. Drops everything that
     * could carry PII by accident (objects, resources).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, scalar|array|null>
     */
    private function sanitizeContext(array $context): array
    {
        $clean = [];

        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $clean[$key] = $this->masker->mask($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } elseif (is_array($value)) {
                $clean[$key] = $this->sanitizeContext($value);
            }
            // objects/resources are intentionally dropped.
        }

        return $clean;
    }
}
