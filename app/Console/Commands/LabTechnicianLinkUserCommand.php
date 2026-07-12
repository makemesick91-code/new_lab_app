<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Technician\Services\TechnicianAccountAuditor;
use Illuminate\Console\Command;

/**
 * LAB-WORKFLOW-V2-PILOT-UAT-1 — governed technician↔user mapping.
 *
 * Links a master `mst_technicians` row to an existing user that ALREADY holds
 * the Technician role. Dry-run by default; --apply required to persist. Never
 * changes the user's role, never links ambiguous rows, never deletes history.
 * Transactional + row-locked + fail-closed + idempotent.
 */
final class LabTechnicianLinkUserCommand extends Command
{
    protected $signature = 'lab:technician-link-user
        {--technician= : Technician id or code}
        {--user= : User id or email}
        {--dry-run : Preview only (default)}
        {--apply : Persist the link}
        {--json}';

    protected $description = 'Link a master technician to an existing Technician-role user (dry-run by default).';

    public function handle(TechnicianAccountAuditor $auditor): int
    {
        $technicianRef = $this->option('technician');
        $userRef = $this->option('user');

        if ($technicianRef === null || $technicianRef === '' || $userRef === null || $userRef === '') {
            $this->error('Both --technician=<id|code> and --user=<id|email> are required.');

            return self::INVALID;
        }

        // Apply only when explicitly requested; --dry-run (or its absence) never mutates.
        $apply = (bool) $this->option('apply');
        if ($apply && $this->option('dry-run')) {
            $this->error('Pass either --dry-run or --apply, not both.');

            return self::INVALID;
        }

        try {
            $result = $auditor->linkUser(
                is_numeric($technicianRef) ? (int) $technicianRef : (string) $technicianRef,
                is_numeric($userRef) ? (int) $userRef : (string) $userRef,
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
            $this->info('Already linked — no change (idempotent).');
        } elseif ($result['applied']) {
            $this->info('APPLIED — technician linked to user.');
        } else {
            $this->comment('DRY-RUN — no change written. Re-run with --apply to persist.');
        }

        $this->table(
            ['field', 'before', 'after'],
            [
                ['technician_id', $result['before']['technician_id'], $result['after']['technician_id']],
                ['user_id', $result['before']['user_id'] ?? '—', $result['after']['user_id'] ?? '—'],
                ['eligible', $result['before']['eligible'] ? 'yes' : 'no', $result['after']['eligible'] ? 'yes' : 'no'],
            ],
        );

        return self::SUCCESS;
    }
}
