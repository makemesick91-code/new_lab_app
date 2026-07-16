<?php

namespace App\Console\Commands;

use App\Modules\Satusehat\Gateways\SatusehatGatewayInterface;
use App\Modules\Satusehat\Gateways\SatusehatTokenProviderInterface;
use App\Modules\Satusehat\Support\SatusehatFhirValidator;
use App\Modules\Satusehat\Support\SatusehatHostGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * SATUSEHAT-2 sandbox verification command. It is the ONLY supported way to
 * prove a real sandbox round-trip, and it is safe by construction:
 *   - Refuses production (sandbox lock).
 *   - --live requires the explicit --confirm-sandbox flag.
 *   - Uses ONLY synthetic/test identifiers passed on the CLI (never real patients).
 *   - Never prints the token, secret, NIK, or a raw FHIR payload.
 *   - Never runs automatically during deploy.
 */
class SatusehatSandboxVerifyCommand extends Command
{
    protected $signature = 'satusehat:sandbox-verify
        {--live : Perform a real sandbox call (requires --confirm-sandbox)}
        {--confirm-sandbox : Explicit confirmation for a live sandbox call}
        {--patient-ihs= : Synthetic Patient IHS id for the test Encounter}
        {--practitioner-ihs= : Synthetic Practitioner IHS id for the test Encounter}
        {--json : Emit a machine-readable sanitized summary}';

    protected $description = 'Verify SATUSEHAT sandbox readiness (dry-run) or a real synthetic round-trip (--live). Sandbox only.';

    public function handle(
        SatusehatTokenProviderInterface $tokenProvider,
        SatusehatGatewayInterface $gateway,
        SatusehatFhirValidator $validator,
    ): int {
        $environment = (string) config('satusehat.environment', 'sandbox');

        // Hard refuse production.
        if ($environment !== 'sandbox' || ! (bool) config('satusehat.sandbox_only', true)) {
            $this->error('satusehat:sandbox-verify hanya untuk lingkungan sandbox.');

            return self::FAILURE;
        }

        $correlationId = (string) Str::uuid();
        $summary = $this->readiness($environment);
        $summary['correlation_id'] = $correlationId;

        $patientIhs = (string) ($this->option('patient-ihs') ?? env('SATUSEHAT_TEST_PATIENT_IHS', ''));
        $practitionerIhs = (string) ($this->option('practitioner-ihs') ?? env('SATUSEHAT_TEST_PRACTITIONER_IHS', ''));

        $encounter = $this->syntheticEncounter($patientIhs, $practitionerIhs);
        $issues = $validator->validate($encounter);
        $summary['synthetic_encounter_valid'] = $issues === [];
        $summary['synthetic_encounter_issues'] = $issues;

        if (! $this->option('live')) {
            $summary['mode'] = 'dry-run';

            return $this->emit($summary, true);
        }

        // --- Live path ---
        if (! $this->option('confirm-sandbox')) {
            $this->error('Mode --live membutuhkan --confirm-sandbox.');

            return self::FAILURE;
        }

        $summary['mode'] = 'live';

        if (! $gateway->isEnabled()) {
            $summary['live_result'] = 'not_enabled';
            $this->error('Gateway belum aktif (send_enabled/config). Live dibatalkan.');

            return $this->emit($summary, false);
        }

        if ($issues !== []) {
            $summary['live_result'] = 'invalid_synthetic_payload';

            return $this->emit($summary, false);
        }

        try {
            SatusehatHostGuard::assertAllowedBaseUrl((string) config('satusehat.fhir_base_url'));
            // Prove token acquisition (value never printed).
            $tokenProvider->token();
            $summary['token'] = 'acquired';

            $created = $gateway->createResource('Encounter', $encounter, $correlationId);
            $summary['encounter_outcome'] = $created->outcome;
            $summary['encounter_http_status'] = $created->httpStatus;
            $summary['encounter_remote_id'] = $created->remoteResourceId;

            if ($created->isSuccess() && filled($created->remoteResourceId)) {
                $fetched = $gateway->getResource('Encounter', (string) $created->remoteResourceId, $correlationId);
                $summary['reconcile_outcome'] = $fetched->outcome;
            }

            $summary['live_result'] = $created->isSuccess() ? 'success' : 'failed';

            return $this->emit($summary, $created->isSuccess());
        } catch (Throwable) {
            // Never echo the exception message (could contain request details).
            $summary['live_result'] = 'error';
            $this->error('Verifikasi live gagal (lihat log dengan correlation id).');

            return $this->emit($summary, false);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readiness(string $environment): array
    {
        $fhirAllowed = $oauthAllowed = false;
        try {
            SatusehatHostGuard::assertAllowedBaseUrl((string) config('satusehat.fhir_base_url'));
            $fhirAllowed = true;
        } catch (Throwable) {
        }
        try {
            SatusehatHostGuard::assertAllowedBaseUrl((string) config('satusehat.oauth_base_url'));
            $oauthAllowed = true;
        } catch (Throwable) {
        }

        return [
            'environment' => $environment,
            'sandbox_only' => (bool) config('satusehat.sandbox_only', true),
            'enabled' => (bool) config('satusehat.enabled'),
            'send_enabled' => (bool) config('satusehat.send_enabled'),
            'oauth_host_allowed' => $oauthAllowed,
            'fhir_host_allowed' => $fhirAllowed,
            'client_id_present' => filled(config('satusehat.client_id')),
            'client_secret_present' => filled(config('satusehat.client_secret')),
            'organization_id_present' => filled(config('satusehat.organization_id')),
            'location_id_present' => filled(config('satusehat.location_id')),
            'production_blocked' => (bool) config('satusehat.sandbox_only', true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function syntheticEncounter(string $patientIhs, string $practitionerIhs): array
    {
        $encounter = [
            'resourceType' => 'Encounter',
            'status' => 'finished',
            'class' => [
                'system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
                'code' => 'AMB',
                'display' => 'ambulatory',
            ],
            'subject' => ['reference' => 'Patient/'.($patientIhs !== '' ? $patientIhs : 'SYNTHETIC-TEST')],
            'serviceProvider' => ['reference' => 'Organization/'.((string) config('satusehat.organization_id') ?: 'SYNTHETIC-ORG')],
            'period' => ['start' => now()->utc()->toIso8601String()],
        ];

        if ($practitionerIhs !== '') {
            $encounter['participant'] = [['individual' => ['reference' => 'Practitioner/'.$practitionerIhs]]];
        }

        if (filled(config('satusehat.location_id'))) {
            $encounter['location'] = [['location' => ['reference' => 'Location/'.(string) config('satusehat.location_id')]]];
        }

        return $encounter;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function emit(array $summary, bool $ok): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($summary as $key => $value) {
                $this->line(sprintf('%-28s %s', $key, is_array($value) ? json_encode($value) : var_export($value, true)));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
