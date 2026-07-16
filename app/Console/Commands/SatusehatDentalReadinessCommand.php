<?php

namespace App\Console\Commands;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Satusehat\Services\Dental\SatusehatDentalReadinessService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-3 — evaluate dental readiness for one visit. Bounded (requires an
 * explicit visit id), read-only, no network, PII-free output (reason codes +
 * coverage only; never a name/NIK/raw note).
 */
class SatusehatDentalReadinessCommand extends Command
{
    protected $signature = 'satusehat:dental-readiness
        {visit : Clinic visit id}
        {--json : Emit machine-readable JSON}
        {--strict : Exit non-zero unless dental_ready}';

    protected $description = 'Evaluate SATUSEHAT dental readiness for a visit (read-only, no network, PII-free).';

    public function handle(SatusehatDentalReadinessService $service): int
    {
        $visit = ClinicVisit::find((int) $this->argument('visit'));
        if ($visit === null) {
            $this->error('Kunjungan tidak ditemukan.');

            return self::FAILURE;
        }

        $result = $service->evaluate($visit);
        $out = [
            'visit_id' => $visit->id,
            'dental_status' => $result->status,
            'reason_codes' => array_column($result->reasons, 'code'),
            'coverage' => $result->coverage,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Dental readiness: '.$result->status);
            foreach ($result->reasons as $r) {
                $this->line('  ['.$r['severity'].'] '.$r['code'].' — '.$r['message']);
            }
        }

        if ($this->option('strict') && $result->status !== 'dental_ready') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
