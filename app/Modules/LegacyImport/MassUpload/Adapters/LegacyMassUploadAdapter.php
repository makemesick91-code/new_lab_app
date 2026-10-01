<?php

declare(strict_types=1);

namespace App\Modules\LegacyImport\MassUpload\Adapters;

use App\Models\User;
use App\Modules\LegacyImport\MassUpload\Models\LegacyMassUploadItem;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadItemVerdict;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadManifestRow;
use App\Modules\LegacyImport\MassUpload\Support\LegacyMassUploadPatientResolution;
use App\Modules\Patient\Models\Patient;
use Illuminate\Http\UploadedFile;

/**
 * The seam between mass orchestration and per-type clinical truth (§6, §7).
 *
 * Mass upload owns batching, package safety and reporting. It owns NO clinical
 * rule. Everything type-specific — which date columns exist, how a patient is
 * looked up, which service creates the lifecycle — lives behind this interface,
 * and each implementation delegates the actual decision to the canonical
 * single-item service rather than re-deciding it.
 *
 * WHY AN INTERFACE AND NOT A FLAG
 * -------------------------------
 * RME and Odontogram are genuinely asymmetric, and §33 warns against inventing
 * symmetry that is not there. RME carries a source-RM binding, a date RANGE and
 * an origin branch; Odontogram carries a single date and, until this sprint, no
 * source-RM concept at all. A shared method with `if ($type === ...)` branches
 * would bury that asymmetry in the middle of the orchestrator and make each
 * type impossible to test on its own. Two implementations keep both honest.
 */
interface LegacyMassUploadAdapter
{
    /** LegacyImportType::LEGACY_RME | LEGACY_ODONTOGRAM. */
    public function importType(): string;

    /**
     * Resolve a manifest medical record number to exactly one patient, scoped
     * to what the actor may see (§11). Zero or multiple matches must refuse.
     */
    public function resolvePatient(?User $actor, string $medicalRecordNumber): LegacyMassUploadPatientResolution;

    /**
     * Forecast whether the canonical service would accept this row now.
     *
     * Implementations must consult the REAL domain services — date rules,
     * branch resolution/admission, the single-active-document slot, the daily
     * quota, and (for both types, as of this sprint) the source-RM patient
     * binding. They must never re-implement those rules locally.
     */
    public function evaluateRow(
        Patient $patient,
        LegacyMassUploadManifestRow $row,
        ?User $actor,
    ): LegacyMassUploadItemVerdict;

    /**
     * Create the canonical lifecycle and return its import id.
     *
     * This MUST call the canonical single-item service. That call is the point
     * at which the slot lock is taken and every gate re-evaluated, so a stale
     * preflight becomes a refusal here rather than a duplicate lifecycle. Any
     * refusal is allowed to propagate — the dispatcher turns it into a BLOCKED
     * item and keeps the rest of the batch moving.
     */
    public function create(
        Patient $patient,
        LegacyMassUploadManifestRow $row,
        UploadedFile $document,
        User $actor,
    ): int;

    /**
     * Record the created import on the staging item, in the column that
     * matches this type. Non-null is the idempotency marker a resumed dispatch
     * checks before doing anything (§26, §27).
     */
    public function assignCreatedImport(LegacyMassUploadItem $item, int $importId): void;

    /**
     * Remaining business allowance for this branch today, or null for
     * unlimited.
     *
     * Null is the current production posture and the documented default
     * (`business_quota_unlimited_by_default`). Mass upload imposes no cap of
     * its own (§38); it merely reports whatever the canonical hub quota says,
     * so that if a limit is ever declared the operator sees it in preflight
     * instead of discovering it at item 101.
     */
    public function quotaRemainingToday(int $branchId): ?int;
}
