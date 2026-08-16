<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\LegacyRme\Interfaces\LegacyRmeImportRepositoryInterface;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeAuditEvent;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeImportNotInScope;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleAction;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleDenied;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleOutcome;
use App\Modules\LegacyRme\Support\LegacyRmeLifecycleRefusal;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-OPS-CLI-1 — THE single business path for every import lifecycle
 * operation, whichever surface asked for it.
 *
 * WHY IT EXISTS. Wave-2 exposed a real operational gap: an operator aborting a
 * production migration could not canonically withdraw or progress a staged
 * import over SSH, because cancel/review/publish/retry lived only as controller
 * methods. The alternatives an operator actually reaches for in that moment —
 * a direct UPDATE, a Tinker `->update(['status' => ...])`, editing
 * `imported_by`/`published_by`, hand-adjusting a quota bucket — bypass the
 * transition map, the branch scope, the policy, the quota semantics and the
 * audit trail simultaneously, and leave clinical evidence that quietly says
 * something untrue.
 *
 * THE CONVERGENCE RULE — THIS IS THE WHOLE SAFETY ARGUMENT.
 *
 *   THE CLI IS AN ADAPTER, NEVER A SECOND SET OF RULES.
 *
 * Both LegacyRmeImportController and LegacyRmeImportAdminCommand enter here, and
 * this class delegates every state change to the SAME canonical services the
 * browser has always used — LegacyRmeImportProcessingService for retry/cancel
 * and LegacyRmePublishService for review/publish. Nothing here re-implements a
 * transition, a date rule, a branch derivation, a duplicate check, a quota
 * decision or an audit write. An auditor asking whether the command line is
 * weaker than the browser only has to verify that one property, and it holds by
 * construction: there is no branch in this file that a controller does not also
 * take.
 *
 * THE GATE ORDER IS DELIBERATE, AND EVERY STEP FAILS CLOSED:
 *
 *   1. FEATURE FLAG. The capability is off by default. HTTP has always answered
 *      404 while it is off; the CLI refuses with FEATURE_DISABLED. A recovery
 *      tool that worked while the emergency stop was pressed would BE the
 *      bypass this sprint exists to remove.
 *   2. SCOPE RESOLUTION. The import is fetched through the repository with the
 *      actor's SERVER-RESOLVED branch ids (LegacyRmeWorkspaceScope). An id on a
 *      command line is exactly as untrusted as an id in a URL, and neither can
 *      reach a row outside the caller's scope.
 *   3. NAMED PERMISSION. The permission the equivalent HTTP ROUTE declares as
 *      middleware. The policy re-checks it, so this is defence in depth — but
 *      it also makes "the CLI carries the same door" assertable in a test.
 *   4. POLICY. LegacyRmeImportPolicy, unchanged: branch scope again, plus the
 *      1A transition gate. A terminal import can never be re-driven.
 *   5. SEPARATION OF DUTIES. Optional, config-gated, applied to BOTH surfaces
 *      identically — see assertSeparationOfDuties().
 *   6. CANONICAL SERVICE. Only now does anything get written.
 *
 * WHAT IT NEVER DOES. It never writes a status column, never touches a quota
 * bucket, never refunds a consumed slot, never edits `uploaded_by` or
 * `published_by`, never creates a ClinicVisit, MedicalRecord, invoice, payment,
 * odontogram, lab candidate/order or SATUSEHAT candidate, and never synthesizes
 * a human attestation. Publishing a legacy archive is filing a historical
 * document, not recording an encounter.
 */
class LegacyRmeImportLifecycleService
{
    public function __construct(
        private readonly LegacyRmeImportRepositoryInterface $imports,
        private readonly LegacyRmeImportService $importService,
        private readonly LegacyRmeImportProcessingService $processing,
        private readonly LegacyRmePublishService $publishing,
        private readonly LegacyRmeWorkspaceScope $scope,
        private readonly LegacyRmeFeatureGuard $feature,
        private readonly LegacyRmeAuditService $audit,
        private readonly Gate $gate,
    ) {}

