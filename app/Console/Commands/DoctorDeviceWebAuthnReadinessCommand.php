<?php

namespace App\Console\Commands;

use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\DoctorDevice\Models\DoctorDeviceWebAuthnCredential;
use App\Modules\DoctorDevice\Services\DoctorAppLoginGate;
use App\Modules\DoctorDevice\Services\DoctorDeviceWebAuthnLoginService;
use App\Modules\DoctorDevice\Services\DoctorWebAuthnLiveProofService;
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
 *
 * ── TWO QUESTIONS, NEVER FOLDED (FIX-...-READINESS-LIVE-PROOF-1) ──────────
 *
 * This command used to answer one question — is the leg CONFIGURED — and print
 * a verdict of ARMED that read like an answer to a second one. It is not. ARMED
 * is a feature flag plus a COUNT(*) over the credential table, and neither
 * input can change when a ceremony stops working. Between 2026-09-09 and
 * 2026-09-19 it printed ARMED every day while the browser leg produced no
 * successful assertion at all; the Android path kept working throughout, which
 * is why nobody noticed.
 *
 * So the report now carries BOTH:
 *
 *   verdict              CONFIGURATION — unchanged, and still means what it
 *                        always meant: switched on with rows behind it
 *   effective_readiness  LIVENESS — has a currently-usable credential on a
 *                        currently-active device actually completed a
 *                        server-verified assertion, recently
 *
 * A green configuration beside a red liveness is the expected and correct state
 * when nobody has used the tablets lately. The instruments are honest and they
 * are saying go and tap a tablet.
 *
 *  is DELIBERATELY unchanged: it still fails only on a relying party
 * that cannot run a ceremony. Liveness is opt-in through --require-live-proof
 * so that adding this dimension cannot silently start failing a caller that
 * asked the old question.
 */
class DoctorDeviceWebAuthnReadinessCommand extends Command
{
    protected $signature = 'webauthn:readiness
        {--json : Emit the report as JSON}
        {--strict : Exit non-zero when the relying party could not run a ceremony}
        {--require-live-proof : Retained alias for the default gate behaviour; liveness is now checked unless --report-only is passed}
        {--report-only : Print the report and ALWAYS exit 0. For a human reading state, never for a gate}';

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

