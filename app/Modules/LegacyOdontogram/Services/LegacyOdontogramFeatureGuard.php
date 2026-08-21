<?php

declare(strict_types=1);

namespace App\Modules\LegacyOdontogram\Services;

use App\Services\Foundation\FeatureFlagService;
use Illuminate\Validation\ValidationException;

/**
 * FIX-04b — the runtime switch for the legacy odontogram MIGRATION capability.
 *
 * WHAT THIS FLAG GOVERNS, AND WHAT IT DOES NOT.
 *
 *   OFF  →  no upload, no processing, no retry, no review, no publish, no void.
 *           Nothing new enters or leaves the archive, anywhere.
 *
 *   OFF  →  an already-PUBLISHED legacy odontogram REMAINS READABLE to a
 *           properly authorized clinical reader. Published evidence is a
 *           patient's real clinical history; the doctor treating them must
 *           still be able to look at their old chart at the next visit without
 *           the owner re-opening the ability to import new documents.
 *
 * Published clinical read is therefore not gated here at all. It is governed by
 * the record's own state (PUBLISHED, never staged/failed/cancelled/VOID) plus
 * canonical authorization — read permission, server-resolved branch scope, and
 * for a doctor the treating relationship — and by the private disk, reachable
 * only through the policy-gated streaming actions.
 *
 * CONTAINING A READ INCIDENT uses the mechanisms that actually exist and are
 * precise: revoke `view_legacy_odontogram_archive` from the affected actors,
 * and/or VOID the offending record (its bytes stop streaming immediately while
 * the row stays auditable). There is no separate read kill switch, and one must
 * not be invented as a side effect of the migration flag.
 *
 * THIS IS NOT THE LEGACY RME FLAG. The two capabilities are independent: an
 * owner may run one and keep the other closed, and a rollback of one must never
 * disturb the other.
 *
 * Flag keys contain dots, so they must be resolved through FeatureFlagService —
 * config('feature_flags.flags.rme.legacy_odontogram_archive') would traverse
 * the dots as nesting and silently return null, which reads as "disabled" today
 * and could read as something else tomorrow.
 */
class LegacyOdontogramFeatureGuard
{
    public function __construct(
        private readonly FeatureFlagService $flags,
    ) {}

    public function flagKey(): string
    {
        $key = config('legacy_odontogram.feature_flag');

        return is_string($key) && $key !== '' ? $key : 'rme.legacy_odontogram_archive';
    }

    /**
     * Is the legacy odontogram MIGRATION / INGESTION / WRITE capability on?
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
                'legacy_odontogram' => 'Fitur arsip odontogram lama belum diaktifkan.',
            ]);
        }
    }
}
