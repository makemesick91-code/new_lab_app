<?php

namespace App\Providers;

use App\Support\Devflow\CanonicalBaseRefResolver;
use App\Support\Devflow\DevflowScanner;
use App\Support\Devflow\GitChangeInspector;
use App\Support\Devflow\SharedFoundationScanner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once app_path('Support/helpers.php');

        // DEVFLOW-1 read-only scanners need the repository base path.
        $this->app->bind(DevflowScanner::class, fn () => new DevflowScanner(base_path()));
        $this->app->bind(SharedFoundationScanner::class, fn () => new SharedFoundationScanner(base_path()));

        // DEVFLOW-FIX-BASE-REF-1 — the canonical base authority. Bound per
        // resolution (not shared) so each command invocation pins its own base
        // and a long-lived container never serves a stale pinned SHA.
        $this->app->bind(CanonicalBaseRefResolver::class, fn () => new CanonicalBaseRefResolver(base_path()));
        $this->app->bind(GitChangeInspector::class, fn ($app) => new GitChangeInspector(
            base_path(),
            $app->make(CanonicalBaseRefResolver::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
