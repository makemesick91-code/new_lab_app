<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Patient\Services\PatientKtpCrossBranchAccessGrantService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * SECURITY-PATIENT-KTP-ARCHIVE-BRANCH-ISOLATION-1 — assign or revoke
 * cross-branch KTP archive reading for ONE Supervisor RME account.
 *
 * Dry run by default; --apply writes. The actor is named explicitly and must be
 * an active Super Admin — the shell user is not an application identity. Every
 * change is audited. Running this is an owner decision, never a deploy step.
 */
final class PatientKtpCrossBranchAccessCommand extends Command
{
    protected $signature = 'rbac:patient-ktp-cross-branch-access
        {action : grant | revoke | status}
        {--user= : Target user id or email (a Supervisor RME account)}
        {--actor= : Acting Super Admin user id or email}
        {--reason= : Why (10-1000 chars; only its length is audited)}
        {--apply : Persist the change (default: dry run)}
        {--json}';

    protected $description = 'Grant/revoke cross-branch KTP archive reading for one Supervisor RME account (dry run by default).';

    public function handle(PatientKtpCrossBranchAccessGrantService $grants): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, ['grant', 'revoke', 'status'], true)) {
            return $this->refuse('Action must be grant, revoke or status.');
        }

        $target = $this->resolveUser((string) ($this->option('user') ?? ''));

        if ($target === null) {
            return $this->refuse('Target user not found (--user=<id|email>).');
        }

        if ($action === 'status') {
            $this->report([
                'user_id' => (int) $target->id,
                'holds_cross_branch_ktp_access' => $grants->holds($target),
                'role_level_grant_ignored' => $grants->roleLevelGrantIgnored($target),
            ]);

            return self::SUCCESS;
        }

        $actor = $this->resolveUser((string) ($this->option('actor') ?? ''));

        if ($actor === null) {
            return $this->refuse('Actor not found (--actor=<id|email> of an active Super Admin).');
        }

        $reason = (string) ($this->option('reason') ?? '');
        $refusal = $action === 'grant' ? $grants->refusal($actor, $target) : null;

        if (! $this->option('apply')) {
            $this->report([
                'mode' => 'dry-run',
                'action' => $action,
                'user_id' => (int) $target->id,
                'currently_holds' => $grants->holds($target),
                'eligible' => $refusal === null,
                'refusal' => $refusal,
                'note' => 'Nothing was written. Re-run with --apply to persist.',
            ]);

            return $refusal === null ? self::SUCCESS : self::FAILURE;
        }

        try {
            $changed = $action === 'grant'
                ? $grants->grant($actor, $target, $reason)
                : $grants->revoke($actor, $target, $reason);
        } catch (ValidationException $e) {
            return $this->refuse(implode(' ', $e->validator->errors()->all()));
        }

        $this->report([
            'mode' => 'applied',
            'action' => $action,
            'user_id' => (int) $target->id,
            'changed' => $changed,
            'holds_cross_branch_ktp_access' => $grants->holds($target->fresh()),
            'audited' => $changed,
        ]);

        return self::SUCCESS;
    }

    private function resolveUser(string $ref): ?User
    {
        if ($ref === '') {
            return null;
        }

        return is_numeric($ref)
            ? User::query()->find((int) $ref)
            : User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower($ref)])->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function report(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT));

            return;
        }

        foreach ($payload as $key => $value) {
            $this->line(sprintf('%-32s %s', $key, is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '—')));
        }
    }

    private function refuse(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => false, 'message' => $message], JSON_PRETTY_PRINT));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
