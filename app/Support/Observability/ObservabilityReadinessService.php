<?php

namespace App\Support\Observability;

use App\Http\Middleware\AttachRequestCorrelationContext;
use App\Support\Database\DatabaseReplicaReadinessService;
use App\Support\LoadBalancer\LoadBalancerReadinessService;
use App\Support\Runtime\RuntimePortabilityReadinessService;
use App\Support\Storage\ObjectStorageReadinessService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * OBS-1 — read-only request id / correlation id / observability readiness
 * audit. Never mutates runtime config, never reads full log file contents,
 * and never reports secrets — only booleans/short strings describing the
 * current safe posture.
 */
class ObservabilityReadinessService
{
    public function __construct(
        private readonly Router $router,
        private readonly ObjectStorageReadinessService $objectStorageReadiness,
        private readonly RuntimePortabilityReadinessService $runtimeReadiness,
        private readonly LoadBalancerReadinessService $lbReadiness,
        private readonly DatabaseReplicaReadinessService $replicaReadiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(bool $includeForeignReadiness = true): array
    {
        $enabled = (bool) config('observability.enabled', true);
        $requestIdEnabled = (bool) config('observability.request_id.enabled', true);
        $requestIdHeader = (string) config('observability.request_id.inbound_header', 'X-Request-ID');
        $correlationIdHeader = (string) config('observability.correlation_id.inbound_header', 'X-Correlation-ID');
        $responseHeader = (string) config('observability.request_id.response_header', 'X-Request-ID');
        $trustInboundRequestId = (bool) config('observability.request_id.trust_inbound', false);
        $trustInboundCorrelationId = (bool) config('observability.correlation_id.trust_inbound', false);
        $maxIdLength = (int) config('observability.max_id_length', 80);
        $logContextEnabled = (bool) config('observability.log_context.enabled', true);
        $piiMaskingEnabled = (bool) config('observability.mask_pii', true);
        $healthcheckEnabled = (bool) config('observability.healthcheck_enabled', true);
        $strict = (bool) config('observability.strict', false);

        $appEnv = (string) config('app.env');
        $appDebug = (bool) config('app.debug');
        $appDebugSafe = ! ($appEnv === 'production' && $appDebug);

        $logChannel = (string) config('logging.default');
        $logStack = (array) config('logging.channels.'.$logChannel.'.channels', []);
        $logStackConfigured = $logChannel !== '' && config('logging.channels.'.$logChannel) !== null;

        $middlewareDetected = $this->middlewareDetected();

        $warnings = [];

        if (! $requestIdEnabled) {
            $warnings[] = 'Request id generation is disabled (OBSERVABILITY_REQUEST_ID_ENABLED=false) — responses will not carry a request id header.';
        }

        if ($responseHeader === '') {
            $warnings[] = 'Response header name is empty — request id will not be exposed to clients/proxies for correlation.';
        }

        if (! $logContextEnabled) {
            $warnings[] = 'Log context is disabled — request id/correlation id will not be attached to log lines.';
        }

        if ($trustInboundRequestId || $trustInboundCorrelationId) {
            $warnings[] = 'Inbound correlation headers are trusted — ensure strict length/pattern validation stays enforced so clients cannot spoof arbitrary log correlation values.';
        }

        if (! $appDebugSafe) {
            $warnings[] = 'APP_DEBUG is true in a production environment — this must be off to avoid leaking stack traces.';
        }

        if (in_array($logChannel, ['single', 'daily'], true)) {
            $warnings[] = "Log channel is {$logChannel} (local disk) — acceptable for this single VPS pilot, but centralized/aggregated logging is needed before real multi-node scale-out.";
        }

        if (((bool) config('observability.log_context.include_user_id', false)) || ((bool) config('observability.log_context.include_branch_id', false))) {
            $warnings[] = 'User id or branch id logging is enabled — confirm this was explicitly reviewed for PII/audit exposure.';
        }

        if (! $middlewareDetected) {
            $warnings[] = 'Request correlation middleware was not detected in the web middleware group.';
        }

        $strictFailure = $strict && ($warnings !== [] || ! $enabled || ! $requestIdEnabled);

        $status = match (true) {
            $strictFailure => 'fail',
            $warnings !== [] => 'warning',
            $enabled && $requestIdEnabled && $middlewareDetected => 'observability_ready',
            default => 'single_vps_ready',
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
            'log_stack_channels' => array_values($logStack),
            'observability_enabled' => $enabled,
            'request_id_enabled' => $requestIdEnabled,
            'request_id_header' => $requestIdHeader,
            'correlation_id_header' => $correlationIdHeader,
            'response_header' => $responseHeader,
            'trust_inbound_request_id' => $trustInboundRequestId,
            'trust_inbound_correlation_id' => $trustInboundCorrelationId,
            'max_id_length' => $maxIdLength,
            'log_context_enabled' => $logContextEnabled,
            'pii_masking_enabled' => $piiMaskingEnabled,
            'healthcheck_enabled' => $healthcheckEnabled,
            'middleware_detected' => $middlewareDetected,
            'warnings' => $warnings,
            'recommendations' => $this->recommendations($logChannel, $trustInboundRequestId, $trustInboundCorrelationId),
        ];

        if ($includeForeignReadiness) {
            $result['object_storage_status'] = $this->objectStorageReadiness->check(false)['status'] ?? null;
            $result['runtime_stateless_status'] = $this->runtimeReadiness->check(false)['status'] ?? null;
            $result['lb_status'] = $this->lbReadiness->check()['status'] ?? null;
            $result['database_replica_status'] = $this->replicaReadiness->check()['status'] ?? null;
        }

        return $result;
    }

    /**
     * In-process (no real network call) simulation of an inbound request
     * carrying a valid and an invalid request id, verifying the response
     * still carries a safe request id header.
     *
     * @return array<string, mixed>
     */
    public function headerSmoke(): array
    {
        if (! (bool) config('observability.healthcheck_enabled', true)) {
            return ['status' => 'skipped', 'reason' => 'OBSERVABILITY_HEALTHCHECK_ENABLED is false.'];
        }

        if (! (bool) config('observability.enabled', true)) {
            return ['status' => 'skipped', 'reason' => 'Observability is disabled (OBSERVABILITY_ENABLED=false).'];
        }

        $header = (string) config('observability.request_id.inbound_header', 'X-Request-ID');
        $maxLength = (int) config('observability.max_id_length', 80);
        $invalidValue = str_repeat('a', $maxLength + 20).' invalid id with spaces';

        $request = Request::create('http://127.0.0.1/health/lb', 'GET', server: [
            'HTTP_'.strtoupper(str_replace('-', '_', $header)) => $invalidValue,
        ]);

        $middleware = new AttachRequestCorrelationContext;
        $response = $middleware->handle($request, fn () => response('ok'));

        $responseHeaderName = (string) config('observability.request_id.response_header', 'X-Request-ID');
        $issuedId = $response->headers->get($responseHeaderName);

        return [
            'status' => 'simulated',
            'note' => 'In-process simulation only; no real network call, no config mutated.',
            'invalid_inbound_rejected' => $issuedId !== null && $issuedId !== $invalidValue,
            'response_header_present' => $issuedId !== null,
        ];
    }

    private function middlewareDetected(): bool
    {
        // Resolving the HTTP kernel applies the bootstrap/app.php middleware
        // configuration to the router even outside an actual HTTP request
        // (e.g. when this check runs from an Artisan command).
        app(Kernel::class);

        $webGroup = $this->router->getMiddlewareGroups()['web'] ?? [];

        return in_array(AttachRequestCorrelationContext::class, $webGroup, true);
    }

    /**
     * @return list<string>
     */
    private function recommendations(string $logChannel, bool $trustInboundRequestId, bool $trustInboundCorrelationId): array
    {
        $recommendations = [
            'Keep request/log context minimal and PII-safe; never log full request payload, tokens, cookies, or session ids.',
            'Add centralized logging/APM in a later sprint once a vendor/privacy review is completed.',
            'Propagate request id/correlation id to queue jobs before scaling async workflows.',
            'Add request id to the Nginx access log format in a future infra sprint for cross-layer correlation.',
            'Add dashboards/alerting on top of this foundation in a future observability sprint (OBS-2/MON-1).',
        ];

        if (in_array($logChannel, ['single', 'daily'], true)) {
            $recommendations[] = 'Local file logging is acceptable for this single VPS pilot; plan centralized/shared logging before multi-node scale-out.';
        }

        if ($trustInboundRequestId || $trustInboundCorrelationId) {
            $recommendations[] = 'If inbound correlation headers are trusted, keep strict length/character validation enforced at all times.';
        }

        return $recommendations;
    }
}