    /**
     * Resolve a staged import inside the actor's own branch scope.
     *
     * The ONLY way this service ever obtains an import. There is no unscoped
     * lookup here — `findForProcessing()` exists for the queue, which runs with
     * no authenticated user, and must never become a convenience for a caller
     * that does have one.
     *
     * @throws LegacyRmeImportNotInScope
     */
    public function resolveForActor(User $actor, int $importId): LegacyRmeImport
    {
        $import = $this->imports->findByIdInBranches(
            $this->scope->branchIdsFor($actor),
            $importId,
            $this->scope->includesUnscopedRowsFor($actor),
        );

        if ($import === null) {
            throw new LegacyRmeImportNotInScope;
        }

        return $import;
    }

    /**
     * Read-only preflight: would this operation be allowed right now?
     *
     * GENUINELY READ-ONLY. It resolves, asks the gate with `allows()` rather
     * than `authorize()`, and reads the transition map. It calls no canonical
     * mutation service, opens no transaction, dispatches no job and writes no
     * audit row — asserted by a test that counts rows before and after.
     *
     * It reports EVERY blocker it can see rather than the first, because an
     * operator fixing one problem should not have to re-run to discover the
     * next one.
     *
     * @throws LegacyRmeImportNotInScope
     */
    public function preview(User $actor, int $importId, string $action, string $channel = LegacyRmeAuditEvent::CHANNEL_CLI): LegacyRmeLifecycleOutcome
    {
        $this->assertKnownAction($action);

        if (! $this->feature->migrationEnabled()) {
            return LegacyRmeLifecycleOutcome::refused(
                $action,
                LegacyRmeLifecycleRefusal::FEATURE_DISABLED,
                'Fitur arsip RME lama belum diaktifkan pada deployment ini.',
                $channel,
                $importId,
                $actor->getKey() !== null ? (int) $actor->getKey() : null,
            );
        }

        $import = $this->resolveForActor($actor, $importId);

        $blockers = [];
        $message = null;

        $permission = LegacyRmeLifecycleAction::requiredPermission($action);

        if ($permission !== null && ! $actor->can($permission)) {
            $blockers[] = LegacyRmeLifecycleRefusal::PERMISSION_DENIED;
            $message ??= sprintf('Akun ini tidak memiliki izin %s.', $permission);
        }

        if (! $this->gate->forUser($actor)->allows(LegacyRmeLifecycleAction::policyAbility($action), $import)) {
            $blockers[] = LegacyRmeLifecycleRefusal::POLICY_DENIED;
            $message ??= sprintf(
                'Tindakan %s tidak diizinkan untuk impor berstatus %s.',
                $action,
                (string) $import->status,
            );
        }

        // The transition map, asked DIRECTLY rather than through the policy.
        //
        // Load-bearing for exactly one account: a Super Admin's global
        // `Gate::before` bypass makes `allows()` return true unconditionally, so
        // for them the policy predicts nothing. Without this check a dry run
        // would tell the one operator most likely to be running a 2am recovery
        // that a doomed operation is "eligible". The canonical service still
        // refuses it — this only stops the preflight from lying about it.
        if ($this->transitionBlocked($import, $action)) {
            $blockers[] = LegacyRmeLifecycleRefusal::TRANSITION_NOT_ALLOWED;
            $message ??= sprintf(
                'Impor berstatus %s tidak dapat di-%s.',
                (string) $import->status,
                $action,
            );
        }

        if ($action === LegacyRmeLifecycleAction::PUBLISH && $this->violatesSeparationOfDuties($actor, $import)) {
            $blockers[] = LegacyRmeLifecycleRefusal::SEPARATION_OF_DUTIES;
            $message ??= self::SEPARATION_MESSAGE;
        }

        return LegacyRmeLifecycleOutcome::preview(
            $action,
            $import,
            $actor->getKey() !== null ? (int) $actor->getKey() : null,
            $channel,
            $blockers,
            $message,
        );
    }

