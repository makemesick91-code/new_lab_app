<?php

declare(strict_types=1);

namespace App\Modules\LegacyRme\Support;

use App\Services\Foundation\FeatureFlagService;
use Illuminate\Validation\ValidationException;

/**
 * LEGACY-RME-PDF-1A — the single runtime switch for the legacy RME archive.
 *
 * The flag defaults to OFF so an incomplete capability can never be reached in
 * production. Sprint 1A ships no HTTP surface at all, so nothing is exposed
 * regardless; this guard exists so every follow-up entry point (upload, review,
 * publish, viewer) checks the same flag through one place instead of reading
 * config directly.
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

    public function enabled(): bool
    {
        return $this->flags->enabled($this->flagKey());
    }

    /**
     * @throws ValidationException
     */
    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages([
                'legacy_rme' => 'Fitur arsip RME lama belum diaktifkan.',
            ]);
        }
    }
}
