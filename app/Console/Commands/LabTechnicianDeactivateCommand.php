<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Technician\Services\TechnicianAccountAuditor;
use Illuminate\Console\Command;

/**
 * LAB-OPS-READINESS-1 — governed technician master deactivation.
 *
 * Sets a master `mst_technicians` row inactive (is_active = false). Dry-run by
 * default; --apply required to persist. Requires a non-empty --reason (recorded
 * in the audit log). Refuses while the master holds an active assignment. Never
 * hard/soft-deletes, never detaches user_id — assignment history stays readable.
 * Transactional + row-locked + fail-closed + idempotent. Single master per run
 * (no bulk wildcard mutation).
 */
final class LabTechnicianDeactivateCommand extends Command
{
    protected $signature = 'lab:technician-deactivate
        {--technician= : Technician id or code}
        {--reason= : Why the master is being deactivated (required, recorded in the audit log)}
        {--dry-run : Preview only (default)}
        {--apply : Persist the deactivation}
        {--json}';

    protected $description = 'Deactivate a master technician (dry-run by default; preserves history, no delete).';

    public function handle(TechnicianAccountAuditor $auditor): int
    {
        $technicianRef = $this->option('technician');
        $reason = (string) ($this->option('reason') ?? '');

        if ($technicianRef === null || $technicianRef === '') {
            $this->error('--technician=<id|code> is required.');

            return self::INVALID;
        }
        if (trim($reason) === '') {
            $this->error('--reason="<why>" is required to deactivate a technician master.');

            return self::INVALID;
        }

        // Apply only when explicitly requested; --dry-run (or its absence) never mutates.
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('dry-run')) {
            $this->error('Pass either --dry-run or --apply, not both.');

            return self::INVALID;
        }

        try {
            $result = $auditor->deactivateMaster(
                is_numeric($technicianRef) ? (int) $technicianRef : (string) $technicianRef,
                $reason,
                $apply,
            );
        } catch (\RuntimeException $e) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($result['idempotent_no_op']) {
            $this->info('Already inactive — no change (idempotent).');
        } elseif ($result['applied']) {
            $this->info('APPLIED — technician master deactivated (history preserved).');
        } else {
            $this->comment('DRY-RUN — no change written. Re-run with --apply to persist.');
        }

        $this->table(
            ['field', 'before', 'after'],
            [
                ['technician_id', $result['before']['technician_id'], $result['after']['technician_id']],
                ['code', $result['before']['technician_code'], $result['before']['technician_code']],
                ['is_active', $result['before']['is_active'] ? 'yes' : 'no', $result['after']['is_active'] ? 'yes' : 'no'],
                ['user_id (preserved)', $result['before']['user_id'] ?? '—', $result['after']['user_id'] ?? '—'],
                ['active_assignments', $result['active_assignments'], $result['active_assignments']],
                ['reason', $result['reason'], $result['reason']],
            ],
        );

        return self::SUCCESS;
    }
}
