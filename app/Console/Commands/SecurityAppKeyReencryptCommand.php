<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\LabOrder\Services\AuditLogService;
use App\Support\Security\AppKeyReencryptionService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * SECURITY-G7B-APP-KEY-REENCRYPTION-1 — report, and on --apply rewrite, encrypted
 * columns still encrypted under a previous application key.
 *
 * Dry run by default. Prints counts only: never a key, never a plaintext, never a
 * ciphertext. Exit 0 when nothing is undecryptable (and, with --apply, every
 * rewrite verified); exit 1 otherwise. "previous_key_values = 0" is the condition
 * for removing APP_PREVIOUS_KEYS.
 */
class SecurityAppKeyReencryptCommand extends Command
{
    protected $signature = 'security:app-key-reencrypt
        {--apply : Rewrite values still encrypted under a previous key}
        {--json : Machine-readable output}';

    protected $description = 'Report (or with --apply, rewrite) encrypted columns still under a previous application key.';

    public function handle(AppKeyReencryptionService $service, AuditLogService $audit): int
    {
        $apply = (bool) $this->option('apply');
        $rewritten = null;

        if ($apply) {
            try {
                $rewritten = $service->reencrypt();
            } catch (RuntimeException $e) {
                return $this->finish(['decision' => 'NO-GO', 'mode' => 'apply', 'error' => $e->getMessage()] + $this->snapshot($service), 1);
            }

            $audit->log('app_key_rotation', null, 'APP_KEY_VALUES_REENCRYPTED', null, [
                'rewritten' => $rewritten['rewritten'],
                'columns' => $rewritten['columns'],
            ]);
        }

        $snapshot = $this->snapshot($service);
        $ok = $snapshot['totals']['undecryptable'] === 0 && (! $apply || $snapshot['totals']['previous'] === 0);

        return $this->finish([
            'decision' => $ok ? 'GO' : 'NO-GO',
            'mode' => $apply ? 'apply' : 'dry-run',
            'rewritten' => $rewritten['rewritten'] ?? null,
        ] + $snapshot, $ok ? 0 : 1);
    }

    /** @return array<string, mixed> */
    private function snapshot(AppKeyReencryptionService $service): array
    {
        $inspection = $service->inspect();

        return [
            'previous_keys_configured' => $service->previousKeyCount(),
            'previous_key_values' => $inspection['totals']['previous'],
            'previous_keys_removable' => $inspection['totals']['previous'] === 0 && $inspection['totals']['undecryptable'] === 0,
            'totals' => $inspection['totals'],
            'columns' => $inspection['columns'],
        ];
    }

    /** @param array<string, mixed> $report */
    private function finish(array $report, int $exit): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exit;
        }

        $this->line('Mode: '.$report['mode']);
        $this->line('Previous keys configured: '.$report['previous_keys_configured']);
        foreach ($report['columns'] as $c) {
            $this->line(sprintf('  %s.%s  total=%d current=%d previous=%d undecryptable=%d', $c['table'], $c['column'], $c['total'], $c['current'], $c['previous'], $c['undecryptable']));
        }
        if (($report['rewritten'] ?? null) !== null) {
            $this->line('Rewritten: '.$report['rewritten']);
        }
        if (isset($report['error'])) {
            $this->error($report['error']);
        }
        $this->line('Previous keys removable: '.($report['previous_keys_removable'] ? 'yes' : 'no'));
        $this->line('Decision: '.$report['decision']);

        return $exit;
    }
}
