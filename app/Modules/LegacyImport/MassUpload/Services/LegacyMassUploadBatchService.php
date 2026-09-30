<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Services;

use App\Models\User;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\LegacyImport\MassUpload\Adapters\LegacyMassUploadAdapter;
use App\Modules\LegacyImport\MassUpload\Exceptions\LegacyMassUploadPackageRejected;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadBatch;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadBatchStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemStatus;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadReason;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Batch intake and lifecycle — FEATURE-LEGACY-RME-ODONTOGRAM-MASS-UPLOAD-1 §19.
 *
 * The sequence an operator actually walks:
 *
 *   store()   UPLOAD PACKAGE -> VALIDATE PACKAGE -> PREFLIGHT -> review
 *   confirm() (LegacyMassUploadDispatchService) -> bounded dispatch
 *
 * Package validation and preflight run in the SAME request as the upload on
 * purpose. An operator who has just waited for a 300 MB upload should be shown
 * the verdict, not a "validating…" spinner backed by a job they cannot see. If
 * this ever becomes too slow for the largest archives, the move is to queue the
 * PREFLIGHT (which creates nothing) — never the creation step.
 */
class LegacyMassUploadBatchService
{
    public function __construct(
        private readonly LegacyMassUploadPackageService $packages,
        private readonly LegacyMassUploadManifestParser $manifests,
        private readonly LegacyMassUploadPreflightService $preflight,
        private readonly LegacyMassUploadAuditService $audit,
        private readonly BranchContext $branchContext,
    ) {}

    /**
     * Accept an archive, validate it, and preflight every row.
     *
     * @throws LegacyMassUploadPackageRejected on any package-integrity refusal
     */
    public function store(
        UploadedFile $package,
        LegacyMassUploadAdapter $adapter,
        User $actor,
    ): LegacyMassUploadBatch {
        // Cheap checks before a single byte is persisted.
        $this->packages->assertUploadedPackage($package);

        $uuid = (string) Str::uuid();

        $stored = $this->packages->storePackage($package, $uuid);

        $batch = LegacyMassUploadBatch::query()->create([
            'uuid' => $uuid,
            'import_type' => $adapter->importType(),
            'status' => LegacyMassUploadBatchStatus::UPLOADED,
            'created_by' => $actor->getKey(),
            // Provenance only. Never an authorization input, and never the
            // source of an item's branch — each item's branch is resolved from
            // its own patient by the canonical service.
            'origin_branch_id' => $this->currentBranchId(),
            'package_original_name' => Str::limit((string) $package->getClientOriginalName(), 240, ''),
            'package_disk' => $this->packages->diskName(),
            'package_path' => $stored['path'],
            'package_sha256' => $stored['sha256'],
            'package_bytes' => $stored['bytes'],
        ]);

        $this->audit->logBatchEvent('MASS_UPLOAD_PACKAGE_RECEIVED', $batch, [
            'package_sha256' => $stored['sha256'],
            'package_bytes' => $stored['bytes'],
        ], $actor);

        $batch->fill(['status' => LegacyMassUploadBatchStatus::VALIDATING])->save();

        try {
            $contents = $this->packages->validateAndExtract(
                packageRelativePath: $stored['path'],
                batchUuid: $uuid,
                manifestParser: $this->manifests,
                importType: $adapter->importType(),
            );
        } catch (LegacyMassUploadPackageRejected $rejected) {
            $this->rejectPackage($batch, $rejected, $actor);

            throw $rejected;
        } catch (Throwable $exception) {
            // Anything unexpected is still a package refusal from the
            // operator's point of view, and the workspace must not be left
            // behind (§40).
            $this->rejectPackage(
                $batch,
                LegacyMassUploadPackageRejected::because(LegacyMassUploadReason::PACKAGE_UNREADABLE),
                $actor,
            );

            throw $exception;
        }

        return $this->preflight->run($batch->refresh(), $contents, $adapter, $actor);
    }

    /**
     * Cancel a batch that has not created anything yet (§29).
     *
     * Hard-guarded on two independent facts: the status must still be a
     * pre-dispatch one, AND no item may carry a created import. Either alone
     * would be enough in theory; both are checked because "cancel" must never
     * become a way to make real clinical documents disappear. Withdrawing a
     * published document is VOID, under its own authority, on that document.
     */
    public function cancel(LegacyMassUploadBatch $batch, User $actor): LegacyMassUploadBatch
    {
        return DB::transaction(function () use ($batch, $actor): LegacyMassUploadBatch {
            $locked = LegacyMassUploadBatch::query()
                ->whereKey($batch->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isCancellable()) {
                return $locked;
            }

            $createdCount = $locked->items()
                ->where(function ($query): void {
                    $query->whereNotNull('rme_legacy_import_id')
                        ->orWhereNotNull('odontogram_legacy_import_id');
                })
                ->count();

            if ($createdCount > 0) {
                // Should be unreachable given the status guard, so if it ever
                // fires, the status machine and reality have diverged and
                // refusing is the only safe answer.
                return $locked;
            }

            // Staging rows only. Nothing clinical exists to preserve.
            $locked->items()->update([
                'status' => LegacyMassUploadItemStatus::SKIPPED,
                'reason_code' => LegacyMassUploadReason::NOT_ATTEMPTED,
                'reason_message' => LegacyMassUploadReason::message(LegacyMassUploadReason::NOT_ATTEMPTED),
                'updated_at' => now(),
            ]);

            $locked->fill([
                'status' => LegacyMassUploadBatchStatus::CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            $this->audit->logBatchEvent('MASS_UPLOAD_CANCELLED', $locked, [], $actor);

            $this->packages->cleanWorkspace((string) $locked->uuid);

            $locked->fill(['workspace_cleaned_at' => now()])->save();

            return $locked->refresh();
        });
    }

    private function rejectPackage(
        LegacyMassUploadBatch $batch,
        LegacyMassUploadPackageRejected $rejected,
        User $actor,
    ): void {
        $batch->fill([
            'status' => LegacyMassUploadBatchStatus::PACKAGE_REJECTED,
            'failure_code' => $rejected->reasonCode,
            'failure_message' => $rejected->safeMessage,
        ])->save();

        $this->audit->logBatchEvent('MASS_UPLOAD_PACKAGE_REJECTED', $batch, [
            'failure_code' => $rejected->reasonCode,
        ], $actor);

        // Deterministic cleanup on the failure path too (§40).
        $this->packages->cleanWorkspace((string) $batch->uuid);

        $batch->fill(['workspace_cleaned_at' => now()])->save();
    }

    /**
     * The operator's current branch, for provenance.
     *
     * BranchContext is the only source; a request may never name a branch.
     * Failure to resolve is not fatal here because this column is descriptive —
     * every decision that matters resolves its own branch from the patient.
     */
    private function currentBranchId(): ?int
    {
        try {
            return $this->branchContext->id();
        } catch (Throwable) {
            return null;
        }
    }
}
