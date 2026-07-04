<?php

namespace App\Services\Foundation;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * NSF-9 — Read-only automated smoke suite.
 *
 * Verifies the application boots, route list compiles, expected named routes
 * exist, storage/cache paths are writable, governance commands are
 * registered, and (optionally) a base URL responds with a healthy HTTP
 * status. Never mutates data. Never requires credentials.
 */
class AutomatedSmokeService
{
    /**
     * @return array<string, mixed>
     */
    public function run(?string $baseUrl = null): array
    {
        $config = config('automated_smoke', []);
        $checks = [];

        $checks[] = $this->pass('SMOKE-APP-BOOTS', 'Application container booted successfully.');

        $checks[] = $this->checkRouteListCompiles();
        $checks[] = $this->checkExpectedRouteNames((array) ($config['expected_route_names'] ?? []));
        $checks[] = $this->checkWritablePaths((array) ($config['required_writable_paths'] ?? []));
        $checks[] = $this->checkGovernanceCommands((array) ($config['required_governance_commands'] ?? []));
        $checks[] = $this->checkConfigCacheReadable();

        if ($baseUrl !== null && $baseUrl !== '') {
            $checks[] = $this->checkHttpHealth($baseUrl, $config);
        }

        $errors = count(array_filter($checks, fn (array $c) => $c['status'] === 'failed'));
        $warnings = count(array_filter($checks, fn (array $c) => $c['status'] === 'warning'));
        $passed = count(array_filter($checks, fn (array $c) => $c['status'] === 'passed'));

        $decision = $errors > 0 ? 'FAIL' : ($warnings > 0 ? 'WATCH' : 'GO');

        return [
            'generated_at' => now()->toIso8601String(),
            'base_url' => $baseUrl,
            'mode' => $baseUrl ? 'command_readiness_and_http' : 'command_readiness_only',
            'checks' => $checks,
            'summary' => [
                'decision' => $decision,
                'checks' => count($checks),
                'passed' => $passed,
                'warnings' => $warnings,
                'errors' => $errors,
            ],
            'privacy' => ['privacy_safe' => true, 'row_level_data' => false, 'pii' => false],
        ];
    }

    private function checkRouteListCompiles(): array
    {
        try {
            $count = count(Route::getRoutes()->getRoutes());

            return $count > 0
                ? $this->pass('SMOKE-ROUTE-LIST-COMPILES', "Route list compiled with {$count} routes.")
                : $this->fail('SMOKE-ROUTE-LIST-COMPILES', 'Route list compiled but contains zero routes.');
        } catch (Throwable $e) {
            return $this->fail('SMOKE-ROUTE-LIST-COMPILES', 'Route list failed to compile: '.$e->getMessage());
        }
    }

    /**
     * @param  list<string>  $expectedNames
     */
    private function checkExpectedRouteNames(array $expectedNames): array
    {
        $known = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($r) => $r->getName())
            ->filter()
            ->all();

        $missing = array_values(array_filter($expectedNames, fn (string $name) => ! in_array($name, $known, true)));

        return $missing === []
            ? $this->pass('SMOKE-EXPECTED-ROUTES-EXIST', 'All expected named routes exist: '.implode(', ', $expectedNames))
            : $this->fail('SMOKE-EXPECTED-ROUTES-EXIST', 'Missing expected named route(s): '.implode(', ', $missing));
    }

    /**
     * @param  list<string>  $paths
     */
    private function checkWritablePaths(array $paths): array
    {
        $unwritable = array_values(array_filter($paths, fn (string $p) => ! is_dir(base_path($p)) || ! is_writable(base_path($p))));

        return $unwritable === []
            ? $this->pass('SMOKE-STORAGE-WRITABLE', 'storage/bootstrap cache paths are writable and readable.')
            : $this->fail('SMOKE-STORAGE-WRITABLE', 'Unwritable/missing path(s): '.implode(', ', $unwritable));
    }

    /**
     * @param  list<string>  $commands
     */
    private function checkGovernanceCommands(array $commands): array
    {
        $registered = Artisan::all();
        $missing = array_values(array_filter($commands, fn (string $c) => ! array_key_exists($c, $registered)));

        return $missing === []
            ? $this->pass('SMOKE-GOVERNANCE-COMMANDS-EXIST', 'Required governance commands are registered: '.implode(', ', $commands))
            : $this->fail('SMOKE-GOVERNANCE-COMMANDS-EXIST', 'Missing governance command(s): '.implode(', ', $missing));
    }

    private function checkConfigCacheReadable(): array
    {
        try {
            $appName = config('app.name');

            return is_string($appName) && $appName !== ''
                ? $this->pass('SMOKE-CONFIG-READABLE', 'Config repository is readable (app.name resolved).')
                : $this->warn('SMOKE-CONFIG-READABLE', 'Config app.name resolved empty.');
        } catch (Throwable $e) {
            return $this->fail('SMOKE-CONFIG-READABLE', 'Config repository failed to read: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function checkHttpHealth(string $baseUrl, array $config): array
    {
        $healthy = (array) ($config['healthy_http_statuses'] ?? [200, 301, 302, 401, 403]);
        $failing = (array) ($config['failing_http_statuses'] ?? [500, 502, 503, 504]);
        $timeout = (int) ($config['http_timeout_seconds'] ?? 5);

        try {
            $response = Http::timeout($timeout)->withoutRedirecting()->get(rtrim($baseUrl, '/').'/login');
            $status = $response->status();

            if (in_array($status, $failing, true)) {
                return $this->fail('SMOKE-HTTP-HEALTH', "HTTP probe to {$baseUrl}/login returned failing status {$status}.");
            }

            if (in_array($status, $healthy, true)) {
                return $this->pass('SMOKE-HTTP-HEALTH', "HTTP probe to {$baseUrl}/login returned healthy status {$status}.");
            }

            return $this->warn('SMOKE-HTTP-HEALTH', "HTTP probe to {$baseUrl}/login returned unclassified status {$status}.");
        } catch (Throwable $e) {
            return $this->warn('SMOKE-HTTP-HEALTH', "HTTP probe to {$baseUrl} could not connect: ".$e->getMessage());
        }
    }

    private function pass(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'passed', 'message' => $message];
    }

    private function warn(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'warning', 'message' => $message];
    }

    private function fail(string $id, string $message): array
    {
        return ['check_id' => $id, 'status' => 'failed', 'message' => $message];
    }
}
