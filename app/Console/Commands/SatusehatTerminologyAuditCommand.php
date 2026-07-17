<?php

namespace App\Console\Commands;

use App\Modules\MedicalRecord\Models\ClinicalDiagnosis;
use App\Modules\MedicalRecord\Models\MedicalRecordDiagnosis;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-4B — clinical terminology governance audit. Read-only, PII-free,
 * no external HTTP. `--strict` exits non-zero only on a real governance
 * anomaly (active without official source, invalid code format, ambiguous
 * duplicate active display).
 */
class SatusehatTerminologyAuditCommand extends Command
{
    protected $signature = 'satusehat:terminology-audit {--json : Keluaran JSON} {--strict : Exit 2 bila ada anomali}';

    protected $description = 'Audit lifecycle & integritas terminologi diagnosis klinis (read-only, tanpa network)';

    public function handle(): int
    {
        $byStatus = ClinicalDiagnosis::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        // Anomaly 1 — ACTIVE terminology without an official source.
        $activeWithoutSource = ClinicalDiagnosis::query()
            ->where('status', ClinicalDiagnosis::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', ''))
            ->get(['id', 'code_system', 'code'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'code' => "{$d->code_system} {$d->code}"])
            ->values();

        // Anomaly 2 — ACTIVE code failing the official format for its system.
        $patterns = (array) config('clinical_diagnosis_rollout.code_patterns', []);
        $invalidCodes = ClinicalDiagnosis::query()
            ->where('status', ClinicalDiagnosis::STATUS_ACTIVE)
            ->whereIn('code_system', array_keys($patterns))
            ->get(['id', 'code_system', 'code'])
            ->filter(function ($d) use ($patterns) {
                $pattern = $patterns[(string) $d->code_system] ?? null;

                return is_string($pattern) && $pattern !== '' && preg_match($pattern, (string) $d->code) !== 1;
            })
            ->map(fn ($d) => ['id' => (int) $d->id, 'code' => "{$d->code_system} {$d->code}"])
            ->values();

        // Anomaly 3 — two ACTIVE entries with the same normalized display
        // (ambiguous duplicates; the unique code constraint already prevents
        // same-code duplicates).
        $ambiguous = ClinicalDiagnosis::query()
            ->where('status', ClinicalDiagnosis::STATUS_ACTIVE)
            ->selectRaw('lower(display) as norm_display, count(*) as total')
            ->groupBy('norm_display')
            ->havingRaw('count(*) > 1')
            ->pluck('total', 'norm_display')
            ->all();

        // Warning — deprecated terminology still referenced by records without
        // a designated replacement (re-coding has no suggested target).
        $deprecatedInUseWithoutReplacement = ClinicalDiagnosis::query()
            ->where('status', ClinicalDiagnosis::STATUS_DEPRECATED)
            ->whereNull('replacement_diagnosis_id')
            ->whereIn('id', MedicalRecordDiagnosis::query()->select('clinical_diagnosis_id'))
            ->count();

        $anomalies = $activeWithoutSource->count() + $invalidCodes->count() + count($ambiguous);

        $report = [
            'counts_by_status' => $byStatus,
            'active_without_official_source' => $activeWithoutSource->all(),
            'active_invalid_code_format' => $invalidCodes->all(),
            'ambiguous_active_displays' => $ambiguous,
            'deprecated_in_use_without_replacement' => $deprecatedInUseWithoutReplacement,
            'anomaly_count' => $anomalies,
            'decision' => $anomalies > 0 ? 'ANOMALY' : 'OK',
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('SATUSEHAT-4B — Audit Terminologi Klinis');
            foreach ($byStatus as $status => $total) {
                $this->line(sprintf('  %-22s: %d', $status, $total));
            }
            $this->line('Aktif tanpa sumber resmi   : '.$activeWithoutSource->count());
            $this->line('Aktif format kode tidak sah: '.$invalidCodes->count());
            $this->line('Display aktif ambigu       : '.count($ambiguous));
            $this->line('Deprecated terpakai tanpa pengganti (warning): '.$deprecatedInUseWithoutReplacement);
            $this->line('Keputusan: '.$report['decision']);
        }

        if ($this->option('strict') && $anomalies > 0) {
            return 2;
        }

        return self::SUCCESS;
    }
}
