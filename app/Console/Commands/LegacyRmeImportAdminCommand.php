<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\LegacyRme\Services\LegacyRmeImportLifecycleService;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeCommandRefusal;
use App\Modules\LegacyRme\Support\LegacyRmeImportNotInScope;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleAction;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleDenied;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleOutcome;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleRefusal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-OPS-CLI-1 — drive one staged legacy RME import through its
 * lifecycle from the terminal.
 *
 * WHY THIS EXISTS. Wave-2 was opened and then aborted, and the operator
 * discovered they could not canonically withdraw or progress the staged
 * documents over SSH — the four lifecycle operations existed only as controller
 * methods. The gap matters because of what an operator reaches for instead: a
 * direct UPDATE, a Tinker `->update(['status' => ...])`, a hand-edited
 * `published_by`, a manually decremented quota bucket. Every one of those
 * bypasses the transition map, the branch scope, the policy, the quota
 * semantics and the audit trail at once, and leaves clinical evidence asserting
 * something that never happened.
 *
 * IT IS AN ADAPTER, NOT A SECOND SET OF RULES. Every action delegates to
 * LegacyRmeImportLifecycleService — the SAME class LegacyRmeImportController
 * calls — so the capability flag, the server-resolved branch scope, the route
 * permission, LegacyRmeImportPolicy, the 1A transition map, the publish-time
 * revalidation of patient/branch/date, the quota semantics and the audit trail
 * are identical to the browser. There is no CLI identity, no `--force`, and no
 * flag that widens anything.
 *
 * SAFE BY DEFAULT, THREE WAYS:
 *
 *   ONE IMPORT AT A TIME. `--import=` names exactly one row. There is no batch
 *   mode, no `--all`, no branch-wide or wave-wide selector. A recovery tool
 *   whose blast radius is "every document in the wave" is how one wrong command
 *   at 2am becomes an incident; the operator loops in their own shell if they
 *   mean to, and each iteration is separately authorized and separately audited.
 *
 *   DRY RUN UNLESS `--apply`. Without it the command reports whether the action
 *   WOULD be allowed and writes nothing — no row, no audit entry, no job.
 *
 *   AN EXPLICIT HUMAN ACTOR. `--actor=` names a real, active account and its
 *   permissions are checked exactly as in the browser. The Linux user, the SSH
 *   login and root are never an application identity.
 *
 * WHAT IT CANNOT DO, BY CONSTRUCTION. It cannot publish an unreviewed import
 * (the transition map refuses), re-drive a terminal one, reach a row outside the
 * actor's branch scope, refund a consumed quota slot, alter a source checksum,
 * override a clinical date, or synthesize a human attestation. Reviewing and
 * publishing remain acts of certification by an authorized person: this command
 * lets that person perform them over SSH — it does not let anyone perform them
 * on that person's behalf.
 *
 * EXIT CODES. 0 only when the requested outcome was reached (or a dry run
 * completed). Any refusal — unauthorized, wrong state, wrong branch, unknown
 * actor, separation of duties, capability off — exits non-zero, so a wrapper
 * script cannot mistake a refusal for success.
 */
class LegacyRmeImportAdminCommand extends Command
{
    protected $signature = 'legacy-rme:import-admin
        {action : cancel|review|publish|retry}
        {--import= : Id of the staged legacy import the action targets}
        {--actor= : Id or email of the user the action is attributed to}
        {--title= : Archive title (publish only)}
        {--description= : Archive description (publish only)}
        {--apply : Perform the change; without it nothing is written}
        {--json : Emit the outcome as JSON}';

    protected $description = 'Cancel, review, publish or retry one legacy RME import through the canonical service (dry-run unless --apply)';

    /**
     * The same bounds PublishLegacyRmeImportRequest enforces in the browser.
     * Kept in step deliberately: a limit that lives only in a FormRequest
     * constrains one caller, not the capability.
     */
    private const MAX_TITLE_LENGTH = 150;

    private const MAX_DESCRIPTION_LENGTH = 2000;

