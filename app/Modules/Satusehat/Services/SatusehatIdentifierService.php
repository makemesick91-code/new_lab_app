<?php

namespace App\Modules\Satusehat\Services;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Entity identifier governance. Identifiers are entered/verified administratively
 * — NO external lookup is ever performed. Sandbox and production are never mixed,
 * and exactly one ACTIVE identifier per (environment, entity_type, local entity)
 * is enforced here.
 */
class SatusehatIdentifierService
{
    public function __construct(
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * Create or replace the active identifier for a local entity. Replacing an
     * existing active identifier inactivates the old one (single-active).
     *
     * @param  array<string, mixed>  $data
     */
    public function upsert(array $data, User $actor): SatusehatEntityIdentifier
    {
        $this->assertEnvironment($data['environment'] ?? null);
        $this->assertEntityType($data['entity_type'] ?? null);
        $this->assertIdentifierFormat($data['remote_identifier'] ?? null);

        return DB::transaction(function () use ($data, $actor) {
            $existing = SatusehatEntityIdentifier::query()
                ->where('environment', $data['environment'])
                ->where('entity_type', $data['entity_type'])
                ->where('local_entity_type', $data['local_entity_type'])
                ->where('local_entity_id', $data['local_entity_id'])
                ->where('status', SatusehatEntityIdentifier::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get();

            foreach ($existing as $old) {
                $old->update(['status' => SatusehatEntityIdentifier::STATUS_INACTIVE, 'effective_to' => now()]);
            }

            $identifier = SatusehatEntityIdentifier::create([
                'environment' => $data['environment'],
                'entity_type' => $data['entity_type'],
                'local_entity_type' => $data['local_entity_type'],
                'local_entity_id' => $data['local_entity_id'],
                'remote_identifier' => trim((string) $data['remote_identifier']),
                'identifier_system' => $data['identifier_system'] ?? null,
                'status' => SatusehatEntityIdentifier::STATUS_ACTIVE,
                'effective_from' => $data['effective_from'] ?? now(),
                'verified_at' => now(),
                'verified_by' => $actor->id,
                'created_by' => $actor->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->audit->log('entity_identifier', $identifier->id,
                $existing->isEmpty() ? SatusehatAuditLog::EVENT_IDENTIFIER_CREATED : SatusehatAuditLog::EVENT_IDENTIFIER_UPDATED,
                'Identifier disimpan', ['entity_type' => $identifier->entity_type], null, $actor);

            return $identifier;
        });
    }

    public function deactivate(SatusehatEntityIdentifier $identifier, User $actor): SatusehatEntityIdentifier
    {
        $identifier->update(['status' => SatusehatEntityIdentifier::STATUS_INACTIVE, 'effective_to' => now()]);

        $this->audit->log('entity_identifier', $identifier->id, SatusehatAuditLog::EVENT_IDENTIFIER_UPDATED,
            'Identifier dinonaktifkan', [], null, $actor);

        return $identifier->refresh();
    }

    private function assertEnvironment(?string $environment): void
    {
        $allowed = (array) config('satusehat.allowed_environments', ['sandbox', 'production']);
        if (! in_array($environment, $allowed, true)) {
            throw ValidationException::withMessages(['environment' => 'Lingkungan SATUSEHAT tidak valid.']);
        }
    }

    private function assertEntityType(?string $entityType): void
    {
        if (! in_array($entityType, SatusehatEntityIdentifier::ENTITY_TYPES, true)) {
            throw ValidationException::withMessages(['entity_type' => 'Tipe entitas tidak valid.']);
        }
    }

    private function assertIdentifierFormat(?string $identifier): void
    {
        $identifier = trim((string) $identifier);
        // Format-only validation (no external lookup). IHS/SATUSEHAT ids are
        // non-empty and reasonably bounded; reject obviously invalid input.
        if ($identifier === '' || mb_strlen($identifier) > 191 || ! preg_match('/^[A-Za-z0-9._:\-]+$/', $identifier)) {
            throw ValidationException::withMessages([
                'remote_identifier' => 'Format identifier tidak valid.',
            ]);
        }
    }
}
