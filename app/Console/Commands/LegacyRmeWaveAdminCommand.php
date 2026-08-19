<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Models\LegacyRmeWaveOperator;
use App\Modules\LegacyRme\Services\LegacyRmeWaveGovernanceService;
use App\Modules\LegacyRme\Support\LegacyRmeBatchWindowRule;
use App\Modules\LegacyRme\Support\LegacyRmeWaveBranchStatus;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-ROLL-4 — govern a migration wave from the terminal.
 *
 * WHY A CLI AT ALL, GIVEN THERE IS A UI. A wave has to be registered and its
 * first operator assigned BEFORE anyone can migrate anything, and on a
 * production VPS that first step happens over SSH beside the deploy — the same
 * place the admission config is edited. Requiring a browser session to open a
 * rollout would put the two halves of one decision in two different places.
 *
 * IT SHARES THE SERVICE, NOT THE RULES. Every action delegates to
 * LegacyRmeWaveGovernanceService, so transition legality, quota bounds, the
 * reconciliation gate, operator eligibility and the audit trail are identical to
 * the UI. A CLI that re-implemented any of them would be a second, weaker set of
 * rules — which is how "the UI enforces it" becomes untrue.
 *
 * MUTATIONS ARE OPT-IN. Every action that writes requires `--apply`; without it
 * the command reports what it WOULD do and changes nothing. An operator running
 * the wrong wave code at 2am gets a dry run, not a drained migration.
 *
 * IT ACTS AS A REAL USER. `--actor` names the account the action is attributed
 * to, and its permissions are checked exactly as they would be in the browser —
 * there is no CLI identity that bypasses authorization.
 */
class LegacyRmeWaveAdminCommand extends Command
{
    protected $signature = 'legacy-rme:wave-admin
        {action : register|approve|activate|pause|resume|drain|cancel|complete|assign|revoke|branch-quota|branch-pause|branch-resume|branch-drain|branch-complete}
        {--wave= : Wave code, e.g. WAVE-2}
        {--actor= : Id or email of the user the action is attributed to}
        {--name= : Wave name (register)}
        {--branches= : Comma-separated branch codes (register)}
        {--branch= : Branch code the action targets}
        {--operator= : Id or email of the operator to assign or revoke}
        {--daily-quota= : Wave or branch daily ceiling; omit for no ceiling}
        {--per-branch-daily-quota= : Default per-branch ceiling (register)}
        {--planned-start-date= : First planned day of the batch window, YYYY-MM-DD (register)}
        {--planned-end-date= : Final planned day of the batch window, inclusive, YYYY-MM-DD (register)}
        {--planned-documents= : Expected document count for a branch}
        {--reason= : Reason or sign-off note}
        {--apply : Perform the change; without it nothing is written}
        {--json : Emit the outcome as JSON}';

    protected $description = 'Register and govern a legacy RME migration wave (dry-run unless --apply)';

    public function handle(LegacyRmeWaveGovernanceService $governance): int
    {
        $action = (string) $this->argument('action');
        $apply = (bool) $this->option('apply');

        try {
            $actor = $this->resolveActor();
        } catch (ValidationException $exception) {
            return $this->reportValidationFailure($exception);
        }

        if (! $apply) {
            return $this->report([
                'action' => $action,
                'applied' => false,
                'note' => 'Dry run — nothing was written. Re-run with --apply to perform this action.',
            ] + $this->plan($action));
        }

        try {
            // The actor's PERMISSION is checked before anything is written, and
            // through the same policy the browser uses. Naming an account on the
            // command line must never be a way around authorization: a CLI that
            // skipped this would be a second, weaker entry point to exactly the
            // governance actions this layer exists to control.
            $this->authorizeActor($actor, $action);
        } catch (ValidationException $exception) {
            return $this->reportValidationFailure($exception);
        }

        try {
            $outcome = match ($action) {
                'register' => $this->register($governance, $actor),
                'approve' => $this->waveAction(fn (LegacyRmeMigrationWave $w) => $governance->approve($actor, $w)),
                'activate' => $this->waveAction(fn (LegacyRmeMigrationWave $w) => $governance->activate($actor, $w)),
                'pause' => $this->waveAction(fn (LegacyRmeMigrationWave $w) => $governance->pause($actor, $w, $this->reason())),
                'resume' => $this->waveAction(fn (LegacyRmeMigrationWave $w) => $governance->resume($actor, $w)),
                'drain' => $this->waveAction(fn (LegacyRmeMigrationWave $w) => $governance->drain($actor, $w, $this->reason())),
                'cancel' => $this->waveAction(fn (LegacyRmeMigrationWave $w) => $governance->cancelWave($actor, $w, $this->reason())),
                'complete' => $this->waveAction(fn (LegacyRmeMigrationWave $w) => $governance->completeWave($actor, $w, $this->reason())),
                'assign' => $this->assign($governance, $actor),
                'revoke' => $this->revoke($governance, $actor),
                'branch-quota' => $this->branchQuota($governance, $actor),
                'branch-pause' => $this->branchTransition($governance, $actor, LegacyRmeWaveBranchStatus::PAUSED),
                'branch-resume' => $this->branchTransition($governance, $actor, LegacyRmeWaveBranchStatus::ACTIVE),
                'branch-drain' => $this->branchTransition($governance, $actor, LegacyRmeWaveBranchStatus::DRAINING),
                'branch-complete' => $this->branchComplete($governance, $actor),
                default => throw ValidationException::withMessages([
                    'action' => sprintf('Tindakan %s tidak dikenali.', $action),
                ]),
            };
        } catch (ValidationException $exception) {
            return $this->reportValidationFailure($exception);
        }

        return $this->report(['action' => $action, 'applied' => true] + $outcome);
    }

