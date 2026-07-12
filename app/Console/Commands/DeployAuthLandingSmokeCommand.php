<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\PostAuthenticationRedirectService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * FIX-LOGIN-REDIRECT-RUNTIME-PERMISSIONS — post-deploy authenticated smoke.
 *
 * Guest smoke (/login, /health/*) never exercises the authorization + Spatie
 * permission-cache path that failed the pilot (file_put_contents on the
 * FileStore permission cache → 500 after login). This command is designed to be
 * run as the PHP-FPM runtime user during deploy so it proves, WITHOUT storing or
 * printing any credential/PII:
 *
 *   1. Illuminate cache is writable+readable by the runtime user (FileStore).
 *   2. The Spatie permission cache resets + loads (the exact failing path).
 *   3. Role-aware landing + route authorization are correct:
 *        - Admin Lab default landing is NOT the forbidden dashboard.
 *        - Admin Lab may NOT access /dashboard, but MAY access the Lab workspace.
 *        - Super Admin MAY access /dashboard.
 *
 * Missing role accounts degrade to WATCH (skipped) — never a fake GO. A real
 * authorization contradiction or a cache-write failure is NO_GO (exit 1).
 */
class DeployAuthLandingSmokeCommand extends Command
{
    protected $signature = 'deploy:auth-landing-smoke {--json} {--strict} {--fail-on-warning}';

    protected $description = 'Authenticated authorization + runtime permission-cache smoke (role-aware landing).';

    public function handle(PostAuthenticationRedirectService $redirect): int
    {
        $checks = [];

        $checks[] = $this->probeRuntimeCache();
        $checks[] = $this->probePermissionCache();

        foreach ($this->roleExpectations() as $expectation) {
            $checks[] = $this->probeRole($redirect, $expectation);
        }

        $hasFail = collect($checks)->contains(fn ($c) => $c['status'] === 'NO_GO');
        $hasWarn = collect($checks)->contains(fn ($c) => $c['status'] === 'WATCH');

        $decision = $hasFail ? 'NO_GO' : ($hasWarn ? 'WATCH' : 'GO');

        if ($this->option('json')) {
            $this->line(json_encode([
                'decision' => $decision,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($checks as $c) {
                $this->line(sprintf('[%s] %s: %s', $c['status'], $c['key'], $c['detail']));
            }
            $this->line("DEPLOY AUTH LANDING SMOKE: {$decision}");
        }

        if ($decision === 'NO_GO') {
            return self::FAILURE;
        }

        if ($decision === 'WATCH' && ($this->option('strict') && $this->option('fail-on-warning'))) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function probeRuntimeCache(): array
    {
        $key = 'deploy:auth-landing-smoke:probe:'.Str::random(12);

        try {
            Cache::put($key, 'ok', 30);
            $value = Cache::get($key);
            Cache::forget($key);

            if ($value !== 'ok') {
                return $this->check('runtime_cache', 'NO_GO', 'cache put/get returned unexpected value');
            }

            return $this->check('runtime_cache', 'GO', 'Illuminate cache put/get/forget works');
        } catch (Throwable $e) {
            return $this->check('runtime_cache', 'NO_GO', 'cache write failed: '.$this->safe($e->getMessage()));
        }
    }

    private function probePermissionCache(): array
    {
        try {
            $registrar = app(PermissionRegistrar::class);
            $registrar->forgetCachedPermissions();
            $registrar->getPermissions();

            return $this->check('permission_cache', 'GO', 'Spatie permission cache reset + load works');
        } catch (Throwable $e) {
            return $this->check('permission_cache', 'NO_GO', 'permission cache failed: '.$this->safe($e->getMessage()));
        }
    }

    /**
     * @return array<int, array{role:string, must_reach_dashboard:bool, forbidden_paths:array<int,string>, required_paths:array<int,string>}>
     */
    private function roleExpectations(): array
    {
        return [
            [
                'role' => 'Admin Lab',
                'must_reach_dashboard' => false,
                'forbidden_paths' => ['/dashboard'],
                'required_paths' => ['/lab/v2-orders'],
            ],
            [
                'role' => 'Super Admin',
                'must_reach_dashboard' => true,
                'forbidden_paths' => [],
                'required_paths' => ['/dashboard'],
            ],
        ];
    }

    private function probeRole(PostAuthenticationRedirectService $redirect, array $exp): array
    {
        $role = $exp['role'];

        try {
            $user = User::role($role)->first();
        } catch (Throwable $e) {
            return $this->check("role:{$role}", 'WATCH', 'could not query role users: '.$this->safe($e->getMessage()));
        }

        if ($user === null) {
            return $this->check("role:{$role}", 'WATCH', 'no active user with this role on this environment — skipped');
        }

        $landing = $redirect->defaultLandingPath($user);

        // Admin Lab must not default onto the forbidden dashboard.
        if (! $exp['must_reach_dashboard'] && str_starts_with($landing, '/dashboard')) {
            return $this->check("role:{$role}", 'NO_GO', "default landing is the forbidden dashboard ({$landing})");
        }

        foreach ($exp['forbidden_paths'] as $path) {
            if ($redirect->userMayAccessLocalPath($user, $path)) {
                return $this->check("role:{$role}", 'NO_GO', "may access forbidden path {$path}");
            }
        }

        foreach ($exp['required_paths'] as $path) {
            if (! $redirect->userMayAccessLocalPath($user, $path)) {
                return $this->check("role:{$role}", 'NO_GO', "cannot access required path {$path}");
            }
        }

        return $this->check("role:{$role}", 'GO', "landing={$landing}, authorization matrix correct");
    }

    private function check(string $key, string $status, string $detail): array
    {
        return ['key' => $key, 'status' => $status, 'detail' => $detail];
    }

    /**
     * Redact anything that could carry a secret / long digit run from a probe
     * error message before it reaches deploy logs.
     */
    private function safe(string $message): string
    {
        $message = preg_replace('/\d{13,}/', '[redacted]', $message) ?? $message;

        return Str::limit($message, 200);
    }
}
