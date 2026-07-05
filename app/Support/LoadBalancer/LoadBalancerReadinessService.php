<?php

namespace App\Support\LoadBalancer;

use App\Support\Runtime\RuntimePortabilityReadinessService;
use App\Support\Storage\ObjectStorageReadinessService;
use Illuminate\Http\Request;

/**
 * LB-1 — read-only load balancer pilot readiness check.
 *
 * Never changes any trusted-proxy/HTTPS/secure-cookie/session/cache/queue
 * config. It only reports the current posture so the app's readiness to sit
 * behind a reverse proxy/load balancer can be audited before real
 * multi-node traffic is introduced.
 */
class LoadBalancerReadinessService
{
    public function __construct(
        private readonly ObjectStorageReadinessService $objectStorageReadiness,
        private readonly RuntimePortabilityReadinessService $runtimeReadiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $lbPilotEnabled = (bool) config('load_balancer.pilot_enabled', true);
        $trustedProxies = (array) config('load_balancer.trusted_proxies', []);
        $expectForwardedHeaders = (bool) config('load_balancer.expect_forwarded_headers', false);
        $expectHttps = (bool) config('load_balancer.expect_https', false);
        $expectSecureCookies = (bool) config('load_balancer.expect_secure_cookies', false);
        $healthEndpointEnabled = (bool) config('load_balancer.health_endpoint_enabled', true);
        $healthEndpointPath = (string) config('load_balancer.health_endpoint_path', '/health/lb');
        $strict = (bool) config('load_balancer.strict', false);

        $appEnv = (string) config('app.env');
        $appDebug = (bool) config('app.debug');
        $appUrlScheme = (string) (parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'http');
        $sessionDriver = (string) config('session.driver');
        $sessionSecureCookie = (bool) config('session.secure');
        $cacheStore = (string) config('cache.default');
        $queueConnection = (string) config('queue.default');

        $objectStorage = $this->objectStorageReadiness->check(false);
        $runtimeStateless = $this->runtimeReadiness->check(false);

        $warnings = [];

        if ($expectForwardedHeaders && $trustedProxies === []) {
            $warnings[] = 'Forwarded headers are expected but LB_TRUSTED_PROXIES is empty — X-Forwarded-* will be ignored until trusted proxies are configured.';
        }

        if ($expectHttps && $appUrlScheme !== 'https') {
            $warnings[] = "HTTPS is expected but APP_URL scheme is '{$appUrlScheme}' — confirm the proxy actually terminates TLS before enabling this expectation.";
        }

        if (($expectHttps || $expectSecureCookies) && ! $sessionSecureCookie) {
            $warnings[] = 'HTTPS/secure cookies are expected but SESSION_SECURE_COOKIE is not enabled.';
        }

        if (! $healthEndpointEnabled) {
            $warnings[] = 'Health endpoint is disabled — the load balancer will have nothing to probe at LB_HEALTH_ENDPOINT_PATH.';
        }

        // "Multi-node expected" is signaled by LB_STRICT, not merely by
        // expecting forwarded headers (a single VPS behind nginx already
        // expects those without running multiple instances).
        $horizontalScaleWarnings = (array) ($runtimeStateless['horizontal_scale_warnings'] ?? []);
        if ($strict && $horizontalScaleWarnings !== []) {
            $warnings = array_merge($warnings, $horizontalScaleWarnings);
        }

        $objectStorageEnabled = (bool) ($objectStorage['enabled'] ?? false);
        if ($strict && ! $objectStorageEnabled) {
            $warnings[] = 'LB strict multi-node mode expects object storage but STORAGE-1 object storage is disabled.';
        }

        $status = match (true) {
            $healthEndpointEnabled && $healthEndpointPath === '' => 'fail',
            $warnings !== [] => 'warning',
            $expectForwardedHeaders && $trustedProxies !== [] => 'lb_pilot_ready',
            default => 'single_vps_ready',
        };

        $decision = match (true) {
            $status === 'fail' => 'NO-GO',
            $status === 'warning' => $strict ? 'NO-GO' : 'GO',
            default => 'GO',
        };

        return [
            'status' => $status,
            'decision' => $decision,
            'app_env' => $appEnv,
            'app_debug_safe' => ! ($appEnv === 'production' && $appDebug),
            'lb_pilot_enabled' => $lbPilotEnabled,
            'health_endpoint_enabled' => $healthEndpointEnabled,
            'health_endpoint_path' => $healthEndpointPath,
            'trusted_proxies_configured' => $trustedProxies !== [],
            'expect_forwarded_headers' => $expectForwardedHeaders,
            'expect_https' => $expectHttps,
            'app_url_scheme' => $appUrlScheme,
            'session_driver' => $sessionDriver,
            'session_secure_cookie' => $sessionSecureCookie,
            'cache_store' => $cacheStore,
            'queue_connection' => $queueConnection,
            'object_storage_enabled' => $objectStorageEnabled,
            'runtime_stateless_status' => $runtimeStateless['status'] ?? null,
            'warnings' => $warnings,
            'recommendations' => $this->recommendations($trustedProxies, $expectHttps, $sessionSecureCookie, $sessionDriver, $cacheStore, $queueConnection),
        ];
    }

