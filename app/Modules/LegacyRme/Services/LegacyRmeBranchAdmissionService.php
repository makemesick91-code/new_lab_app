<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\LegacyRme\Support\LegacyRmeAdmissionDecision;
use App\Modules\LegacyRme\Support\LegacyRmeBranchResolution;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-ROLL-3 — the SINGLE server-side answer to "may this branch
 * start new legacy migration work?".
 *
 * THE GAP THIS CLOSES. ROLL-2 declared one pilot branch in
 * `legacy_rme_rollout.pilot_scope.branch_code`, but only the readiness REPORT
 * ever read it. No runtime path consulted it, so switching the capability on
 * cleared EVERY RME-enabled branch to import at once and the single-branch
 * pilot held only because the operator chose not to touch the others. A
 * rollout that depends on discipline is not a controlled rollout.
 *
 * TWO INDEPENDENT CONCERNS, BOTH REQUIRED:
 *
 *   CAPABILITY — `rme.legacy_pdf_archive`, resolved through
 *                LegacyRmeFeatureGuard. Off means nothing, anywhere.
 *   ADMISSION  — the wave allowlist below. On means "this branch may ingest".
 *
 * WHAT IS CHECKED. Always the branch DERIVED from the patient's Nomor RM
 * (FIX-ROLL2-1), delivered as a resolved LegacyRmeBranchResolution. This class
 * deliberately accepts no branch id, code or context from a caller who got it
 * from a request: if a client could name the branch, admission would be
 * advisory again — the exact defect ROLL-3 removes.
 *
 * MATCHING IS EXACT-TOKEN. The allowlist is normalized once, at config-build
 * time, into canonical upper-case tokens. `TKM1` admits `TKM1` and nothing
 * else: `TKM` does not admit `TKM1`, and `TKM1-EXTRA` admits neither. A
 * substring or prefix must never widen a clinical rollout.
 *
 * FAIL CLOSED, ALWAYS. Unset allowlist, blank allowlist, unresolved branch,
 * forbidden branch — every one denies. There is no "probably fine" path.
 *
 * SCOPE — NORMAL DRAIN, NOT EMERGENCY STOP. This gate governs the START of new
 * migration work: the upload that creates a staging row, and a retry that puts
 * a document back on the render queue. It deliberately does NOT gate publish.
 * Removing a branch mid-wave therefore stops new intake while letting already
 * staged, human-reviewed evidence finish its lifecycle — publish still runs
 * every canonical revalidation (permission, patient, RM-derived branch, date
 * range, native boundary, state machine) on its own. To stop publish as well,
 * switch the CAPABILITY off; that is the emergency control, and it is a
 * different operational decision with a different blast radius.
 */
class LegacyRmeBranchAdmissionService
{
    public function __construct(
        private readonly LegacyRmeFeatureGuard $feature,
    ) {}

    /**
     * Whether the admission gate is enforced on this deployment.
     *
     * The switch exists so a local or CI test can exercise the pre-ROLL-3 path,
     * never so a production deployment can opt out — the readiness gate FAILS
     * when enforcement is off somewhere a real worker is expected.
     */
    public function enforced(): bool
    {
        return (bool) config('legacy_rme_rollout.admission.enforced', true);
    }

    /**
     * The canonical admitted tokens. Normalized at config-build time, so this
     * never re-parses an operator string and never reads the environment.
     *
     * @return list<string>
     */
    public function admittedBranchCodes(): array
    {
        $codes = config('legacy_rme_rollout.admission.admitted_branch_codes', []);

        if (! is_array($codes)) {
            return [];
        }

        // Defence in depth: a hand-edited cached config could hold anything.
        // Re-normalizing costs nothing and keeps the comparison exact.
        return array_values(array_unique(array_filter(
            array_map(static fn ($code): string => strtoupper(trim((string) $code)), $codes),
            static fn (string $code): bool => $code !== '' && ! in_array($code, self::forbiddenBranchCodes(), true),
        )));
    }

