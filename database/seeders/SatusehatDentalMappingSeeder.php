<?php

namespace Database\Seeders;

use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use Illuminate\Database\Seeder;

/**
 * SATUSEHAT-3 — seeds the official dental terminology mappings as DRAFT.
 *
 * IDEMPOTENT + NON-DESTRUCTIVE. Nothing is activated: every seeded mapping is
 * STATUS_DRAFT, carries the official source citation + version + a mapping
 * confidence, and must be VERIFIED + ACTIVATED by a human (the dental readiness
 * engine reports `dental_mapping_blocked` until then). No runtime code path
 * activates a mapping. Re-running never duplicates or overwrites an active
 * mapping — it only ensures the DRAFT seed exists for a fresh environment.
 *
 * Codes are read verbatim from the official SATUSEHAT "Rawat Jalan Gigi"
 * playbook v1.5 (7 Aug 2024) + terminology annex (audited 2026-07-16).
 */
class SatusehatDentalMappingSeeder extends Seeder
{
    public function run(): void
    {
        $env = (string) config('satusehat.environment', 'sandbox');
        $cfg = config('satusehat_dental');
        $profile = $cfg['official_profile'];
        $family = (string) $cfg['profile_family'];
        $snomed = $cfg['systems']['snomed'];

        // 1. FDI tooth number → SNOMED bodySite (Lampiran 1).
        foreach ((array) $cfg['fdi_bodysite_map'] as $fdi => $snomedCode) {
            $this->seedDraft($env, $family, [
                'local_entity_type' => 'odontogram_tooth_bodysite',
                'local_code' => (string) $fdi,
                'target_resource_type' => 'Observation',
                'target_path' => 'Observation.bodySite.coding',
                'terminology_system' => $snomed,
                'target_code' => (string) $snomedCode,
                'target_display' => 'FDI '.$fdi.' tooth structure',
                'mapping_confidence' => 'verified_official',
            ], $profile);
        }

        // 2. Local tooth status → official Keadaan Gigi / Restorasi (Lampiran 5/7).
        foreach ((array) $cfg['tooth_condition_map'] as $status => $def) {
            $this->seedDraft($env, $family, [
                'local_entity_type' => 'odontogram_tooth_condition',
                'local_code' => (string) $status,
                'target_resource_type' => 'Observation',
                'target_path' => 'Observation.component.valueCodeableConcept.coding',
                'terminology_system' => $cfg['systems'][$def['system']] ?? $snomed,
                'target_code' => (string) $def['code'],
                'target_display' => (string) $def['display'],
                'mapping_confidence' => (string) $def['confidence'],
            ], $profile);
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $profile
     */
    private function seedDraft(string $env, string $family, array $attrs, array $profile): void
    {
        // Logical identity key (single mapping per key, seeded as DRAFT). If ANY
        // mapping already exists for the key (draft/active/deprecated) skip —
        // never overwrite human decisions.
        $exists = SatusehatCodeMapping::query()
            ->where('environment', $env)
            ->where('local_entity_type', $attrs['local_entity_type'])
            ->where('local_code', $attrs['local_code'])
            ->where('target_resource_type', $attrs['target_resource_type'])
            ->exists();

        if ($exists) {
            return;
        }

        SatusehatCodeMapping::create(array_merge($attrs, [
            'environment' => $env,
            'local_entity_id' => null,
            'profile_family' => $family,
            'status' => SatusehatCodeMapping::STATUS_DRAFT,
            'version' => 1,
            'official_source' => $profile['annex_url'],
            'official_source_version' => $profile['source_version'].' ('.$profile['source_dated'].')',
            'effective_date' => $profile['source_dated'],
            'notes' => 'Seed resmi SATUSEHAT-3 (DRAFT). Wajib diverifikasi manusia sebelum diaktifkan.',
        ]));
    }
}
