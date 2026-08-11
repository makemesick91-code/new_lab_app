<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\LegacyRme\Support\LegacyRmeBranchResolution;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use Illuminate\Database\Eloquent\Collection;

/**
 * LEGACY-RME-PDF-FIX-ROLL2-1 — the SINGLE source of truth for the branch a
 * legacy RME document belongs to.
 *
 * THE RULE. A legacy archive's branch is DERIVED from the branch-code segment
 * of the patient's canonical Nomor RM — it is never chosen by the operator:
 *
 *     DG-TKM1-2024-9985  →  TKM1  →  Cabang Telkomas
 *
 * WHY IT IS NOT OPERATOR INPUT. `origin_branch_id` is not a descriptive note:
 * LegacyRmeImportRepository::scoped() filters row visibility on it and
 * LegacyRmeImportPolicy::inScope() evaluates it. Letting a request choose it
 * would let an operator file a patient's history under a branch that does not
 * own that patient, and quietly change who can read it afterwards. The patient's
 * own RM already states the answer, so the server reads it there.
 *
 * FAIL CLOSED, ALWAYS. There is no fallback: not the acting user's branch, not
 * BranchContext, not the first RME-enabled branch, not a pilot branch, and never
 * a value submitted with the request. A missing, malformed, unknown, ambiguous,
 * inactive or non-RME branch code is refused with a stable code, so the operator
 * fixes the patient master data instead of the archive silently landing in the
 * wrong place.
 *
 * Definitions are reused, not re-derived: the RM format comes from
 * PatientMedicalRecordNumberService (which composes it), and "active and
 * RME-enabled" comes from BranchService::rmeEnabledIds() — the same set the
 * rest of the RME workspace uses.
 */
class LegacyRmeBranchResolver
{
    public function __construct(
        private readonly PatientMedicalRecordNumberService $medicalRecordNumbers,
        private readonly BranchService $branches,
        private readonly LegacyRmeWorkspaceScope $scope,
    ) {}

    /**
     * Resolve the owning branch from the patient's Nomor RM.
     *
     * When an actor is supplied the resolved branch must also be inside that
     * actor's own workspace scope. A scoped operator may not archive another
     * branch's history — and could not read the row afterwards anyway, so
     * refusing is both the safe and the honest outcome.
     */
    public function resolveForPatient(Patient $patient, ?User $actor = null): LegacyRmeBranchResolution
    {
        $rm = $patient->medical_record_number;

        if (! is_string($rm) || trim($rm) === '') {
            return LegacyRmeBranchResolution::failure(
                LegacyRmeBranchResolution::CODE_RM_MISSING,
                'Pasien ini belum memiliki Nomor RM, sehingga cabang arsip tidak dapat ditentukan. Lengkapi Nomor RM pasien terlebih dahulu.',
            );
        }

        $branchCode = $this->medicalRecordNumbers->branchCodeFrom($rm);

        if ($branchCode === null) {
            return LegacyRmeBranchResolution::failure(
                LegacyRmeBranchResolution::CODE_INVALID_RM_BRANCH_CODE,
                'Format Nomor RM pasien tidak sesuai pola DG-KODECABANG-TAHUN-NOMOR, sehingga cabang arsip tidak dapat ditentukan.',
            );
        }

        /** @var Collection<int, Branch> $matches */
        $matches = Branch::query()->where('code', $branchCode)->get();

        if ($matches->isEmpty()) {
            return LegacyRmeBranchResolution::failure(
                LegacyRmeBranchResolution::CODE_BRANCH_NOT_FOUND,
                sprintf('Kode cabang %s pada Nomor RM pasien tidak terdaftar pada master cabang.', $branchCode),
                $branchCode,
            );
        }

        // DEFENCE IN DEPTH. `mst_branches.code` is UNIQUE, so this branch is
        // unreachable while that constraint holds — which is exactly why
        // RM-derived resolution is unambiguous in the first place. It is kept
        // because the alternative, if the constraint were ever relaxed, would be
        // to silently guess which branch owns a patient's history.
        if ($matches->count() > 1) {
            return LegacyRmeBranchResolution::failure(
                LegacyRmeBranchResolution::CODE_BRANCH_AMBIGUOUS,
                sprintf('Kode cabang %s cocok dengan lebih dari satu cabang. Perbaiki master cabang terlebih dahulu.', $branchCode),
                $branchCode,
            );
        }

        $branch = $matches->first();
        $branchId = (int) $branch->getKey();

        if (! (bool) $branch->is_active) {
            return LegacyRmeBranchResolution::failure(
                LegacyRmeBranchResolution::CODE_BRANCH_INACTIVE,
                sprintf('Cabang %s sedang nonaktif, sehingga arsip RME lama tidak dapat diimpor untuk cabang tersebut.', $branchCode),
                $branchCode,
            );
        }

        // "Active and RME-enabled" is defined once, by BranchService. MAIN is
        // excluded by that definition and is never an archive owner.
        if (! in_array($branchId, $this->branches->rmeEnabledIds(), true)) {
            return LegacyRmeBranchResolution::failure(
                LegacyRmeBranchResolution::CODE_BRANCH_NOT_RME_ENABLED,
                sprintf('Cabang %s bukan Cabang RME, sehingga arsip RME lama tidak dapat diimpor untuk cabang tersebut.', $branchCode),
                $branchCode,
            );
        }

        if ($actor !== null && ! $this->scope->allows($actor, $branchId)) {
            return LegacyRmeBranchResolution::failure(
                LegacyRmeBranchResolution::CODE_BRANCH_OUT_OF_SCOPE,
                sprintf('Arsip pasien ini milik cabang %s. Akun Anda tidak memiliki akses ke cabang tersebut.', $branchCode),
                $branchCode,
            );
        }

        return LegacyRmeBranchResolution::success($branchId, $branchCode, (string) $branch->name);
    }

    /**
     * Reject a request that names a branch other than the RM-derived one.
     *
     * The client value is never used as the answer — it is only compared, so a
     * mismatch surfaces as an explicit operator error instead of being silently
     * discarded. A matching value (or none at all) is accepted.
     */
    public function assertNoConflict(LegacyRmeBranchResolution $resolution, ?int $submittedBranchId): LegacyRmeBranchResolution
    {
        if (! $resolution->resolved || $submittedBranchId === null || $submittedBranchId <= 0) {
            return $resolution;
        }

        if ($submittedBranchId === $resolution->branchId) {
            return $resolution;
        }

        return LegacyRmeBranchResolution::failure(
            LegacyRmeBranchResolution::CODE_BRANCH_CONFLICT,
            sprintf(
                'Cabang arsip ditentukan dari Nomor RM pasien (%s) dan tidak dapat diubah manual.',
                (string) $resolution->branchCode,
            ),
            $resolution->branchCode,
        );
    }
}