    /**
     * Perform a lifecycle operation through the canonical service.
     *
     * @param  array{title?: string|null, description?: string|null}  $attributes  publish-only archive labels
     *
     * @throws LegacyRmeImportNotInScope the import does not exist in the actor's scope
     * @throws AuthorizationException the actor may not do this
     * @throws ValidationException the capability is off, or the canonical service refused
     */
    public function perform(
        User $actor,
        int $importId,
        string $action,
        array $attributes = [],
        string $channel = LegacyRmeAuditEvent::CHANNEL_CLI,
    ): LegacyRmeLifecycleOutcome {
        $this->assertKnownAction($action);

        // Gate 1. Never reachable from HTTP (the controller has already answered
        // 404), and load-bearing for the CLI: the emergency stop must stop the
        // command line too.
        $this->feature->assertMigrationEnabled();

        // Gate 2.
        $import = $this->resolveForActor($actor, $importId);
        $previousStatus = $import->status;

        // Gate 3.
        $this->assertRoutePermission($actor, $action);

        // Gate 4. LegacyRmeLifecycleDenied extends AuthorizationException, so
        // the browser still answers 403 exactly as before; the command line gets
        // a code that tells the operator WHICH gate refused.
        if (! $this->gate->forUser($actor)->allows(LegacyRmeLifecycleAction::policyAbility($action), $import)) {
            throw new LegacyRmeLifecycleDenied(
                LegacyRmeLifecycleRefusal::POLICY_DENIED,
                sprintf(
                    'Tindakan %s tidak diizinkan untuk impor berstatus %s.',
                    $action,
                    (string) $import->status,
                ),
            );
        }

        // Gate 5.
        if ($action === LegacyRmeLifecycleAction::PUBLISH) {
            $this->assertSeparationOfDuties($actor, $import);
        }

        // Gate 6. The audit rows the canonical services write inside this
        // closure are tagged with the surface that asked, so a reader can tell
        // an SSH recovery from a browser action without a second audit event
        // and without changing what any existing event MEANS.
        return $this->audit->withChannel($channel, function () use ($actor, $import, $action, $attributes, $previousStatus, $channel): LegacyRmeLifecycleOutcome {
            $actorId = $actor->getKey() !== null ? (int) $actor->getKey() : null;

            return match ($action) {
                LegacyRmeLifecycleAction::CANCEL => LegacyRmeLifecycleOutcome::applied(
                    $action,
                    $this->processing->cancel($import, $actor),
                    $previousStatus,
                    $actorId,
                    $channel,
                ),
                LegacyRmeLifecycleAction::RETRY => LegacyRmeLifecycleOutcome::applied(
                    $action,
                    $this->processing->retry($import, $this->importService, $actor),
                    $previousStatus,
                    $actorId,
                    $channel,
                ),
                LegacyRmeLifecycleAction::REVIEW => $this->reviewOutcome($import, $actor, $previousStatus, $channel),
                LegacyRmeLifecycleAction::PUBLISH => $this->publishOutcome($import, $attributes, $actor, $previousStatus, $channel),
            };
        });
    }

    /**
     * Re-reviewing an already REVIEWED import is a documented no-op in the
     * canonical service (the operator pressed the button twice). Reported as
     * `changed: false` so an automated caller can tell a fresh certification
     * from a repeat without having to diff statuses itself.
     */
    private function reviewOutcome(LegacyRmeImport $import, User $actor, ?string $previousStatus, string $channel): LegacyRmeLifecycleOutcome
    {
        $reviewed = $this->publishing->review($import, $actor);

        return LegacyRmeLifecycleOutcome::applied(
            LegacyRmeLifecycleAction::REVIEW,
            $reviewed,
            $previousStatus,
            $actor->getKey() !== null ? (int) $actor->getKey() : null,
            $channel,
            changed: $previousStatus !== $reviewed->status,
        );
    }

    private function publishOutcome(
        LegacyRmeImport $import,
        array $attributes,
        User $actor,
        ?string $previousStatus,
        string $channel,
    ): LegacyRmeLifecycleOutcome {
        /** @var LegacyRmeRecord $record */
        $record = $this->publishing->publish($import, $attributes, $actor);

        // The canonical service updates the staging row inside its own
        // transaction, so the in-memory instance is stale by design; re-read it
        // rather than assuming the new status.
        $published = $import->refresh();

        return LegacyRmeLifecycleOutcome::applied(
            LegacyRmeLifecycleAction::PUBLISH,
            $published,
            $previousStatus,
            $actor->getKey() !== null ? (int) $actor->getKey() : null,
            $channel,
            record: $record,
            changed: $previousStatus !== $published->status,
        );
    }

