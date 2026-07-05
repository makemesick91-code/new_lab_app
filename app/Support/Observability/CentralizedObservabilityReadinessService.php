<?php

namespace App\Support\Observability;

use App\Support\Cache\RedisSharedCacheReadinessService;
use App\Support\Database\DatabaseReplicaReadinessService;
use App\Support\LoadBalancer\LoadBalancerReadinessService;
use App\Support\Runtime\RuntimePortabilityReadinessService;
use App\Support\Storage\ObjectStorageReadinessService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OBS-2 — read-only centralized logging / error tracking readiness audit.
 *
 * Never installs or calls an external vendor, never mutates runtime config,
 * never reads full log file contents, and never reports a secret value —
 * only booleans/short strings describing the current safe, OFF-by-default
 * posture (config/observability_pipeline.php).
 */
class CentralizedObservabilityReadinessService
{
    public function __construct(
        private readonly ObservabilityReadinessService $observabilityReadiness,
        private readonly ObjectStorageReadinessService $objectStorageReadiness,
        private readonly RuntimePortabilityReadinessService $runtimeReadiness,
        private readonly LoadBalancerReadinessService $lbReadiness,
        private readonly DatabaseReplicaReadinessService $replicaReadiness,
        private readonly RedisSharedCacheReadinessService $redisReadiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(bool $includeForeignReadiness = true): array
    {
        $obs1 = $this->observabilityReadiness->check(false);

        $central = (array) config('observability_pipeline.central_logging', []);
        $error = (array) config('observability_pipeline.error_tracking', []);

        $centralEnabled = (bool) ($central['enabled'] ?? false);
        $centralDriver = (string) ($central['driver'] ?? 'none');
        $centralEndpointConfigured = trim((string) ($central['endpoint'] ?? '')) !== '';
        $centralApiKeyConfigured = trim((string) ($central['api_key'] ?? '')) !== '';
        $centralSendPii = (bool) ($central['send_pii'] ?? false);
        $centralStrict = (bool) ($central['strict'] ?? false);

        $errorEnabled = (bool) ($error['enabled'] ?? false);
        $errorDriver = (string) ($error['driver'] ?? 'none');
        $errorDsnConfigured = trim((string) ($error['dsn'] ?? '')) !== '';
        $errorSampleRate = (float) ($error['sample_rate'] ?? 0.0);
        $errorSendPii = (bool) ($error['send_pii'] ?? false);
        $errorStrict = (bool) ($error['strict'] ?? false);

        $appEnv = (string) config('app.env');
        $appDebug = (bool) config('app.debug');
        $appDebugSafe = ! ($appEnv === 'production' && $appDebug);

        $logChannel = (string) config('logging.default');
        $logStackConfigured = $logChannel !== '' && config('logging.channels.'.$logChannel) !== null;

        $requestIdEnabled = (bool) ($obs1['request_id_enabled'] ?? false);
        $logContextEnabled = (bool) ($obs1['log_context_enabled'] ?? false);

        $warnings = [];

        if ($centralEnabled && ($centralDriver === 'none' || ! $centralEndpointConfigured)) {
            $warnings[] = 'Central logging is enabled but driver/endpoint is missing — no destination is actually configured.';
        }

        if ($errorEnabled && ($errorDriver === 'none' || ! $errorDsnConfigured)) {
            $warnings[] = 'Error tracking is enabled but driver/DSN is missing — no destination is actually configured.';
        }

        if ($centralSendPii || $errorSendPii) {
            $warnings[] = 'Send-PII flag is enabled for central logging or error tracking — this must never be turned on without an explicit privacy/security review.';
        }

        if (! $appDebugSafe) {
            $warnings[] = 'APP_DEBUG is true in a production environment — this must be off before enabling error tracking externally.';
        }

        if (! $requestIdEnabled) {
            $warnings[] = 'Request id is not enabled (OBS-1) — exported log/error events would lack correlation.';
        }

        if (! $logContextEnabled) {
            $warnings[] = 'Log context is disabled (OBS-1) — request id/correlation id will not be attached to log lines.';
        }

        if ($centralEnabled && $centralEndpointConfigured && ! $centralStrict) {
            $warnings[] = 'Central logging endpoint is configured but strict review mode is off — treat as pre-production until reviewed.';
        }

        if ($errorEnabled && $errorDsnConfigured && ! $errorStrict) {
            $warnings[] = 'Error tracking DSN is configured but strict review mode is off — treat as pre-production until reviewed.';
        }

        $piiRisk = $centralSendPii || $errorSendPii;
        $failOnPiiRisk = (bool) config('observability_pipeline.fail_on_pii_risk', true);
        $persistentStrict = $centralStrict || $errorStrict;

        $hardFailure = $piiRisk && $failOnPiiRisk;
        $strictFailure = $persistentStrict && $warnings !== [];

        $status = match (true) {
            $hardFailure, $strictFailure => 'fail',
            $warnings !== [] => 'warning',
            ! $centralEnabled => 'external_logging_not_enabled',
            ! $errorEnabled => 'error_tracking_not_enabled',
            default => 'pipeline_readiness_ready',
        };

        $decision = match ($status) {
            'fail' => 'NO_GO',
            'warning' => 'GO_WITH_WARNINGS',
            default => 'GO',
        };

        $result = [
            'status' => $status,
            'decision' => $decision,
            'app_env' => $appEnv,
            'app_debug_safe' => $appDebugSafe,
            'log_channel' => $logChannel,
            'log_stack_configured' => $logStackConfigured,
            'request_id_enabled' => $requestIdEnabled,
            'log_context_enabled' => $logContextEnabled,
            'central_logging_enabled' => $centralEnabled,
            'central_logging_driver' => $centralDriver,
            'central_logging_endpoint_configured' => $centralEndpointConfigured,
            'central_logging_api_key_configured_as_boolean_only' => $centralApiKeyConfigured,
            'central_logging_send_pii' => $centralSendPii,
            'error_tracking_enabled' => $errorEnabled,
            'error_tracking_driver' => $errorDriver,
            'error_tracking_dsn_configured_as_boolean_only' => $errorDsnConfigured,
            'error_tracking_sample_rate' => $errorSampleRate,
            'error_tracking_send_pii' => $errorSendPii,
            'warnings' => $warnings,
            'recommendations' => $this->recommendations($centralEnabled, $errorEnabled),
        ];

        if ($includeForeignReadiness) {
            $result['observability_status'] = $obs1['status'] ?? null;
            $result['object_storage_status'] = $this->objectStorageReadiness->check(false)['status'] ?? null;
            $result['runtime_stateless_status'] = $this->runtimeReadiness->check(false)['status'] ?? null;
            $result['lb_status'] = $this->lbReadiness->check()['status'] ?? null;
            $result['database_replica_status'] = $this->replicaReadiness->check()['status'] ?? null;
            $result['cache_redis_status'] = $this->redisReadiness->check()['status'] ?? null;
        }

        return $result;
    }

    /**
     * Writes a single safe, bounded, non-PII synthetic log line locally
     * (never sent externally) so the log pipeline path can be smoke-tested.
     *
     * @return array<string, mixed>
     */
    public function logSmoke(): array
    {
        if (! (bool) config('observability_pipeline.log_smoke_enabled', true)) {
            return ['status' => 'skipped', 'reason' => 'OBS_PIPELINE_LOG_SMOKE_ENABLED is false.'];
        }

        $requestId = (string) Str::uuid();

        Log::info('obs2.pipeline_readiness.log_smoke', [
            'event' => 'obs2_pipeline_readiness_log_smoke',
            'request_id' => $requestId,
            'synthetic' => true,
        ]);

        return [
            'status' => 'written',
            'note' => 'Single synthetic non-PII log line written locally; nothing sent externally.',
            'event' => 'obs2_pipeline_readiness_log_smoke',
        ];
    }

    /**
     * Simulates error-report readiness without throwing an unhandled
     * exception and without any external network call.
     *
     * @return array<string, mixed>
     */
    public function errorSmoke(): array
    {
        if (! (bool) config('observability_pipeline.error_smoke_enabled', false)) {
            return ['status' => 'skipped', 'reason' => 'OBS_PIPELINE_ERROR_SMOKE_ENABLED is false (default) — synthetic error smoke is opt-in only.'];
        }

        try {
            $synthetic = new \RuntimeException('obs2_pipeline_readiness_synthetic_error');

            Log::warning('obs2.pipeline_readiness.error_smoke', [
                'event' => 'obs2_pipeline_readiness_error_smoke',
                'synthetic' => true,
                'exception_class' => $synthetic::class,
            ]);

            return [
                'status' => 'simulated',
                'note' => 'Synthetic exception object created and logged locally only; no external traffic sent.',
            ];
        } catch (\Throwable) {
            return ['status' => 'error', 'note' => 'Error smoke simulation itself failed safely without an unhandled exception.'];
        }
    }

    /**
     * @return list<string>
     */
    private function recommendations(bool $centralEnabled, bool $errorEnabled): array
    {
        $recommendations = [
            'Keep centralized logging and error tracking disabled until an endpoint, privacy review, and rollback plan are all in place.',
            'Attach request id/correlation id (OBS-1) to every log/error event before exporting it anywhere.',
            'Redact PII/secrets before any external export; never send full request payloads.',
            'Test log/error pipelines with synthetic, non-PII events only.',
            'Add centralized log retention and access-control policy before enabling live external export.',
            'Add alert thresholds in a future observability sprint (OBS-3/MON-1).',
        ];

        if (! $centralEnabled && ! $errorEnabled) {
            $recommendations[] = 'No central logging or error tracking vendor configured yet — acceptable for this single VPS pilot.';
        }

        return $recommendations;
    }
}