    /**
     * @return array<string, mixed>
     */
    private function register(LegacyRmeWaveGovernanceService $governance, User $actor): array
    {
        $codes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('branches')),
        )));

        $wave = $governance->createWave(
            actor: $actor,
            code: (string) $this->option('wave'),
            name: (string) ($this->option('name') ?: $this->option('wave')),
            branchCodes: $codes,
            dailyQuota: $this->optionalInt('daily-quota'),
            perBranchDailyQuota: $this->optionalInt('per-branch-daily-quota'),
            // A routine batch is time-bounded, and SSH is a first-class way to
            // open one. Passing these through means the CLI can satisfy the
            // same invariant the browser does; the service validates them for
            // both callers, so omitting them here fails closed rather than
            // silently registering a batch whose approval never expires.
            plannedStartDate: $this->optionalString('planned-start-date'),
            plannedEndDate: $this->optionalString('planned-end-date'),
        );

        return [
            'wave' => $wave->code,
            'status' => $wave->status,
            'branches' => $wave->branches()->pluck('branch_code')->all(),
            'planned_start_date' => $wave->planned_start_date?->toDateString(),
            'planned_end_date' => $wave->planned_end_date?->toDateString(),
        ];
    }

    /**
     * @param  callable(LegacyRmeMigrationWave): LegacyRmeMigrationWave  $callback
     * @return array<string, mixed>
     */
    private function waveAction(callable $callback): array
    {
        $wave = $callback($this->requireWave());

        return ['wave' => $wave->code, 'status' => $wave->status];
    }

    /**
     * @return array<string, mixed>
     */
    private function assign(LegacyRmeWaveGovernanceService $governance, User $actor): array
    {
        $wave = $this->requireWave();
        $branch = $this->requireBranch($wave);
        $operator = $this->requireUser((string) $this->option('operator'), 'operator');

        $governance->assignOperator($actor, $wave, $operator, $branch);

        return [
            'wave' => $wave->code,
            'branch' => $branch->branch_code,
            'operator_user_id' => (int) $operator->getKey(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revoke(LegacyRmeWaveGovernanceService $governance, User $actor): array
    {
        $wave = $this->requireWave();
        $branch = $this->requireBranch($wave);
        $operator = $this->requireUser((string) $this->option('operator'), 'operator');

        $assignment = LegacyRmeWaveOperator::query()
            ->where('wave_id', $wave->getKey())
            ->where('user_id', $operator->getKey())
            ->where('branch_id', $branch->branch_id)
            ->first();

        if ($assignment === null) {
            throw ValidationException::withMessages([
                'operator' => 'Penugasan operator tersebut tidak ditemukan pada gelombang ini.',
            ]);
        }

        $governance->revokeOperator($actor, $assignment);

        return [
            'wave' => $wave->code,
            'branch' => $branch->branch_code,
            'operator_user_id' => (int) $operator->getKey(),
            'revoked' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchQuota(LegacyRmeWaveGovernanceService $governance, User $actor): array
    {
        $wave = $this->requireWave();
        $branch = $this->requireBranch($wave);

        $governance->setBranchQuota(
            $actor,
            $branch,
            $this->optionalInt('daily-quota'),
            $this->optionalInt('planned-documents'),
        );

        return [
            'wave' => $wave->code,
            'branch' => $branch->branch_code,
            'daily_quota' => $this->optionalInt('daily-quota'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchTransition(LegacyRmeWaveGovernanceService $governance, User $actor, string $to): array
    {
        $wave = $this->requireWave();
        $branch = $this->requireBranch($wave);

        $updated = $governance->transitionBranch(
            $actor,
            $branch,
            $to,
            $to === LegacyRmeWaveBranchStatus::ACTIVE ? null : $this->reason(),
        );

        return ['wave' => $wave->code, 'branch' => $updated->branch_code, 'status' => $updated->status];
    }

    /**
     * @return array<string, mixed>
     */
    private function branchComplete(LegacyRmeWaveGovernanceService $governance, User $actor): array
    {
        $wave = $this->requireWave();
        $branch = $this->requireBranch($wave);

        $updated = $governance->completeBranch($actor, $branch, $this->reason());

        return [
            'wave' => $wave->code,
            'branch' => $updated->branch_code,
            'status' => $updated->status,
            'reconciliation' => $updated->reconciliation_snapshot,
        ];
    }

    /**
     * What --apply would attempt. Reported without touching a row, so the dry
     * run is genuinely read-only.
     *
     * @return array<string, mixed>
     */
    private function plan(string $action): array
    {
        $wave = $this->findWave();

        return [
            'wave' => $this->option('wave'),
            'wave_found' => $wave !== null,
            'wave_status' => $wave?->status,
            'branch' => $this->option('branch'),
            'operator' => $this->option('operator'),
            'target_action' => $action,
            // Surfaced so an operator can SEE the window a --apply would
            // record, and see that it is missing, before the write happens.
            // Reported as typed, not normalised: the dry run must not imply
            // the value has already passed validation.
            'planned_start_date' => $this->optionalString('planned-start-date'),
            'planned_end_date' => $this->optionalString('planned-end-date'),
            'batch_window_required' => LegacyRmeBatchWindowRule::requiredByPolicy(),
        ];
    }

    private function findWave(): ?LegacyRmeMigrationWave
    {
        $code = (string) $this->option('wave');

        if (trim($code) === '') {
            return null;
        }

        return LegacyRmeMigrationWave::query()->where('code', strtoupper(trim($code)))->first();
    }

    private function requireWave(): LegacyRmeMigrationWave
    {
        $wave = $this->findWave();

        if ($wave === null) {
            throw ValidationException::withMessages([
                'wave' => 'Gelombang migrasi tidak ditemukan. Sertakan --wave= dengan kode yang benar.',
            ]);
        }

        return $wave;
    }

    private function requireBranch(LegacyRmeMigrationWave $wave): LegacyRmeWaveBranch
    {
        $code = strtoupper(trim((string) $this->option('branch')));

        $branch = $code === '' ? null : LegacyRmeWaveBranch::query()
            ->where('wave_id', $wave->getKey())
            ->where('branch_code', $code)
            ->first();

        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch' => 'Cabang tersebut tidak terdaftar pada gelombang migrasi ini.',
            ]);
        }

        return $branch;
    }

    /**
     * The account an action is attributed to.
     *
     * Required for every mutation: a governance record whose actor is "the
     * server" cannot be audited, and the permission checks inside the service
     * need a real subject.
     */
    private function resolveActor(): User
    {
        return $this->requireUser((string) $this->option('actor'), 'actor');
    }

    private function requireUser(string $identifier, string $field): User
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            throw ValidationException::withMessages([
                $field => sprintf('Sertakan --%s= dengan id atau email pengguna.', $field),
            ]);
        }

        $user = ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('email', $identifier)->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                $field => sprintf('Pengguna %s tidak ditemukan.', $identifier),
            ]);
        }

        return $user;
    }

    /**
     * Check the acting account against the SAME policy the HTTP surface uses.
     *
     * `approve` deliberately requires its own ability, so the split between
     * managing a rollout and signing it off survives on the command line too —
     * otherwise separation of duties would hold in the browser and evaporate
     * over SSH.
     *
     * @throws ValidationException
     */
    private function authorizeActor(User $actor, string $action): void
    {
        $ability = $action === 'approve' ? 'approve' : 'update';

        // `update`/`approve` are per-wave abilities. For `register` there is no
        // wave yet, so the class-level `create` ability is the right question.
        $allowed = $action === 'register'
            ? $actor->can('create', LegacyRmeMigrationWave::class)
            : $actor->can($ability, $this->requireWave());

        if (! $allowed) {
            throw ValidationException::withMessages([
                'actor' => sprintf(
                    'Pengguna %s tidak memiliki izin untuk menjalankan tindakan %s pada gelombang migrasi.',
                    (string) $actor->getKey(),
                    $action,
                ),
            ]);
        }
    }

    private function reason(): string
    {
        return (string) $this->option('reason');
    }

    private function optionalInt(string $option): ?int
    {
        $value = $this->option($option);

        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * An omitted option and an empty one mean the same thing: not supplied.
     * The value is passed through untouched — parsing and rejecting it is the
     * service's job, so the CLI cannot accidentally accept a shape the HTTP
     * caller would refuse.
     */
    private function optionalString(string $option): ?string
    {
        $value = $this->option($option);

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function report(array $payload): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        foreach ($payload as $key => $value) {
            $this->components->twoColumnDetail(
                (string) $key,
                is_scalar($value) || $value === null
                    ? var_export($value, true)
                    : (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            );
        }

        return self::SUCCESS;
    }

    private function reportValidationFailure(ValidationException $exception): int
    {
        foreach ($exception->errors() as $field => $messages) {
            foreach ((array) $messages as $message) {
                $this->components->error(sprintf('%s: %s', $field, $message));
            }
        }

        return self::FAILURE;
    }
}
