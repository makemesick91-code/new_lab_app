<?php

namespace App\Modules\Satusehat\Services;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mapping lifecycle governance. Mappings are versioned and never edited in place
 * once active — a change is a new draft version. Exactly one ACTIVE mapping per
 * logical key is enforced here (defense in depth alongside the PG partial unique).
 */
class SatusehatMappingService
{
    public function __construct(
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(array $data, User $actor): SatusehatCodeMapping
    {
        $this->assertEnvironment($data['environment'] ?? null);

        return DB::transaction(function () use ($data, $actor) {
            $mapping = SatusehatCodeMapping::create([
                'environment' => $data['environment'],
                'local_entity_type' => $data['local_entity_type'],
                'local_entity_id' => $data['local_entity_id'] ?? null,
                'local_code' => $data['local_code'] ?? null,
                'target_resource_type' => $data['target_resource_type'],
                'target_path' => $data['target_path'] ?? null,
                'terminology_system' => $data['terminology_system'] ?? null,
                'target_code' => $data['target_code'] ?? null,
                'target_display' => $data['target_display'] ?? null,
                'effective_date' => $data['effective_date'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'status' => SatusehatCodeMapping::STATUS_DRAFT,
                'version' => $this->nextVersion($data),
                'notes' => $data['notes'] ?? null,
                // SATUSEHAT-3 terminology governance provenance.
                'profile_family' => $data['profile_family'] ?? null,
                'official_source' => $data['official_source'] ?? null,
                'official_source_version' => $data['official_source_version'] ?? null,
                'mapping_confidence' => $data['mapping_confidence'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->audit->log('code_mapping', $mapping->id, SatusehatAuditLog::EVENT_MAPPING_CREATED,
                'Draft mapping dibuat', ['version' => $mapping->version], null, $actor);

            return $mapping;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(SatusehatCodeMapping $mapping, array $data, User $actor): SatusehatCodeMapping
    {
        if (! $mapping->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Hanya mapping berstatus draf yang dapat diubah. Buat versi baru untuk mengubah mapping aktif.',
            ]);
        }

        $mapping->update(array_filter([
            'target_path' => $data['target_path'] ?? null,
            'terminology_system' => $data['terminology_system'] ?? null,
            'target_code' => $data['target_code'] ?? null,
            'target_display' => $data['target_display'] ?? null,
            'effective_date' => $data['effective_date'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'notes' => $data['notes'] ?? null,
            'profile_family' => $data['profile_family'] ?? null,
            'official_source' => $data['official_source'] ?? null,
            'official_source_version' => $data['official_source_version'] ?? null,
            'mapping_confidence' => $data['mapping_confidence'] ?? null,
        ], fn ($v) => $v !== null));

        return $mapping->refresh();
    }

    /**
     * Human verification stamp — required before a governed profile-family
     * mapping may be activated. Records the official source + verifier.
     *
     * @param  array<string, mixed>  $data
     */
    public function verify(SatusehatCodeMapping $mapping, array $data, User $actor): SatusehatCodeMapping
    {
        $source = trim((string) ($data['official_source'] ?? $mapping->official_source ?? ''));
        if ($source === '') {
            throw ValidationException::withMessages([
                'official_source' => 'Sumber resmi wajib diisi sebelum verifikasi.',
            ]);
        }

        $mapping->update([
            'official_source' => $source,
            'official_source_version' => $data['official_source_version'] ?? $mapping->official_source_version,
            'verified_at' => now(),
            'verified_by' => $actor->id,
        ]);

        $this->audit->log('code_mapping', $mapping->id, SatusehatAuditLog::EVENT_MAPPING_REVIEWED,
            'Mapping diverifikasi terhadap sumber resmi', ['version' => $mapping->version], null, $actor);

        return $mapping->refresh();
    }

    public function review(SatusehatCodeMapping $mapping, User $actor): SatusehatCodeMapping
    {
        $mapping->update(['reviewed_by' => $actor->id]);

        $this->audit->log('code_mapping', $mapping->id, SatusehatAuditLog::EVENT_MAPPING_REVIEWED,
            'Mapping direview', [], null, $actor);

        return $mapping->refresh();
    }

    public function activate(SatusehatCodeMapping $mapping, User $actor): SatusehatCodeMapping
    {
        // SATUSEHAT-3 terminology governance: a mapping belonging to a governed
        // profile family (e.g. "dental") may only be ACTIVATED once it carries
        // an official source citation AND a human verification stamp. This
        // prevents an unverified/guessed clinical code from ever going active.
        if ($mapping->isProfileFamilyGoverned() && ! $mapping->hasOfficialProvenance()) {
            throw ValidationException::withMessages([
                'status' => 'Mapping profil klinis wajib mencantumkan sumber resmi dan diverifikasi sebelum diaktifkan.',
            ]);
        }

        return DB::transaction(function () use ($mapping, $actor) {
            $locked = SatusehatCodeMapping::query()->lockForUpdate()->findOrFail($mapping->id);

            if ($locked->isProfileFamilyGoverned() && ! $locked->hasOfficialProvenance()) {
                throw ValidationException::withMessages([
                    'status' => 'Mapping profil klinis wajib mencantumkan sumber resmi dan diverifikasi sebelum diaktifkan.',
                ]);
            }

            // Single-active: deprecate any currently-active mapping for the key.
            SatusehatCodeMapping::query()
                ->where('environment', $locked->environment)
                ->where('local_entity_type', $locked->local_entity_type)
                ->where('target_resource_type', $locked->target_resource_type)
                ->when($locked->local_entity_id !== null,
                    fn ($q) => $q->where('local_entity_id', $locked->local_entity_id),
                    fn ($q) => $q->whereNull('local_entity_id'))
                ->when($locked->local_code !== null && $locked->local_code !== '',
                    fn ($q) => $q->where('local_code', $locked->local_code),
                    fn ($q) => $q->whereNull('local_code'))
                ->where('status', SatusehatCodeMapping::STATUS_ACTIVE)
                ->where('id', '!=', $locked->id)
                ->get()
                ->each(function (SatusehatCodeMapping $old) use ($actor) {
                    $old->update(['status' => SatusehatCodeMapping::STATUS_DEPRECATED]);
                    $this->audit->log('code_mapping', $old->id, SatusehatAuditLog::EVENT_MAPPING_DEPRECATED,
                        'Mapping versi lama diusangkan', [], null, $actor);
                });

            $locked->update([
                'status' => SatusehatCodeMapping::STATUS_ACTIVE,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            $this->audit->log('code_mapping', $locked->id, SatusehatAuditLog::EVENT_MAPPING_ACTIVATED,
                'Mapping diaktifkan', ['version' => $locked->version], null, $actor);

            return $locked->refresh();
        });
    }

    public function deprecate(SatusehatCodeMapping $mapping, User $actor): SatusehatCodeMapping
    {
        $mapping->update(['status' => SatusehatCodeMapping::STATUS_DEPRECATED]);

        $this->audit->log('code_mapping', $mapping->id, SatusehatAuditLog::EVENT_MAPPING_DEPRECATED,
            'Mapping diusangkan', [], null, $actor);

        return $mapping->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function nextVersion(array $data): int
    {
        $max = SatusehatCodeMapping::query()
            ->where('environment', $data['environment'])
            ->where('local_entity_type', $data['local_entity_type'])
            ->where('target_resource_type', $data['target_resource_type'])
            ->when(($data['local_entity_id'] ?? null) !== null,
                fn ($q) => $q->where('local_entity_id', $data['local_entity_id']),
                fn ($q) => $q->whereNull('local_entity_id'))
            ->when(($data['local_code'] ?? null) !== null && ($data['local_code'] ?? '') !== '',
                fn ($q) => $q->where('local_code', $data['local_code']),
                fn ($q) => $q->whereNull('local_code'))
            ->max('version');

        return (int) $max + 1;
    }

    private function assertEnvironment(?string $environment): void
    {
        $allowed = (array) config('satusehat.allowed_environments', ['sandbox', 'production']);
        if (! in_array($environment, $allowed, true)) {
            throw ValidationException::withMessages(['environment' => 'Lingkungan SATUSEHAT tidak valid.']);
        }
    }
}
