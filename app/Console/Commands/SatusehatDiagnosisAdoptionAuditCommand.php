<?php

namespace App\Console\Commands;

use App\Modules\Satusehat\Services\SatusehatDiagnosisAdoptionService;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4B — reproducible structured diagnosis adoption baseline/audit.
 * Read-only, bounded, PII-free, no external HTTP. Safe on the VPS.
 */
class SatusehatDiagnosisAdoptionAuditCommand extends Command
{
    protected $signature = 'satusehat:diagnosis-adoption-audit
        {--branch= : Batasi ke satu branch id (harus cabang RME aktif)}
        {--from= : Tanggal awal (YYYY-MM-DD, default 30 hari terakhir)}
        {--to= : Tanggal akhir (YYYY-MM-DD, default hari ini)}
        {--doctor= : Batasi ke satu doctor id}
        {--json : Keluaran JSON}';

    protected $description = 'Audit adopsi diagnosis terstruktur per cabang/dokter (read-only, tanpa PII, tanpa network)';

    public function handle(SatusehatDiagnosisAdoptionService $adoption): int
    {
        $metrics = $adoption->metrics([
            'branch_id' => $this->option('branch') !== null ? (int) $this->option('branch') : null,
            'from' => $this->option('from'),
            'to' => $this->option('to'),
            'doctor_id' => $this->option('doctor') !== null ? (int) $this->option('doctor') : null,
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('SATUSEHAT-4B — Audit Adopsi Diagnosis Terstruktur');
        $this->line("Periode           : {$metrics['period']['from']} s/d {$metrics['period']['to']}");
        $this->line('Cabang dalam scope: '.(count($metrics['scope_branch_ids']) > 0 ? implode(', ', $metrics['scope_branch_ids']) : '-'));
        $this->line("Kunjungan eligible: {$metrics['eligible_visits']}");
        $this->line("Dengan diagnosis  : {$metrics['with_structured_diagnosis']}");
        $this->line("Dengan primary    : {$metrics['with_primary_diagnosis']}");
        $this->line('Adoption rate     : '.($metrics['adoption_rate'] !== null ? $metrics['adoption_rate'].'%' : 'N/A'));
        $this->line('Primary rate      : '.($metrics['primary_completeness_rate'] !== null ? $metrics['primary_completeness_rate'].'%' : 'N/A'));
        $this->line("Diagnosis sekunder: {$metrics['secondary_diagnosis_count']}");
        $this->line("Terminologi nonaktif terpakai: {$metrics['deprecated_diagnosis_usage']}");
        $this->line("Override darurat  : {$metrics['override_count']}");
        $this->line("Kandidat source_changed: {$metrics['source_changed_candidates']}");

        if ($metrics['per_branch'] !== []) {
            $this->table(
                ['Cabang', 'Eligible', 'Dengan Dx', 'Dengan Primary', 'Adopsi %'],
                collect($metrics['per_branch'])->map(fn ($r) => [
                    $r['branch_name'], $r['eligible'], $r['with_diagnosis'], $r['with_primary'],
                    $r['adoption_rate'] !== null ? $r['adoption_rate'].'%' : 'N/A',
                ])->all(),
            );
        }

        if ($metrics['per_doctor'] !== []) {
            $this->table(
                ['Dokter', 'Eligible', 'Dengan Dx', 'Dengan Primary', 'Adopsi %'],
                collect($metrics['per_doctor'])->map(fn ($r) => [
                    $r['doctor_name'], $r['eligible'], $r['with_diagnosis'], $r['with_primary'],
                    $r['adoption_rate'] !== null ? $r['adoption_rate'].'%' : 'N/A',
                ])->all(),
            );
        }

        return self::SUCCESS;
    }
}
