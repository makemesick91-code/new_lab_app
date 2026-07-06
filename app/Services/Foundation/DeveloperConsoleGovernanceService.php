<?php

namespace App\Services\Foundation;

use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\Route;

/**
 * ENT-7 — Developer Assistance Console governance layer.
 *
 * Locks the console as read-only, permission-gated, audited, and PII-masked,
 * and verifies the ENT-5 / ENT-6 foundations it builds on remain GO. It stays
 * separate from the OBS-1/OBS-2 governance services so their contracts remain
 * intact.
 */
class DeveloperConsoleGovernanceService
{
    public function __construct(
        private readonly QueueRetryFailedJobGovernanceService $queueRetryGovernance,
        private readonly IdempotencyOutboxGovernanceService $idempotencyOutboxGovernance,
    ) {}

    /**
     * @return list<array{id: string, title: string, description: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id' => 'ENT7-DC001',
                'title' => 'Console is strictly read-only',
                'description' => 'Every developer-console route must be GET/HEAD only; the console never mutates application state, runtime drivers, or data.',
            ],
            [
                'id' => 'ENT7-DC002',
                'title' => 'Console access is permission-gated and Super-Admin only by default',
                'description' => 'The console route and sidebar entry are gated by the view_developer_console permission; only Super Admin holds it by default and no role may receive it without explicit approval.',
            ],
            [
                'id' => 'ENT7-DC003',
                'title' => 'Every console access is audited',
                'description' => 'Each console page view writes an immutable sys_audit_logs row and an application log entry carrying the OBS-1 request/correlation context.',
            ],
            [
                'id' => 'ENT7-DC004',
                'title' => 'PII and secret masking is mandatory on every excerpt',
                'description' => 'All free-text surfaces (log lines, exception excerpts, query summaries) pass the sensitive-value masker; KTP/NIK-shaped digit runs, credentials, tokens, and emails are never rendered in full.',
            ],
            [
                'id' => 'ENT7-DC005',
                'title' => 'Secrets and environment contents are never rendered',
                'description' => 'The console must not display passwords, tokens, sessions, cookies, API keys, connection credentials, or environment file contents in any panel or export.',
            ],
            [
                'id' => 'ENT7-DC006',
                'title' => 'Excerpts are bounded and metadata-first',
                'description' => 'Log tails, exception messages, and audit event rows are line- and length-bounded; audit payload columns (old/new values) are never rendered.',
            ],
            [
                'id' => 'ENT7-DC007',
                'title' => 'Console consumes existing read-only surfaces only',
                'description' => 'Panels aggregate existing readiness/audit/observability services and tables; the console introduces no runtime driver change, no migration, and no new write path beyond its own audit row.',
            ],
            [
                'id' => 'ENT7-DC008',
                'title' => 'ENT-5 and ENT-6 governance remain mandatory and surfaced',
                'description' => 'The console surfaces queue/failed-job and idempotency/outbox health without weakening them; ENT-5 queue retry and ENT-6 idempotency/outbox strict checks must stay GO.',
            ],
            [
                'id' => 'ENT7-DC009',
                'title' => 'Readiness check is read-only and privacy-safe',
                'description' => 'foundation:developer-console-check reports configuration and route posture only; its output never contains raw log lines, secrets, or patient identifiers.',
            ],
            [
                'id' => 'ENT7-DC010',
                'title' => 'New console surfaces require governance and tests first',
                'description' => 'Any future console panel or export must add masking coverage, permission/audit tests, and pass this governance check before shipping.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $checks = [];

        $enabled = (bool) config('developer_console.enabled', true);
        $readOnly = (bool) config('developer_console.read_only', false);
        $permission = (string) config('developer_console.permission', '');

        $checks[] = $readOnly
            ? $this->pass('ENT7-DC-READ-ONLY-DECLARED', 'Console is declared read-only in configuration.')
            : $this->fail('ENT7-DC-READ-ONLY-DECLARED', 'developer_console.read_only must be true.');

        $checks[] = $permission === 'view_developer_console'
            ? $this->pass('ENT7-DC-PERMISSION-CONFIG', 'Console permission is view_developer_console.')
            : $this->fail('ENT7-DC-PERMISSION-CONFIG', 'developer_console.permission must be view_developer_console.');

        $checks[] = in_array('view_developer_console', PermissionSeeder::PERMISSIONS, true)
            ? $this->pass('ENT7-DC-PERMISSION-SEEDED', 'view_developer_console is registered in the permission seeder.')
            : $this->fail('ENT7-DC-PERMISSION-SEEDED', 'view_developer_console must be registered in the permission seeder.');

        [$routeCheck, $routeRegistered, $mutatingRoutes] = $this->routePosture($enabled, $permission);
        $checks[] = $routeCheck;

        $audit = (array) config('developer_console.audit_access', []);
        $checks[] = ($audit['enabled'] ?? false) === true && ($audit['action'] ?? '') !== ''
            ? $this->pass('ENT7-DC-AUDIT-ACCESS', 'Console access auditing is enabled.')
            : $this->fail('ENT7-DC-AUDIT-ACCESS', 'developer_console.audit_access must stay enabled with a declared action.');

        $masking = (array) config('developer_console.masking', []);
        $checks[] = ($masking['enabled'] ?? false) === true
            && ($masking['mask_long_digit_runs'] ?? false) === true
            && (array) ($masking['sensitive_key_fragments'] ?? []) !== []
            ? $this->pass('ENT7-DC-MASKING-CONFIG', 'PII/secret masking is enabled with digit-run and sensitive-key coverage.')
            : $this->fail('ENT7-DC-MASKING-CONFIG', 'Masking must stay enabled with digit-run masking and sensitive key fragments.');

        $queueRetry = $this->queueRetryGovernance->collect();
        $checks[] = $this->decisionCheck('ENT7-DC-ENT5-QUEUE-RETRY', (string) ($queueRetry['decision'] ?? 'FAIL'), 'ENT-5 queue retry governance is GO.');

        $idempotencyOutbox = $this->idempotencyOutboxGovernance->collect();
        $checks[] = $this->decisionCheck('ENT7-DC-ENT6-IDEMPOTENCY-OUTBOX', (string) ($idempotencyOutbox['decision'] ?? 'FAIL'), 'ENT-6 idempotency/outbox governance is GO.');

        $errors = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => ($c['status'] ?? '') === 'passed'));
        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'sprint' => 'ENT-7',
            'decision' => $decision,
            'readiness_status' => $decision === 'GO' ? 'developer_console_ready' : strtolower($decision),
            'console_enabled' => $enabled,
            'read_only' => $readOnly,
            'permission' => $permission,
            'route_registered' => $routeRegistered,
            'mutating_routes' => $mutatingRoutes,
            'audit_access_enabled' => ($audit['enabled'] ?? false) === true,
            'masking_enabled' => ($masking['enabled'] ?? false) === true,
            'queue_retry_decision' => $queueRetry['decision'] ?? 'UNKNOWN',
            'idempotency_outbox_decision' => $idempotencyOutbox['decision'] ?? 'UNKNOWN',
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
                'foundation:developer-console-check',
                'foundation:queue-retry-failed-job-check',
                'foundation:idempotency-outbox-check',
                'architecture:foundation-governance-summary',
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: bool, 2: list<string>}
     */
    private function routePosture(bool $enabled, string $permission): array
    {
        $consoleRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'developer-console.'));

        $registered = $consoleRoutes->contains(fn ($route) => $route->getName() === 'developer-console.index');

        $mutating = $consoleRoutes
            ->filter(fn ($route) => array_diff($route->methods(), ['GET', 'HEAD']) !== [])
            ->map(fn ($route) => (string) $route->getName())
            ->values()
            ->all();

        if ($mutating !== []) {
            return [$this->fail('ENT7-DC-ROUTE-POSTURE', 'Console exposes mutating routes: '.implode(', ', $mutating).'.'), $registered, $mutating];
        }

        if (! $enabled) {
            return [$this->warn('ENT7-DC-ROUTE-POSTURE', 'Console is disabled by configuration; route is intentionally absent.'), $registered, []];
        }

        if (! $registered) {
            return [$this->fail('ENT7-DC-ROUTE-POSTURE', 'developer-console.index route is not registered while the console is enabled.'), false, []];
        }

        $indexRoute = $consoleRoutes->first(fn ($route) => $route->getName() === 'developer-console.index');
        $middleware = $indexRoute?->gatherMiddleware() ?? [];
        $gated = collect($middleware)->contains(fn ($m) => is_string($m) && str_contains($m, 'permission:'.$permission));

        return [
            $gated
                ? $this->pass('ENT7-DC-ROUTE-POSTURE', 'Console route is GET-only and permission-gated.')
                : $this->fail('ENT7-DC-ROUTE-POSTURE', 'Console route must be gated by permission:'.$permission.'.'),
            true,
            [],
        ];
    }

    private function decisionCheck(string $id, string $decision, string $goMessage): array
    {
        return match ($decision) {
            'GO' => $this->pass($id, $goMessage),
            'WATCH' => $this->warn($id, "{$id} is WATCH; strict mode should block until resolved."),
            default => $this->fail($id, "{$id} is {$decision}; ENT-7 cannot be GO."),
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
