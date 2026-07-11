<?php

namespace App\Providers;

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
        $this->app->bind(GitChangeInspector::class, fn () => new GitChangeInspector(base_path()));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