    /**
     * Branches that may never host a clinical migration, whatever is declared.
     *
     * @return list<string>
     */
    public static function forbiddenBranchCodes(): array
    {
        $forbidden = config('legacy_rme_rollout.admission.forbidden_branch_codes', ['MAIN']);

        if (! is_array($forbidden)) {
            return ['MAIN'];
        }

        return array_values(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            $forbidden,
        ));
    }

    /**
     * The governance reference for the CURRENT wave's approval.
     *
     * ROLL-2's `pilot_scope.approval_reference` is deliberately NOT consulted:
     * it records a historical single-branch pilot and must never authorize a
     * later multi-branch wave.
     */
    public function approvalReference(): string
    {
        return trim((string) config('legacy_rme_rollout.admission.approval_reference', ''));
    }

    /**
     * The exact branch set the current approval covers.
     *
     * @return list<string>
     */
    public function approvedBranchCodes(): array
    {
        $codes = config('legacy_rme_rollout.admission.approved_branch_codes', []);

        if (! is_array($codes)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($code): string => strtoupper(trim((string) $code)), $codes),
            static fn (string $code): bool => $code !== '',
        )));
    }

    /**
     * Admitted branches the current approval does NOT cover.
     *
     * Non-empty means the allowlist was widened without widening the approval,
     * which fails closed rather than inheriting the older, narrower decision.
     *
     * @return list<string>
     */
    public function unapprovedAdmittedBranchCodes(): array
    {
        return array_values(array_diff($this->admittedBranchCodes(), $this->approvedBranchCodes()));
    }

    /** A non-PHI wave label recorded alongside admitted ingestion. */
    public function wave(): ?string
    {
        $wave = strtoupper(trim((string) config('legacy_rme_rollout.admission.wave', '')));

        return $wave !== '' ? $wave : null;
    }

    /**
     * Decide whether the branch behind a resolved Nomor RM may ingest.
     *
     * Evaluation order matters and is deliberate: capability first (an off
     * feature explains everything and reveals nothing about the wave), then
     * resolution, then the forbidden list, then the allowlist.
     */
    public function decide(LegacyRmeBranchResolution $resolution): LegacyRmeAdmissionDecision
    {
        $wave = $this->wave();

        if (! $this->feature->migrationEnabled()) {
            return LegacyRmeAdmissionDecision::deny(
                LegacyRmeAdmissionDecision::CODE_FEATURE_DISABLED,
                'Fitur arsip RME lama belum diaktifkan.',
                $resolution->branchCode,
                $wave,
            );
        }

        if ($resolution->failed() || $resolution->branchCode === null || $resolution->branchCode === '') {
            return LegacyRmeAdmissionDecision::deny(
                LegacyRmeAdmissionDecision::CODE_BRANCH_UNRESOLVED,
                'Cabang arsip belum dapat ditentukan dari Nomor RM pasien, sehingga migrasi tidak dapat dimulai.',
                $resolution->branchCode,
                $wave,
            );
        }

        $branchCode = strtoupper(trim($resolution->branchCode));

        // Enforcement off is a test-environment posture, not a production one.
        // It is checked AFTER resolution so a disabled gate can never rescue an
        // unresolvable branch — that failure is a data problem either way.
        if (! $this->enforced()) {
            return LegacyRmeAdmissionDecision::admit($branchCode, $wave);
        }

        if (in_array($branchCode, self::forbiddenBranchCodes(), true)) {
            return LegacyRmeAdmissionDecision::deny(
                LegacyRmeAdmissionDecision::CODE_BRANCH_FORBIDDEN,
                sprintf('Cabang %s tidak pernah dapat menjadi cabang migrasi arsip RME lama.', $branchCode),
                $branchCode,
                $wave,
            );
        }

        $admitted = $this->admittedBranchCodes();

        if ($admitted === []) {
            return LegacyRmeAdmissionDecision::deny(
                LegacyRmeAdmissionDecision::CODE_NO_BRANCH_ADMITTED,
                'Belum ada cabang yang diizinkan memulai migrasi arsip RME lama pada deployment ini.',
                $branchCode,
                $wave,
            );
        }

        // Exact token comparison. in_array with strict typing over a normalized
        // list cannot match a prefix or a substring.
        if (! in_array($branchCode, $admitted, true)) {
            return LegacyRmeAdmissionDecision::deny(
                LegacyRmeAdmissionDecision::CODE_BRANCH_NOT_ADMITTED,
                sprintf('Cabang %s belum termasuk dalam gelombang migrasi arsip RME lama yang sedang berjalan.', $branchCode),
                $branchCode,
                $wave,
            );
        }

        // The wave must carry its OWN approval. Being on the allowlist proves
        // someone edited the config; it does not prove an owner authorized this
        // branch. ROLL-2's historical single-branch approval is never consulted
        // here, so it can never stretch to cover a later multi-branch wave.
        if ($this->approvalReference() === '') {
            return LegacyRmeAdmissionDecision::deny(
                LegacyRmeAdmissionDecision::CODE_WAVE_NOT_APPROVED,
                'Gelombang migrasi arsip RME lama belum memiliki referensi persetujuan yang tercatat.',
                $branchCode,
                $wave,
            );
        }

        // ...and that approval must cover THIS branch. Widening the allowlist
        // without widening the approval fails closed instead of inheriting the
        // older, narrower decision.
        if (! in_array($branchCode, $this->approvedBranchCodes(), true)) {
            return LegacyRmeAdmissionDecision::deny(
                LegacyRmeAdmissionDecision::CODE_WAVE_NOT_APPROVED,
                sprintf('Cabang %s tidak tercakup dalam persetujuan gelombang migrasi yang berlaku saat ini.', $branchCode),
                $branchCode,
                $wave,
            );
        }

        return LegacyRmeAdmissionDecision::admit($branchCode, $wave);
    }

    /**
     * Decide for an already-persisted import, whose origin branch was derived
     * from the Nomor RM when it was created. Used by the retry path, which
     * restarts render work and therefore consumes fresh capacity.
     */
    public function decideForBranchCode(?string $branchCode): LegacyRmeAdmissionDecision
    {
        $resolution = $branchCode !== null && trim($branchCode) !== ''
            ? LegacyRmeBranchResolution::success(0, strtoupper(trim($branchCode)), null)
            : LegacyRmeBranchResolution::failure(
                LegacyRmeBranchResolution::CODE_BRANCH_NOT_FOUND,
                'Cabang asal arsip tidak diketahui.',
            );

        return $this->decide($resolution);
    }

    /**
     * @throws ValidationException
     */
    public function assertAdmitted(LegacyRmeBranchResolution $resolution): LegacyRmeAdmissionDecision
    {
        $decision = $this->decide($resolution);

        if ($decision->denied()) {
            throw ValidationException::withMessages([
                'legacy_rme_admission' => (string) $decision->message,
            ]);
        }

        return $decision;
    }
}