    public function handle(LegacyRmeImportLifecycleService $lifecycle): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        if (! LegacyRmeLifecycleAction::isValid($action)) {
            return $this->refuse(
                $action,
                LegacyRmeLifecycleRefusal::UNKNOWN_ACTION,
                sprintf(
                    'Tindakan %s tidak dikenali. Gunakan salah satu: %s.',
                    $action === '' ? '(kosong)' : $action,
                    implode(', ', LegacyRmeLifecycleAction::ALL),
                ),
            );
        }

        $importId = $this->importId();

        if ($importId === null) {
            return $this->refuse(
                $action,
                LegacyRmeLifecycleRefusal::IMPORT_REQUIRED,
                'Sertakan --import= dengan id impor yang valid (bilangan bulat positif).',
            );
        }

        // Actor and label shape are settled BEFORE anything is resolved, so a
        // malformed invocation never reaches the application services at all.
        try {
            $actor = $this->resolveActor();
            $attributes = $this->archiveAttributes();
        } catch (LegacyRmeCommandRefusal $refusal) {
            return $this->refuse($action, $refusal->refusalCode, $refusal->getMessage(), $importId);
        }

        $actorId = (int) $actor->getKey();

        try {
            if (! $this->option('apply')) {
                return $this->emit(
                    $lifecycle->preview($actor, $importId, $action, LegacyRmeAuditEvent::CHANNEL_CLI),
                    dryRun: true,
                );
            }

            return $this->emit(
                $lifecycle->perform(
                    $actor,
                    $importId,
                    $action,
                    $attributes,
                    LegacyRmeAuditEvent::CHANNEL_CLI,
                ),
                dryRun: false,
            );
        } catch (LegacyRmeImportNotInScope $exception) {
            // Deliberately indistinguishable from "no such import": answering
            // otherwise would let an operator pinned to one branch enumerate ids
            // and learn what another branch has staged.
            return $this->refuse(
                $action,
                LegacyRmeLifecycleRefusal::IMPORT_NOT_IN_SCOPE,
                'Impor tersebut tidak ditemukan dalam cakupan cabang akun ini.',
                $importId,
                $actorId,
            );
        } catch (AuthorizationException $exception) {
            // A typed denial knows which gate refused — the missing permission
            // and the wrong state have different remedies and different people
            // to call. An untyped one (a policy raising it directly) falls back
            // to the generic code rather than guessing.
            return $this->refuse(
                $action,
                $exception instanceof LegacyRmeLifecycleDenied
                    ? $exception->refusalCode
                    : LegacyRmeLifecycleRefusal::POLICY_DENIED,
                $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : sprintf('Akun ini tidak berwenang menjalankan tindakan %s pada impor tersebut.', $action),
                $importId,
                $actorId,
            );
        } catch (ValidationException $exception) {
            // The canonical service declined: wrong state, missing source,
            // unusable pages, a date that no longer holds, or separation of
            // duties. The service's own message is the explanation.
            return $this->refuse(
                $action,
                $this->classifyValidationFailure($exception),
                $this->firstMessage($exception),
                $importId,
                $actorId,
            );
        }
    }

    /**
     * SEPARATION_OF_DUTIES and FEATURE_DISABLED arrive as ValidationExceptions
     * on known fields, and an operator scripting against `--json` needs to tell
     * them from an ordinary wrong-state refusal — the remedies are completely
     * different (find a second person; switch the capability on; wait for the
     * render to finish).
     */
    private function classifyValidationFailure(ValidationException $exception): string
    {
        $fields = array_keys($exception->errors());

        if (in_array('legacy_rme', $fields, true)) {
            return LegacyRmeLifecycleRefusal::FEATURE_DISABLED;
        }

        if (in_array('actor', $fields, true)) {
            return LegacyRmeLifecycleRefusal::SEPARATION_OF_DUTIES;
        }

        return LegacyRmeLifecycleRefusal::SERVICE_REFUSED;
    }

    /**
     * The account the action is attributed to.
     *
     * REQUIRED, REAL AND ACTIVE. An audit row whose actor is "the server"
     * cannot be reviewed by a human afterwards, and the policy needs a real
     * subject. The Linux/SSH identity is deliberately NOT consulted: root over
     * SSH is not an application authority, and treating it as one would make
     * every gate below advisory.
     *
     * @throws LegacyRmeCommandRefusal
     */
    private function resolveActor(): User
    {
        $identifier = trim((string) $this->option('actor'));

        if ($identifier === '') {
            throw new LegacyRmeCommandRefusal(
                LegacyRmeLifecycleRefusal::ACTOR_REQUIRED,
                'Sertakan --actor= dengan id atau email pengguna yang menjalankan tindakan ini.',
            );
        }

        // Soft-deleted users are excluded by the model's default scope, so a
        // removed account can never act.
        $user = ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('email', $identifier)->first();

        if ($user === null) {
            throw new LegacyRmeCommandRefusal(
                LegacyRmeLifecycleRefusal::ACTOR_NOT_FOUND,
                'Pengguna tersebut tidak ditemukan.',
            );
        }

        if (! (bool) $user->is_active) {
            throw new LegacyRmeCommandRefusal(
                LegacyRmeLifecycleRefusal::ACTOR_INACTIVE,
                'Akun tersebut sudah tidak aktif dan tidak dapat menjalankan tindakan ini.',
            );
        }

        return $user;
    }

    private function importId(): ?int
    {
        $raw = trim((string) $this->option('import'));

        if ($raw === '' || ! ctype_digit($raw) || (int) $raw < 1) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * The two optional publish labels, held to the SAME bounds
     * PublishLegacyRmeImportRequest enforces in the browser.
     *
     * A FormRequest only constrains the caller that goes through it. Without this
     * the command line would be a way to write an archive label the browser would
     * have rejected — the precise "HTTP rule A, CLI rule B" drift this workstream
     * exists to prevent. The publish service bounds both again as a backstop.
     *
     * @return array{title: string|null, description: string|null}
     *
     * @throws LegacyRmeCommandRefusal
     */
    private function archiveAttributes(): array
    {
        $title = trim((string) $this->option('title'));
        $description = trim((string) $this->option('description'));

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new LegacyRmeCommandRefusal(
                LegacyRmeLifecycleRefusal::INVALID_ARCHIVE_LABEL,
                sprintf('Judul arsip maksimal %d karakter.', self::MAX_TITLE_LENGTH),
            );
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new LegacyRmeCommandRefusal(
                LegacyRmeLifecycleRefusal::INVALID_ARCHIVE_LABEL,
                sprintf('Keterangan arsip maksimal %d karakter.', self::MAX_DESCRIPTION_LENGTH),
            );
        }

        return [
            'title' => $title !== '' ? $title : null,
            'description' => $description !== '' ? $description : null,
        ];
    }

    private function emit(LegacyRmeLifecycleOutcome $outcome, bool $dryRun): int
    {
        $payload = $outcome->toArray();

        if ($dryRun) {
            $payload['note'] = $outcome->eligible
                ? 'Dry run — belum ada perubahan. Jalankan ulang dengan --apply untuk menerapkan.'
                : 'Dry run — tindakan ini akan ditolak. Tidak ada perubahan.';
        }

        $this->render($payload);

        // A dry run that found the action ineligible is a successful REPORT, but
        // it is not the outcome the operator asked for; a script that branches
        // on the exit code must not read "would be refused" as "done".
        return $outcome->eligible ? self::SUCCESS : self::FAILURE;
    }

    private function refuse(string $action, string $code, string $message, ?int $importId = null, ?int $actorId = null): int
    {
        $this->render(LegacyRmeLifecycleOutcome::refused(
            LegacyRmeLifecycleAction::isValid($action) ? $action : 'unknown',
            $code,
            $message,
            LegacyRmeAuditEvent::CHANNEL_CLI,
            $importId,
            $actorId,
        )->toArray());

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function render(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        foreach ($payload as $key => $value) {
            $this->components->twoColumnDetail(
                (string) $key,
                is_scalar($value) || $value === null
                    ? var_export($value, true)
                    : (string) json_encode($value, JSON_UNESCAPED_SLASHES),
            );
        }

        if (($payload['refusal_message'] ?? null) !== null) {
            $this->components->error((string) $payload['refusal_message']);
        }
    }

    private function firstMessage(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            foreach ((array) $messages as $message) {
                return (string) $message;
            }
        }

        return 'Tindakan ditolak oleh layanan kanonik.';
    }
}
