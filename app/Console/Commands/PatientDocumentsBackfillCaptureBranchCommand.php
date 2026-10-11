<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Patient\Services\PatientDocumentCaptureBranchBackfillService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — record the capture branch of
 * pre-sprint patient documents, but only where independent evidence agrees.
 *
 * DRY RUN BY DEFAULT: without --apply nothing is written. --apply requires a
 * named, active Super Admin --actor (the shell user is not an application
 * identity), recorded as the actor of every audit row. A document the
 * evidence cannot place keeps an unknown capture branch, which the access
 * policy treats as fail closed (only an explicitly privileged account can read
 * it). Output is aggregate counts and reason codes — no patient identifiers.
 */
class PatientDocumentsBackfillCaptureBranchCommand extends Command
{
    protected $signature = 'patient-documents:backfill-capture-branch
                            {--apply : Write the capture branch for documents whose evidence agrees (default: dry run)}
                            {--actor= : Acting Super Admin user id or email (required with --apply)}
                            {--json : Output the report as JSON}';

    protected $description = 'Record the capture branch of pre-sprint patient documents where evidence agrees (dry run by default)';

    public function handle(PatientDocumentCaptureBranchBackfillService $backfill): int
    {
        $apply = (bool) $this->option('apply');
        $actor = $this->resolveActor((string) ($this->option('actor') ?? ''));

        try {
            $report = $backfill->run($apply, $actor);
        } catch (InvalidArgumentException $e) {
            $this->option('json')
                ? $this->line((string) json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_PRETTY_PRINT))
                : $this->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $this->info('Mode: '.$report['mode']);
        $this->line('Documents without a capture branch: '.$report['scanned']);
        $this->line('Resolvable from agreeing evidence:  '.$report['resolvable']);
        $this->line('Unresolved (stay fail-closed):      '.$report['unresolved']);

        foreach ($report['unresolved_by_reason'] as $reason => $count) {
            $this->line("  - {$reason}: {$count}");
        }

        $this->line('Applied: '.$report['applied']);

        if ($report['mode'] === 'dry-run' && $report['resolvable'] > 0) {
            $this->comment('Dry run — nothing written. Re-run with --apply to record the resolvable documents.');
        }

        return Command::SUCCESS;
    }

    private function resolveActor(string $ref): ?User
    {
        if ($ref === '') {
            return null;
        }

        return is_numeric($ref)
            ? User::query()->find((int) $ref)
            : User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($ref)])->first();
    }
}
