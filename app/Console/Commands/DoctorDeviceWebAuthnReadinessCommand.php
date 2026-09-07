<?php

namespace App\Console\Commands;

use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceWebAuthnLoginService;
use App\Modules\DoctorDevice\Support\WebAuthnRelyingParty;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Console\Command;
use Throwable;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — is this deployment able to run a ceremony, and is it
 * currently doing so?
 *
 * WHY THIS EXISTS
 *
 * A relying party misconfiguration does not fail on the server. It fails inside
 * the browser, on a tablet, in a clinic, with no message — the authenticator
 * simply refuses an origin whose registrable domain does not match the id the
 * credential was created under. That failure mode is undiagnosable from the
 * outside, so the configuration has to be checkable from the inside BEFORE
 * anybody is asked to stand at a tablet.
 *
 * Read-only, credential-free, and safe on production. It reports what is
 * configured and what is armed; it changes nothing and enables nothing.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *
 * It does not print a credential id, a public key, a challenge or a doctor's
 * name. The device estate is not something a console report needs to enumerate,
 * so it reports counts.
 */
class DoctorDeviceWebAuthnReadinessCommand extends Command
{
    protected $signature = 'webauthn:readiness
        {--json : Emit the report as JSON}
        {--strict : Exit non-zero when the relying party could not run a ceremony}';

    protected $description = 'Report whether the WebAuthn relying party is usable and whether doctor device credential login is armed. Read-only, no secrets.';

    /**
     * A count, or null when the schema is not there to be counted.
     *
     * This command exists to be run on production at awkward moments — during
     * a deploy, before a migration, on a fresh checkout — and a read-only
     * diagnostic that answers a schema question with a SQL stack trace is worse
     * than useless: it looks like an incident. Null is reported as `-`, and the
     * verdict says SCHEMA_UNAVAILABLE rather than claiming zero credentials,
     * because "I could not count" and "there are none" are different answers.
     */
    private function count(callable $query): ?int
    {
        try {
            return (int) $query();
        } catch (Throwable) {
            return null;
        }
    }

    public function handle(FeatureFlagService $flags, DoctorAppLoginGate $gate): int
    {
        $relyingParty = WebAuthnRelyingParty::fromConfig();

        $failure = $relyingParty->usabilityFailure();

        try {
            $userVerification = $relyingParty->userVerification();
        } catch (Throwable) {
            // A refused user-verification policy is itself a finding, not a
            // crash: reporting it is the whole point of the command.
            $userVerification = 'INVALID';
            $failure ??= 'user_verification_invalid';
        }

        $report = [
            'relying_party_id' => $relyingParty->id(),
            'allowed_origins' => $relyingParty->allowedOrigins(),
            'usable' => $failure === null,
            'usability_failure' => $failure,
            'user_verification' => $userVerification,
            'require_device_bound' => (bool) config('webauthn.device_binding.require_device_bound', true),
            'challenge_ttl_seconds' => (int) config('webauthn.ceremony.challenge_ttl_seconds', 120),

            // Both switches. Neither is changed here.
            'device_enforcement_armed' => $gate->enforcementEnabled(),
            'webauthn_login_armed' => $flags->enabled(DoctorDeviceWebAuthnLoginService::FLAG),

            // Counts, never identities.
            'devices_active' => $this->count(fn () => DoctorDevice::query()
                ->where('status', DoctorDevice::STATUS_ACTIVE)->count()),
            'credentials_usable' => $this->count(fn () => DoctorDeviceWebAuthnCredential::query()
                ->whereNull('revoked_at')->count()),
            'credentials_device_bound' => $this->count(fn () => DoctorDeviceWebAuthnCredential::query()
                ->whereNull('revoked_at')
                ->where('device_bound_verdict', DoctorDeviceWebAuthnCredential::VERDICT_DEVICE_BOUND)
                ->count()),
        ];

        /*
         * A deployment with no credentials is not broken — it is the expected
         * state before the first tablet is enrolled, and saying so out loud
         * stops an operator reading "0" as a fault.
         */
        $report['verdict'] = match (true) {
            $failure !== null => 'RELYING_PARTY_UNUSABLE',
            // A null count means the schema is not there to be counted, which
            // is not the same claim as "zero credentials" and must not be
            // reported as one.
            $report['credentials_usable'] === null => 'SCHEMA_UNAVAILABLE',
            $report['webauthn_login_armed'] && $report['credentials_usable'] === 0 => 'ARMED_WITHOUT_CREDENTIALS',
            $report['webauthn_login_armed'] => 'ARMED',
            $report['credentials_usable'] > 0 => 'READY_NOT_ARMED',
            default => 'CONFIGURED_NO_CREDENTIALS',
        };

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('WebAuthn doctor device readiness — DOCTOR-PWA-WEBAUTHN-1');
            $this->newLine();

            foreach ($report as $key => $value) {
                $this->line(strtoupper($key).'='.match (true) {
                    is_bool($value) => $value ? 'true' : 'false',
                    is_array($value) => implode(',', $value),
                    $value === null => '-',
                    default => (string) $value,
                });
            }
        }

        // Only an unusable relying party is a failure. "No credentials yet" is
        // a state, not a fault, and exiting non-zero on it would train an
        // operator to ignore the command.
        return ($this->option('strict') && $failure !== null) ? self::FAILURE : self::SUCCESS;
    }
}
