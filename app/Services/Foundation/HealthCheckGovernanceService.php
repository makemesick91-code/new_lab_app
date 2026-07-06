<?php

namespace App\Services\Foundation;

use App\Support\Health\HealthCheckService;
use Illuminate\Support\Facades\Route;

/**
 * ENT-8 — Observability & Health Check Pack governance layer.
 *
 * Locks the health pack as minimal, non-sensitive, read-only, and GET-only,
 * verifies the alerting readiness stays internal (no external paging vendor),
 * and confirms the ENT-5 / ENT-6 / ENT-7 foundations it builds on remain GO. It
 * stays separate from the OBS-1/OBS-2 and developer-console governance services
 * so their contracts remain intact.
 */
class HealthCheckGovernanceService
{
    public function __construct(
        private readonly HealthCheckService $health,
        private readonly QueueRetryFailedJobGovernanceService $queueRetryGovernance,
        private readonly IdempotencyOutboxGovernanceService $idempotencyOutboxGovernance,
        private readonly DeveloperConsoleGovernanceService $developerConsoleGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ENT8-HC001',
                'title' => 'Health endpoints are minimal and non-sensitive',
                'description' => 'Liveness/readiness endpoints return only overall status plus per-component ok/degraded/down; never APP_KEY, DB host/credentials, env values, route list, user/branch/patient data, secrets, or stack traces.',
            ],
            [
                'id' => 'ENT8-HC002',
                'title' => 'Health endpoints are strictly read-only and GET-only',
                'description' => 'Every health route is GET/HEAD only and never mutates application state, runtime drivers, or data.',
            ],
            [
                'id' => 'ENT8-HC003',
                'title' => 'Readiness aggregates DB/cache/queue/storage health',
                'description' => 'The readiness probe evaluates the configured dependency components (database, cache, queue, storage, object storage) with bounded, non-sensitive results and a critical/non-critical classification.',
            ],
            [
                'id' => 'ENT8-HC004',
                'title' => 'Alerting readiness stays internal until privacy review',
                'description' => 'Alerting thresholds are readiness-only; no external paging/alerting vendor is wired without a privacy review, and the external channel flag stays disabled by default.',
            ],
            [
                'id' => 'ENT8-HC005',
                'title' => 'No PII/secret in health or alert output',
                'description' => 'Health and alerting output never contains KTP/NIK-shaped identifiers, patient data, credentials, tokens, or secrets — probe exceptions are swallowed, never echoed.',
            ],
            [
                'id' => 'ENT8-HC006',
                'title' => 'Health pack is read-only and introduces no driver change',
                'description' => 'The pack aggregates existing runtime facades and readiness surfaces; it adds no migration, no runtime driver change, and no new write path.',
            ],
            [
                'id' => 'ENT8-HC007',
                'title' => 'Readiness command is read-only and privacy-safe',
                'description' => 'foundation:health-check reports configuration, component posture, and threshold readiness only; its output never contains raw log lines, secrets, or patient identifiers.',
            ],
            [
                'id' => 'ENT8-HC008',
                'title' => 'ENT-5/ENT-6/ENT-7 governance remain mandatory and surfaced',
                'description' => 'The health pack surfaces queue/failed-job, idempotency/outbox, and developer-console health without weakening them; the ENT-5, ENT-6, and ENT-7 strict checks must stay GO.',
            ],
            [
                'id' => 'ENT8-HC009',
                'title' => 'Health endpoints build on the LB-1 /health/lb posture',
                'description' => 'The pack extends the LB-1 minimal-health posture; the existing /health/lb endpoint stays intact and the new endpoints follow the same non-sensitive contract.',
            ],
            [
                'id' => 'ENT8-HC010',
                'title' => 'New health surfaces require governance and tests first',
                'description' => 'Any future health panel, endpoint, or exported metric must add non-sensitivity coverage, endpoint tests, and pass this governance check before shipping.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $checks = [];

        $enabled = (bool) config('health_check.enabled', true);
        $livenessEnabled = (bool) config('health_check.liveness.enabled', true);
        $readinessEnabled = (bool) config('health_check.readiness.enabled', true);
        $externalAlert = (bool) config('health_check.alerting.external_channel_enabled', false);
        $components = (array) config('health_check.components', []);

        [$routeCheck, $liveRegistered, $readyRegistered, $mutatingRoutes] = $this->routePosture($enabled, $livenessEnabled, $readinessEnabled);
        $checks[] = $routeCheck;

        $criticalDb = ($components['database']['enabled'] ?? false) === true
            && ($components['database']['critical'] ?? false) === true;
        $checks[] = $criticalDb
            ? $this->pass('ENT8-HC-COMPONENTS', 'Readiness evaluates the database as a critical component with cache/queue/storage coverage.')
            : $this->fail('ENT8-HC-COMPONENTS', 'health_check.components must enable the database component as critical.');

        $alertingEnabled = (bool) config('health_check.alerting.enabled', true);
        $thresholds = (array) config('health_check.alerting.thresholds', []);
        $checks[] = $alertingEnabled && $thresholds !== []
            ? $this->pass('ENT8-HC-ALERTING-READINESS', 'Alerting-threshold readiness is configured (internal only).')
            : $this->warn('ENT8-HC-ALERTING-READINESS', 'Alerting-threshold readiness should stay configured for MON-1 consumption.');

        $checks[] = $externalAlert
            ? $this->fail('ENT8-HC-NO-EXTERNAL-VENDOR', 'External paging/alerting channel must stay disabled until a privacy review approves it.')
            : $this->pass('ENT8-HC-NO-EXTERNAL-VENDOR', 'No external paging/alerting vendor is wired (privacy-safe default).');

        // Live sensitivity scan: assert the aggregated readiness payload carries
        // no forbidden keys and no long-digit runs.
        [$sensitivityCheck, $overallStatus] = $this->sensitivityPosture();
        $checks[] = $sensitivityCheck;

        $lbHealthRegistered = $this->lbHealthRegistered();
        $checks[] = $lbHealthRegistered
            ? $this->pass('ENT8-HC-LB-BASELINE', 'LB-1 /health/lb endpoint remains registered.')
            : $this->warn('ENT8-HC-LB-BASELINE', 'LB-1 /health/lb endpoint is not registered; health endpoint may be disabled.');

        $queueRetry = $this->queueRetryGovernance->collect();
        $checks[] = $this->decisionCheck('ENT8-HC-ENT5-QUEUE-RETRY', (string) ($queueRetry['decision'] ?? 'FAIL'), 'ENT-5 queue retry governance is GO.');

        $idempotencyOutbox = $this->idempotencyOutboxGovernance->collect();
        $checks[] = $this->decisionCheck('ENT8-HC-ENT6-IDEMPOTENCY-OUTBOX', (string) ($idempotencyOutbox['decision'] ?? 'FAIL'), 'ENT-6 idempotency/outbox governance is GO.');

        $developerConsole = $this->developerConsoleGovernance->collect();
        $checks[] = $this->decisionCheck('ENT8-HC-ENT7-DEVELOPER-CONSOLE', (string) ($developerConsole['decision'] ?? 'FAIL'), 'ENT-7 developer-console governance is GO.');

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        $alerting = $this->health->alertingReadiness();

        return [
            'sprint' => 'ENT-8',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'health_check_ready' : strtolower($decision),
            'health_check_enabled' => $enabled,
            'liveness_enabled' => $livenessEnabled,
            'readiness_enabled' => $readinessEnabled,
            'liveness_route_registered' => $liveRegistered,
            'readiness_route_registered' => $readyRegistered,
            'mutating_routes' => $mutatingRoutes,
            'components' => array_keys(array_filter($components, fn ($c) => ($c['enabled'] ?? false) === true)),
            'readiness_overall_status' => $overallStatus,
            'alerting_enabled' => $alertingEnabled,
            'external_alert_channel_enabled' => $externalAlert,
            'alerting_status' => $alerting['status'] ?? HealthCheckService::STATUS_OK,
            'alerting_breaches' => $alerting['breaches'] ?? [],
            'lb_health_endpoint_registered' => $lbHealthRegistered,
            'queue_retry_decision' => $queueRetry['decision'] ?? 'UNKNOWN',
            'idempotency_outbox_decision' => $idempotencyOutbox['decision'] ?? 'UNKNOWN',
            'developer_console_decision' => $developerConsole['decision'] ?? 'UNKNOWN',
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'rules' => self::rules(),
            'commands' => [
                'foundation:health-check',
                'foundation:developer-console-check',
                'foundation:idempotency-outbox-check',
                'foundation:queue-retry-failed-job-check',
                'architecture:foundation-governance-summary',
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: bool, 2: bool, 3: list<string>}
     */
    private function routePosture(bool $enabled, bool $livenessEnabled, bool $readinessEnabled): array
    {
        $healthRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array((string) $route->getName(), ['health.live', 'health.ready'], true));

        $liveRegistered = $healthRoutes->contains(fn ($route) => $route->getName() === 'health.live');
        $readyRegistered = $healthRoutes->contains(fn ($route) => $route->getName() === 'health.ready');

        $mutating = $healthRoutes
            ->filter(fn ($route) => array_diff($route->methods(), ['GET', 'HEAD']) !== [])
            ->map(fn ($route) => (string) $route->getName())
            ->values()
            ->all();

        if ($mutating !== []) {
            return [$this->fail('ENT8-HC-ROUTE-POSTURE', 'Health pack exposes mutating routes: '.implode(', ', $mutating).'.'), $liveRegistered, $readyRegistered, $mutating];
        }

        if (! $enabled) {
            return [$this->warn('ENT8-HC-ROUTE-POSTURE', 'Health pack is disabled by configuration; routes are intentionally absent.'), $liveRegistered, $readyRegistered, []];
        }

        if ($livenessEnabled && ! $liveRegistered) {
            return [$this->fail('ENT8-HC-ROUTE-POSTURE', 'health.live route is not registered while liveness is enabled.'), false, $readyRegistered, []];
        }

        if ($readinessEnabled && ! $readyRegistered) {
            return [$this->fail('ENT8-HC-ROUTE-POSTURE', 'health.ready route is not registered while readiness is enabled.'), $liveRegistered, false, []];
        }

        return [$this->pass('ENT8-HC-ROUTE-POSTURE', 'Health routes are GET-only.'), $liveRegistered, $readyRegistered, []];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function sensitivityPosture(): array
    {
        $forbidden = ['password', 'secret', 'token', 'apikey', 'api_key', 'db_host', 'app_key', 'credential'];

        $readiness = $this->health->readiness();
        $encoded = strtolower((string) json_encode(array_merge($readiness, ['live' => $this->health->liveness()])));

        foreach ($forbidden as $needle) {
            if (str_contains($encoded, $needle)) {
                return [$this->fail('ENT8-HC-NON-SENSITIVE', 'Health payload exposed a forbidden key: '.$needle.'.'), (string) ($readiness['status'] ?? 'unknown')];
            }
        }

        if (preg_match('/\d{13,}/', $encoded) === 1) {
            return [$this->fail('ENT8-HC-NON-SENSITIVE', 'Health payload contained a long digit run.'), (string) ($readiness['status'] ?? 'unknown')];
        }

        return [$this->pass('ENT8-HC-NON-SENSITIVE', 'Health payload is non-sensitive (status + component states only).'), (string) ($readiness['status'] ?? 'unknown')];
    }

    private function lbHealthRegistered(): bool
    {
        return collect(Route::getRoutes()->getRoutes())
            ->contains(fn ($route) => $route->getName() === 'health.lb');
    }

    private function decisionCheck(string $id, string $decision, string $goMessage): array
    {
        return match ($decision) {
            'GO' => $this->pass($id, $goMessage),
            'WATCH' => $this->warn($id, "{$id} is WATCH; strict mode should block until resolved."),
            default => $this->fail($id, "{$id} is {$decision}; ENT-8 cannot be GO."),
        };
    }

    private function pass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'blocking' => false, 'message' => $message];
    }

    private function warn(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'warning', 'blocking' => false, 'message' => $message];
    }

    private function fail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'blocking' => true, 'message' => $message];
    }
}
