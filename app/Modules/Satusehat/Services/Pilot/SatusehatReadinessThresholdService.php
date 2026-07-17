<?php

namespace App\Modules\Satusehat\Services\Pilot;

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Services\SatusehatAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SATUSEHAT-4C — readiness threshold governance.
 *
 * Thresholds are config-driven DEFAULTS plus reasoned, audited, allowlisted
 * per-branch overrides. Safe defaults; branch-scoped; NEVER a global
 * enforcement switch. Changing a threshold NEVER silently resolves an issue
 * and NEVER makes a candidate ready — the canonical rule engine is untouched.
 * Historical evaluation stays reproducible via the versioned `threshold_version`.
 */
class SatusehatReadinessThresholdService
{
    public function __construct(
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * @return array<string, int>
     */
    public function defaults(): array
    {
        $thresholds = (array) config('satusehat_pilot.thresholds', []);
        unset($thresholds['version']);

        return array_map(static fn ($v) => (int) $v, $thresholds);
    }

    public function version(): int
    {
        return (int) config('satusehat_pilot.thresholds.version', 1);
    }

    /**
     * Effective thresholds for a branch profile (config defaults merged with
     * sanitized overrides). A null/absent profile → defaults.
     *
     * @return array<string, int>
     */
    public function effectiveThresholds(?SatusehatBranchPilotProfile $profile): array
    {
        $overrides = $this->sanitize((array) ($profile?->threshold_overrides ?? []));

        return array_merge($this->defaults(), $overrides);
    }

    /**
     * Allowlist + bound raw override input. Unknown keys are dropped; percent
     * thresholds clamp to 0..100; count thresholds clamp to >= 0.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, int>
     */
    public function sanitize(array $raw): array
    {
        $allow = (array) config('satusehat_pilot.overridable_thresholds', []);
        $clean = [];

        foreach ($raw as $key => $value) {
            if (! in_array($key, $allow, true) || ! is_numeric($value)) {
                continue;
            }

            $int = (int) $value;
            $clean[$key] = str_ends_with($key, '_min_percent')
                ? max(0, min(100, $int))
                : max(0, $int);
        }

        return $clean;
    }

    /**
     * Persist reasoned, audited threshold overrides for a branch profile.
     *
     * @param  array<string, mixed>  $raw
     */
    public function setOverrides(
        SatusehatBranchPilotProfile $profile,
        array $raw,
        string $reason,
        User $actor,
    ): SatusehatBranchPilotProfile {
        $reason = mb_substr(trim($reason), 0, 500);
        if (mb_strlen($reason) < (int) config('satusehat_pilot.sla.min_reason_length', 10)) {
            throw ValidationException::withMessages([
                'reason' => 'Alasan perubahan ambang batas wajib diisi dengan jelas (min. 10 karakter).',
            ]);
        }

        $overrides = $this->sanitize($raw);

        return DB::transaction(function () use ($profile, $overrides, $reason, $actor) {
            $locked = SatusehatBranchPilotProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $previous = (array) ($locked->threshold_overrides ?? []);

            $locked->update([
                'threshold_overrides' => $overrides === [] ? null : $overrides,
                'threshold_version' => $this->version(),
            ]);

            $this->audit->log(
                'satusehat_branch_pilot_profile',
                (int) $locked->id,
                SatusehatAuditLog::EVENT_PILOT_THRESHOLD_CHANGED,
                'Ambang batas kesiapan cabang diubah',
                [
                    'branch_id' => (int) $locked->branch_id,
                    'previous_override_keys' => array_keys($previous),
                    'new_override_keys' => array_keys($overrides),
                    'threshold_version' => $this->version(),
                    'reason' => $reason,
                ],
                (int) $locked->branch_id,
                $actor,
            );

            return $locked->refresh();
        });
    }
}