    /**
     * In-process simulation (no real network call) of forwarded-header
     * resolution, used by the readiness command's --header-smoke option.
     *
     * @return array<string, mixed>
     */
    public function headerSmoke(): array
    {
        if (! (bool) config('load_balancer.header_smoke_enabled', true)) {
            return ['status' => 'skipped', 'reason' => 'LB_HEADER_SMOKE_ENABLED is false.'];
        }

        $trustedProxies = (array) config('load_balancer.trusted_proxies', []);

        if ($trustedProxies === []) {
            return [
                'status' => 'skipped',
                'reason' => 'LB_TRUSTED_PROXIES is empty — forwarded headers are ignored by design on this single VPS pilot.',
            ];
        }

        $syntheticProxyIp = '203.0.113.10';
        $trustedForSimulation = array_values(array_unique(array_merge($trustedProxies, [$syntheticProxyIp])));

        $request = Request::create(
            'http://127.0.0.1'.(string) config('load_balancer.health_endpoint_path', '/health/lb'),
            'GET',
            server: [
                'REMOTE_ADDR' => $syntheticProxyIp,
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST' => 'daengtisiams.local',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
            ]
        );

        $request->setTrustedProxies($trustedForSimulation, Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_AWS_ELB);

        return [
            'status' => 'simulated',
            'note' => 'Synthetic proxy IP added to a copy of the trusted list for this in-process simulation only; no config was changed.',
            'resolved_scheme' => $request->getScheme(),
            'resolved_host' => $request->getHost(),
            'resolved_client_ip' => $request->getClientIp(),
        ];
    }

    /**
     * @param  list<string>  $trustedProxies
     * @return list<string>
     */
    private function recommendations(
        array $trustedProxies,
        bool $expectHttps,
        bool $sessionSecureCookie,
        string $sessionDriver,
        string $cacheStore,
        string $queueConnection
    ): array {
        $recommendations = [];

        if ($trustedProxies === []) {
            $recommendations[] = 'Use an explicit trusted proxy IP/CIDR list (LB_TRUSTED_PROXIES) when a real load balancer/reverse proxy is introduced — never trust "*" in production.';
        }

        if (! $sessionSecureCookie) {
            $recommendations[] = 'Enable secure cookies only after HTTPS is confirmed to be terminated/proxied correctly end-to-end.';
        }

        if ($sessionDriver === 'file' || $cacheStore === 'file' || $queueConnection === 'sync') {
            $recommendations[] = 'Use a shared session/cache/queue backend (not local file/sync) before running real multi-node instances behind the load balancer.';
        }

        $recommendations[] = 'Keep the /health/lb response minimal — never add PII, secrets, or internal config to it.';
        $recommendations[] = 'Plan centralized/aggregated logging with a request/correlation id before routing significant multi-node traffic.';

        return $recommendations;
    }
}