    public function handle(
        FeatureFlagService $flags,
        DoctorAppLoginGate $gate,
        DoctorWebAuthnLiveProofService $liveProof,
    ): int {
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

        /*
         * THE SECOND QUESTION. Everything above is configuration; everything
         * below is liveness, and the two are kept as separate keys on purpose
         * so no reader can collapse them back into one word.
         *
         * Guarded, because a readiness command that throws while reporting on
         * readiness is worse than useless — it looks like an incident. A
         * failure here is UNVERIFIED and never a pass.
         */
        try {
            $proof = $liveProof->report();
        } catch (Throwable) {
            $proof = null;
        }

        $report['webauthn_configured'] = $failure === null && $report['webauthn_login_armed'];

        /*
         * `??` IS THE WRONG OPERATOR HERE, AND IT SHIPPED ONCE.
         *
         * The service returns `unverified_reason => null` on the SUCCESS path,
         * and `??` treats null as absent — so the first deployed build printed
         * `unverified_reason=live_proof_report_failed` on every healthy run,
         * beside four correctly measured devices. A report whose whole purpose
         * is to stop claiming things it cannot support was claiming its own
         * failure. Caught on the first production measurement.
         *
         * The distinction that matters is "did the call happen at all", which
         * is `$proof === null` and nothing else. Reading the keys only when
         * there IS a payload makes the null-vs-absent question unaskable rather
         * than answered correctly by luck, which is how the sibling keys here
         * survived — they map null to null and so hid the bug.
         */
        if ($proof === null) {
            $report['live_assertion_proof'] = DoctorWebAuthnLiveProofService::PROOF_UNVERIFIED;
            $report['proof_freshness'] = DoctorWebAuthnLiveProofService::FRESHNESS_UNVERIFIED;
            $report['proof_freshness_window_days'] = null;
            $report['proof_source'] = null;
            $report['last_qualifying_proof_utc'] = null;
            $report['last_qualifying_proof_local'] = null;
            $report['proof_population'] = 0;
            $report['scope_coverage'] = null;
            $report['effective_readiness'] = DoctorWebAuthnLiveProofService::READINESS_UNVERIFIED;
            $report['unverified_reason'] = 'live_proof_report_failed';
            $report['devices'] = [];
        } else {
            $report['live_assertion_proof'] = $proof['live_assertion_proof'];
            $report['proof_freshness'] = $proof['proof_freshness'];
            $report['proof_freshness_window_days'] = $proof['freshness_window_days'];
            $report['proof_source'] = $proof['proof_source'];
            $report['last_qualifying_proof_utc'] = $proof['last_qualifying_proof_utc'];
            $report['last_qualifying_proof_local'] = $proof['last_qualifying_proof_local'];
            $report['proof_population'] = $proof['population'];
            $report['scope_coverage'] = $proof['scope_coverage'];
            $report['effective_readiness'] = $proof['effective_readiness'];
            // Null here is the NORMAL, healthy answer: nothing was unverifiable.
            $report['unverified_reason'] = $proof['unverified_reason'];
            $report['devices'] = $proof['devices'];
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('WebAuthn doctor device readiness — DOCTOR-PWA-WEBAUTHN-1');
            $this->newLine();

            foreach ($report as $key => $value) {
                if ($key === 'devices') {
                    // Structured detail belongs in --json, not in a wall of
                    // console text.
                    continue;
                }

                $this->line(strtoupper($key).'='.match (true) {
                    is_bool($value) => $value ? 'true' : 'false',
                    is_array($value) => implode(',', $value),
                    $value === null => '-',
                    default => (string) $value,
                });
            }

            /*
             * The summary exists because the line-per-key dump above is exactly
             * what let ARMED be misread for ten days: it is easy to scan, find
             * a word that looks like an answer, and stop. This block states the
             * two questions separately and in that order.
             */
            $this->newLine();
            $this->line('  CONFIGURED           '.($report['webauthn_configured'] ? 'YES' : 'NO'));
            $this->line('  CREDENTIALS USABLE   '.(($report['credentials_usable'] ?? 0) > 0 ? 'YES' : 'NO'));
            $this->line('  LIVE ASSERTION PROOF '.$report['live_assertion_proof']);
            $this->line('  READINESS            '.$report['effective_readiness']);
            $this->newLine();
            $this->line('  Configuration is not liveness. ARMED means switched on with rows');
            $this->line('  behind it; only LIVE ASSERTION PROOF says a ceremony has worked.');

            if ($report['last_qualifying_proof_utc'] !== null) {
                $this->line('  Last qualifying assertion: '.$report['last_qualifying_proof_utc']
                    .'  /  '.$report['last_qualifying_proof_local']);
            }

            if ($report['scope_coverage'] !== null) {
                $this->line('  Coverage: '.$report['scope_coverage']);
            }
        }

        // Only an unusable relying party is a failure under --strict. "No
        // credentials yet" is a state, not a fault, and exiting non-zero on it
        // would train an operator to ignore the command.
        if ($this->option('strict') && $failure !== null) {
            return self::FAILURE;
        }

        /*
         * D7 — LIVENESS IS NOW THE DEFAULT, AND SILENCE IS NOT SUCCESS.
         *
         * Liveness used to be opt-in behind --require-live-proof, and an audit
         * of every call site found that NOTHING passed it: not one script, CI
         * workflow, evidence map, deploy step or runbook line, and none of the
         * four real production invocations on record. So the dimension this
         * command exists to report could never fail anything — `NOT_READY`
         * printed in the body while the process exited 0.
         *
         * The property that has to hold is "a caller cannot accidentally
         * believe NOT_READY is green", and an opt-in flag cannot deliver it:
         * the accident IS forgetting the flag. So the default fails closed and
         * a human who only wants to LOOK asks for that explicitly.
         *
         * --require-live-proof is kept as a no-op alias so existing runbooks
         * and muscle memory keep working and keep meaning the same thing.
         *
         * STALE, NEVER_PROVEN and UNVERIFIED all fail. Treating UNVERIFIED as
         * success would rebuild the original defect one level up.
         */
        if ($this->option('report-only')) {
            return self::SUCCESS;
        }

        if ($report['effective_readiness'] !== DoctorWebAuthnLiveProofService::READINESS_READY) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
