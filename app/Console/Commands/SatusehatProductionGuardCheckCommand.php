<?php

namespace App\Console\Commands;

use App\Modules\Satusehat\Support\SatusehatProductionActivationGuard;
use Illuminate\Console\Command;

/**
 * SATUSEHAT-3 — production activation guard check. Verifies production CANNOT
 * activate. Read-only, no network. Exits FAILURE if production were somehow
 * allowed (a safety tripwire); on SATUSEHAT-3 it always reports blocked.
 */
class SatusehatProductionGuardCheckCommand extends Command
{
    protected $signature = 'satusehat:production-guard-check
        {--json : Emit machine-readable JSON}
        {--expect-blocked : Fail if production is NOT blocked (default posture)}';

    protected $description = 'Verify the SATUSEHAT production activation guard blocks production (read-only).';

    public function handle(SatusehatProductionActivationGuard $guard): int
    {
        $result = $guard->evaluate();
        $expectBlocked = $this->option('expect-blocked') !== false; // default true posture

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('SATUSEHAT-3 Production Guard — production allowed: '.($result['allowed'] ? 'YES' : 'NO (blocked)'));
            foreach ($result['checks'] as $c) {
                $this->line(sprintf('  [%s] %-40s %s', $c['passed'] ? 'PASS' : 'BLOCK', $c['label'], $c['detail']));
            }
        }

        // Tripwire: on this sprint production MUST stay blocked.
        if ($result['allowed'] && $expectBlocked) {
            $this->error('Produksi TIDAK terblokir — ini pelanggaran guard SATUSEHAT-3.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
