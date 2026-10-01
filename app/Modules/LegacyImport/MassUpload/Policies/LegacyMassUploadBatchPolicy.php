<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Policies;

use App\Models\User;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;

/**
 * Authorization for mass upload batches — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §4.
 *
 * NO NEW PERMISSION IS INTRODUCED.
 *
 * Mass upload is the same act as single upload, performed in bulk, so it uses
 * the same upload permission family: `create_legacy_rme_imports` for RME and
 * `create_legacy_odontogram_imports` for odontogram. Minting a
 * `mass_upload_*` permission would have meant a role grant, and a role grant to
 * make a menu appear is how privilege creeps.
 *
 * Nothing here widens publish, review, VOID, SOD or cross-branch authority.
 * Those all remain exactly where they are — on the individual document, under
 * their own permissions — which is why a mass uploader still cannot publish
 * anything (§8, §32).
 *
 * ONE POLICY, NOT TWO
 * -------------------
 * Both surfaces share one model that carries its own `import_type`, so the
 * permission is chosen from the row rather than from the class. Two
 * near-identical policy classes would drift, and the interesting asymmetry
 * between the types lives in the adapters, not here.
 */
class LegacyMassUploadBatchPolicy
{
    public function __construct(
        private readonly LegacyRmeWorkspaceScope $scope,
    ) {}

    /**
     * There is no type-free "view any mass upload" screen, so viewAny is only
     * reachable with a concrete type. Callers use viewAnyOfType().
     */
    public function viewAny(User $user): bool
    {
        return $this->viewAnyOfType($user, LegacyImportType::LEGACY_RME)
            || $this->viewAnyOfType($user, LegacyImportType::LEGACY_ODONTOGRAM);
    }

    public function viewAnyOfType(User $user, string $importType): bool
    {
        return $user->can($this->uploadPermission($importType));
    }

    public function view(User $user, LegacyMassUploadBatch $batch): bool
    {
        return $this->viewAnyOfType($user, (string) $batch->import_type)
            && $this->inScope($user, $batch);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function createOfType(User $user, string $importType): bool
    {
        return $user->can($this->uploadPermission($importType));
    }

    /**
     * Confirming a batch creates real lifecycles, so it demands the same
     * permission as creating one document by hand — no more, no less. It does
     * NOT grant publish: every created import still lands in review and needs a
     * separate publisher (§32).
     */
    public function confirm(User $user, LegacyMassUploadBatch $batch): bool
    {
        return $this->view($user, $batch)
            && $batch->status === LegacyMassUploadBatchStatus::PREFLIGHT_READY;
    }

    public function dispatchItems(User $user, LegacyMassUploadBatch $batch): bool
    {
        return $this->view($user, $batch) && ! $batch->isTerminal();
    }

    /**
     * Cancel is a staging operation only. The status guard lives on the model
     * and is re-asserted in the service under a lock; repeating it here means
     * the UI never offers an action the server will refuse.
     */
    public function cancel(User $user, LegacyMassUploadBatch $batch): bool
    {
        return $this->view($user, $batch) && $batch->isCancellable();
    }

    public function downloadReport(User $user, LegacyMassUploadBatch $batch): bool
    {
        return $this->view($user, $batch);
    }

    /**
     * Branch isolation, reusing the same scope object the single-item policies
     * use so both answer the question identically.
     *
     * The creator is additionally always allowed to see their own batch. A
     * batch is an operator work-queue, and an operator whose branch context
     * has since changed must still be able to read the outcome of work they
     * submitted — otherwise a shift change strands a migration mid-flight.
     * This grants no access to any clinical document: the documents are guarded
     * by their own policies on their own branch.
     */
    private function inScope(User $user, LegacyMassUploadBatch $batch): bool
    {
        if ((int) $batch->created_by === (int) $user->getKey()) {
            return true;
        }

        return $this->scope->allows($user, $batch->origin_branch_id);
    }

    /**
     * Unknown types fail closed to the RME permission rather than to `true`.
     *
     * A typo'd type must not become an authorization bypass.
     */
    private function uploadPermission(string $importType): string
    {
        return match ($importType) {
            LegacyImportType::LEGACY_ODONTOGRAM => 'create_legacy_odontogram_imports',
            default => 'create_legacy_rme_imports',
        };
    }
}
