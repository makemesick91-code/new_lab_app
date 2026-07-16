<?php

namespace App\Console\Commands;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Satusehat\Services\Dental\SatusehatDentalResourceBuilder;
use App\Modules\Satusehat\Support\SatusehatDentalConformanceValidator;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-3 — build + locally validate the dental FHIR preview for one visit.
 * Bounded (explicit visit id), read-only, NO network. The preview is LOCAL ONLY
 * and never sent; output is PII-free (structure + validation, no name/NIK/note).
 */
class SatusehatDentalPreviewCommand extends Command
{
    protected $signature = 'satusehat:dental-preview
        {visit : Clinic visit id}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Build + locally validate the dental FHIR preview for a visit (local only, no network).';

    public function handle(
        SatusehatDentalResourceBuilder $builder,
        SatusehatDentalConformanceValidator $validator,
    ): int {
        $visit = ClinicVisit::find((int) $this->argument('visit'));
        if ($visit === null) {
            $this->error('Kunjungan tidak ditemukan.');

            return self::FAILURE;
        }

        $preview = $builder->build($visit);

        $rows = [];
        foreach ($preview['resources'] as $r) {
            $verdict = ($r['supported'] ?? false) && ($r['payload'] ?? null) !== null
                ? $validator->validate($r['payload'])['result']
                : 'skipped';
            $rows[] = [
                'order' => $r['order'],
                'variable' => $r['variable'],
                'supported' => $r['supported'] ?? false,
                'confidence' => $r['mapping_confidence'] ?? null,
                'conformance' => $verdict,
                'payload_hash' => $r['payload_hash'] ?? null,
            ];
        }

        $out = [
            'visit_id' => $visit->id,
            'environment' => $preview['environment'],
            'note' => $preview['note'],
            'resources' => $rows,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Dental preview (LOKAL — belum dikirim) untuk visit '.$visit->id);
            foreach ($rows as $row) {
                $this->line(sprintf('  #%d %-24s supported=%s conf=%s conformance=%s',
                    $row['order'], $row['variable'], $row['supported'] ? 'y' : 'n',
                    $row['confidence'] ?? '-', $row['conformance']));
            }
            $this->warn('Validasi lokal tidak menjamin acceptance oleh API SATUSEHAT.');
        }

        return self::SUCCESS;
    }
}
