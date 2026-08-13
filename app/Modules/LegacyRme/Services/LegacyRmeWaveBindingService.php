<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Services;

use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;

/**
 * LEGACY-RME-PDF-ROLL-4 — resolve the wave the deployment is actually running,
 * and prove its operational record agrees with the approval that authorized it.
 *
 * TWO RECORDS, ONE TRUTH.
 *
 *   CONFIG (`legacy_rme_rollout.admission`) is the AUTHORITY: the wave label,
 *   the owner's approval reference and the exact approved branch set. It is
 *   deploy-time state, changed on the server, outside this application's write
 *   path — which is the whole reason it can be trusted.
 *
 *   THE WAVE ROW is the OPERATIONAL MIRROR: the same three facts, plus
 *   everything that changes during a wave (operators, quota, pause, sign-off).
 *   It is written through the application, so it is never treated as authority.
 *
 * WHY COMPARE THEM AT ALL. Two records of the same decision drift. Someone
 * widens the environment allowlist and forgets the governance record; someone
 * edits the wave in the UI and never redeploys. Either way the deployment is
 * now running under an approval that nobody actually granted in that shape.
 *
 * ROLL-3 met this exact failure one level down — a green readiness report beside
 * an approval naming a branch that was not in the wave — and fixed it by binding
 * the approval to its scope. This is the same fix applied to the mirror: when
 * the two disagree, NEITHER is assumed correct and ingestion stops until a human
 * reconciles them. Preferring one silently would just pick a winner and hide the
 * drift, which is the failure mode, not the remedy.
 */
class LegacyRmeWaveBindingService
{
    /**
     * The wave label config declares, as a canonical token, or null when none
     * is declared.
     */
    public function declaredWaveCode(): ?string
    {
        $wave = strtoupper(trim((string) config('legacy_rme_rollout.admission.wave', '')));

        return $wave !== '' ? $wave : null;
    }

    /** The approval reference config declares for the current wave. */
    public function declaredApprovalReference(): string
    {
        return trim((string) config('legacy_rme_rollout.admission.approval_reference', ''));
    }

    /**
     * The exact branch set the declared approval covers, as canonical tokens.
     *
     * @return list<string>
     */
    public function declaredApprovedBranchCodes(): array
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
     * The operational record for the declared wave, or null when none exists.
     *
     * Matched by exact canonical code. A wave row whose code differs by case or
     * whitespace is a different wave, not a near-miss to be helpfully accepted.
     */
    public function resolveWave(): ?LegacyRmeMigrationWave
    {
        $code = $this->declaredWaveCode();

        if ($code === null) {
            return null;
        }

        return LegacyRmeMigrationWave::query()->where('code', $code)->first();
    }

    /**
     * Whether a wave row mirrors the config approval exactly.
     *
     * Compared as SETS: order is meaningless in an allowlist, so `[A,B]` and
     * `[B,A]` are the same approval. Membership is not — a set with an extra or
     * a missing branch is a different approval and must fail.
     */
    public function bindingMatches(LegacyRmeMigrationWave $wave): bool
    {
        if (trim((string) $wave->approval_reference) !== $this->declaredApprovalReference()) {
            return false;
        }

        $declared = $this->declaredApprovedBranchCodes();
        $recorded = $wave->approvedBranchCodeTokens();

        sort($declared);
        sort($recorded);

        return $declared === $recorded;
    }

    /**
     * PII-free evidence of what each side declares, for the readiness gate and
     * the operations dashboard.
     *
     * @return array<string, mixed>
     */
    public function bindingReport(?LegacyRmeMigrationWave $wave = null): array
    {
        $wave ??= $this->resolveWave();

        return [
            'declared_wave' => $this->declaredWaveCode(),
            'declared_approval_reference' => $this->declaredApprovalReference() !== ''
                ? $this->declaredApprovalReference()
                : null,
            'declared_approved_branch_codes' => $this->declaredApprovedBranchCodes(),
            'registered_wave' => $wave?->code,
            'registered_status' => $wave?->status,
            'registered_approval_reference' => $wave?->approval_reference,
            'registered_approved_branch_codes' => $wave?->approvedBranchCodeTokens() ?? [],
            'binding_matches' => $wave !== null && $this->bindingMatches($wave),
        ];
    }
}
