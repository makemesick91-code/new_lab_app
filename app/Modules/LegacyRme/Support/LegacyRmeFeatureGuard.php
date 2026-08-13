<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use App\Services\Foundation\FeatureFlagService;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-1A — the runtime switch for the legacy RME MIGRATION capability.
 *
 * The flag defaults to OFF so an incomplete capability can never be reached in
 * production. This guard exists so every migration entry point (upload,
 * processing, retry, review, publish, void, branch admission) checks the same
 * flag through one place instead of reading config directly.
 *
 * LEGACY-RME-PDF-HISTORY-1A — WHAT THIS FLAG DOES AND DOES NOT GOVERN.
 *
 * This guard governs the MIGRATION / INGESTION / WRITE capability ONLY. It is
 * an emergency stop for legacy MUTATIONS — it is NOT, and must never again
 * become, a switch that hides already-PUBLISHED clinical evidence.
 *
 *   OFF  →  no upload, no processing, no retry, no review, no publish, no void,
 *           no branch admission. Nothing new enters the archive, anywhere.
 *
 *   OFF  →  an already-PUBLISHED legacy record REMAINS READABLE to a properly
 *           authorized clinical reader. Published evidence is a patient's real
 *           medical history; a doctor treating that patient must still be able
 *           to read it when the patient comes in for a new visit, without the
 *           owner having to re-open the migration capability to do it.
 *
 * Published clinical read is therefore NOT flag-gated at all. It is governed by
 * the record's own state plus canonical authorization, every layer of which
 * still applies and none of which this flag can substitute for:
 *
 *   - the record is PUBLISHED (a staged, failed, cancelled or VOIDed record is
 *     never ordinary clinical history);
 *   - LegacyRmeRecordPolicy (read permission + branch scope + the treating
 *     doctor's own DoctorPatientScopeService relationship);
 *   - the private disk, reachable only through the policy-gated stream actions.
 *
 * CONTAINING A READ INCIDENT. Because read is deliberately not tied to this
 * flag, containment uses the mechanisms that actually exist and are precise:
 * revoke `view_legacy_rme_archive` / `view_legacy_rme_imports` from the affected
 * actors, and/or VOID the offending record (which immediately stops its bytes
 * streaming while preserving it as auditable evidence). There is no separate
 * read kill switch, and one must not be invented as a side effect of the
 * migration flag.
 *
 * Flag keys contain dots, so they must be resolved through FeatureFlagService —
 * config('feature_flags.flags.rme.legacy_pdf_archive') would traverse the dot
 * as nesting and silently return null.
 */
class LegacyRmeFeatureGuard
{
    public function __construct(
        private readonly FeatureFlagService $flags,
    ) {}

    public function flagKey(): string
    {
        $key = config('legacy_rme.feature_flag');

        return is_string($key) && $key !== '' ? $key : 'rme.legacy_pdf_archive';
    }

    /**
     * Is the legacy MIGRATION / INGESTION / WRITE capability switched on?
     *
     * The canonical name. Call this at every mutation boundary so the intent is
     * unmistakable at the call site and no future reader mistakes it for a
     * clinical-read gate.
     */
    public function migrationEnabled(): bool
    {
        return $this->flags->enabled($this->flagKey());
    }

    /**
     * @throws ValidationException
     */
    public function assertMigrationEnabled(): void
    {
        if (! $this->migrationEnabled()) {
            throw ValidationException::withMessages([
                'legacy_rme' => 'Fitur arsip RME lama belum diaktifkan.',
            ]);
        }
    }

    /**
     * Historical alias of {@see migrationEnabled()}.
     *
     * Kept because the rollout-readiness reporting surface and its tests read
     * the effective flag state through this name. It means MIGRATION capability
     * and nothing else — never use it to decide whether published clinical
     * evidence may be read.
     */
    public function enabled(): bool
    {
        return $this->migrationEnabled();
    }

    /**
     * Historical alias of {@see assertMigrationEnabled()}.
     *
     * @throws ValidationException
     */
    public function assertEnabled(): void
    {
        $this->assertMigrationEnabled();
    }
}
