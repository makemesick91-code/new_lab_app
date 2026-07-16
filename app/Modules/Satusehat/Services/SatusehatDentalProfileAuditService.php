<?php

namespace App\Modules\Satusehat\Services;

use App\Modules\Satusehat\Models\SatusehatCodeMapping;

/**
 * Read-only dental coverage / terminology audit (SATUSEHAT-3). Reads the local
 * registry (config coverage matrix + mst_satusehat_code_mappings) and reports
 * missing sources, inactive/unverified mappings, duplicate active mappings,
 * builders/resources without a mapping. NO network access, NO PII in output.
 *
 * Decision: GO (no blockers) | WATCH (draft/unverified pending) | NO_GO
 * (duplicate active / active-without-provenance — a real governance defect).
 */
class SatusehatDentalProfileAuditService
{
    public function audit(?string $environment = null): array
    {
        $env = $environment ?? (string) config('satusehat.environment');
        $cfg = (array) config('satusehat_dental');
        $family = (string) ($cfg['profile_family'] ?? 'dental');

        $coverage = $this->coverage($cfg);
        $mappings = $this->mappingAudit($env, $family, $cfg);

        $errors = array_merge($coverage['errors'], $mappings['errors']);
        $warnings = array_merge($coverage['warnings'], $mappings['warnings']);

        $decision = $errors !== [] ? 'NO_GO' : ($warnings !== [] ? 'WATCH' : 'GO');

        return [
            'environment' => $env,
            'profile' => $cfg['official_profile'] ?? [],
            'decision' => $decision,
            'coverage_summary' => $coverage['summary'],
            'mapping_summary' => $mappings['summary'],
            'errors' => array_values($errors),
            'warnings' => array_values($warnings),
        ];
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function coverage(array $cfg): array
    {
        $rows = (array) ($cfg['coverage'] ?? []);
        $summary = [];
        $errors = [];
        $warnings = [];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'blocked');
            $summary[$status] = ($summary[$status] ?? 0) + 1;

            // A row declared supported but with no local source is a defect.
            if (in_array($status, ['supported', 'supported_with_mapping'], true)
                && blank($row['local_source'] ?? null)) {
                $errors[] = "Variabel {$row['variable']} berstatus supported tanpa sumber lokal.";
            }
            if ($status === 'official_mapping_unverified') {
                $warnings[] = "Variabel {$row['variable']} menunggu verifikasi mapping resmi.";
            }
        }

        return ['summary' => $summary, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function mappingAudit(string $env, string $family, array $cfg): array
    {
        $mappings = SatusehatCodeMapping::query()
            ->where('environment', $env)
            ->where('profile_family', $family)
            ->get();

        $errors = [];
        $warnings = [];

        $active = $mappings->where('status', SatusehatCodeMapping::STATUS_ACTIVE);
        $draft = $mappings->where('status', SatusehatCodeMapping::STATUS_DRAFT);

        // Duplicate ACTIVE mapping per logical key = a real governance defect.
        $activeKeys = [];
        foreach ($active as $m) {
            $key = $m->activeKey();
            $activeKeys[$key] = ($activeKeys[$key] ?? 0) + 1;
        }
        foreach ($activeKeys as $key => $count) {
            if ($count > 1) {
                $errors[] = "Terdapat {$count} mapping aktif ambigu untuk kunci {$key}.";
            }
        }

        // ACTIVE mapping without official provenance = defect (should be blocked
        // by the service, but audit catches any pre-existing data).
        foreach ($active as $m) {
            if (! $m->hasOfficialProvenance()) {
                $errors[] = "Mapping aktif #{$m->id} tanpa sumber resmi/verifikasi.";
            }
        }

        // Expected condition/bodySite mappings not yet seeded.
        $expectedConditions = array_keys((array) ($cfg['tooth_condition_map'] ?? []));
        foreach ($expectedConditions as $status) {
            $has = $mappings->first(fn ($m) => $m->local_entity_type === 'odontogram_tooth_condition' && $m->local_code === (string) $status);
            if ($has === null) {
                $warnings[] = "Mapping keadaan gigi '{$status}' belum di-seed.";
            }
        }

        if ($draft->isNotEmpty()) {
            $warnings[] = "Terdapat {$draft->count()} mapping gigi DRAFT menunggu verifikasi + aktivasi manusia.";
        }
        if ($active->isEmpty()) {
            $warnings[] = 'Belum ada mapping gigi yang aktif — preview gigi akan dilaporkan mapping_blocked.';
        }

        return [
            'summary' => [
                'total' => $mappings->count(),
                'active' => $active->count(),
                'draft' => $draft->count(),
                'deprecated' => $mappings->where('status', SatusehatCodeMapping::STATUS_DEPRECATED)->count(),
                'verified' => $mappings->whereNotNull('verified_at')->count(),
            ],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }
}