    /**
     * Would the 1A transition map refuse this operation right now?
     *
     * READ-ONLY AND PREDICTIVE ONLY. It exists for `preview()`; `perform()`
     * never calls it, because the canonical services already own this rule and
     * re-implementing it on the write path is precisely how two sets of rules
     * appear. The idempotent no-ops the canonical services document (re-reviewing
     * a REVIEWED import, re-publishing one that already produced its record) are
     * reported as allowed, because they are.
     */
    private function transitionBlocked(LegacyRmeImport $import, string $action): bool
    {
        return match ($action) {
            LegacyRmeLifecycleAction::CANCEL => ! $import->canTransitionTo(LegacyRmeImportStatus::CANCELLED),

            LegacyRmeLifecycleAction::REVIEW => ! $import->canTransitionTo(LegacyRmeImportStatus::REVIEWED)
                && $import->status !== LegacyRmeImportStatus::REVIEWED,

            LegacyRmeLifecycleAction::PUBLISH => ! $import->canTransitionTo(LegacyRmeImportStatus::PUBLISHED)
                && $import->status !== LegacyRmeImportStatus::PUBLISHED,

            // A PROCESSING import whose worker died is first moved to FAILED by
            // the canonical service, so the legal FAILED → QUEUED path applies;
            // that is a real retry route and the preflight must not call it
            // impossible.
            LegacyRmeLifecycleAction::RETRY => ! $import->canTransitionTo(LegacyRmeImportStatus::QUEUED)
                && $import->status !== LegacyRmeImportStatus::PROCESSING,

            default => true,
        };
    }

    private const SEPARATION_MESSAGE = 'Akun yang mengunggah dokumen ini tidak boleh menerbitkannya sendiri. Publikasi harus dilakukan oleh pemeriksa yang berbeda.';

    /**
     * SEPARATION OF DUTIES — an account-level check, applied identically to
     * every surface.
     *
     * WHAT ACTUALLY ENFORCES MAKER/CHECKER TODAY, RECORDED HONESTLY. It is the
     * ROLE SPLIT, not this method: Wave-1 gave the maker `create_legacy_rme_imports`
     * and deliberately withheld `review`/`publish`, and gave the checker
     * `review`+`publish` while withholding `create`. A maker therefore cannot
     * publish anything at all, on any surface, with or without this check — the
     * policy refuses at gate 3/4. That split is the load-bearing control and it
     * is unchanged by this sprint.
     *
     * WHAT THIS ADDS. Defence in depth for the one account that can hold both
     * duties — a Super Admin, whose `Gate::before` bypass makes every policy
     * answer yes. With this on, even that account cannot certify a document it
     * filed itself.
     *
     * WHY IT DEFAULTS OFF. Turning it on changes BROWSER behaviour too, on live
     * clinical data, and that is the owner's call rather than a side effect of
     * shipping a CLI. It mirrors the existing `require_separate_approver` switch
     * exactly, including its "turn this on once two staffed accounts exist"
     * posture. Off, both surfaces behave precisely as they do today; on, both
     * tighten together — there is deliberately no way to have it in the browser
     * but not over SSH, or the reverse.
     *
     * @throws ValidationException
     */
    private function assertSeparationOfDuties(User $actor, LegacyRmeImport $import): void
    {
        if ($this->violatesSeparationOfDuties($actor, $import)) {
            throw ValidationException::withMessages([
                'actor' => self::SEPARATION_MESSAGE,
            ]);
        }
    }

    private function violatesSeparationOfDuties(User $actor, LegacyRmeImport $import): bool
    {
        if (! (bool) config('legacy_rme_operations.require_separate_publisher', false)) {
            return false;
        }

        $uploader = $import->uploaded_by !== null ? (int) $import->uploaded_by : null;

        // A row with no recorded uploader predates attribution. Refusing it
        // would strand a document nobody can ever publish, and inventing an
        // uploader to compare against would be a guess about who filed it.
        if ($uploader === null) {
            return false;
        }

        return $uploader === (int) $actor->getKey();
    }

    /**
     * @throws AuthorizationException
     */
    private function assertRoutePermission(User $actor, string $action): void
    {
        $permission = LegacyRmeLifecycleAction::requiredPermission($action);

        if ($permission !== null && ! $actor->can($permission)) {
            throw new LegacyRmeLifecycleDenied(
                LegacyRmeLifecycleRefusal::PERMISSION_DENIED,
                sprintf('Akun ini tidak memiliki izin %s.', $permission),
            );
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertKnownAction(string $action): void
    {
        if (! LegacyRmeLifecycleAction::isValid($action)) {
            throw ValidationException::withMessages([
                'action' => sprintf('Tindakan %s tidak dikenali.', $action),
            ]);
        }
    }
}
