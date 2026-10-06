<?php

namespace App\Modules\Observability\Providers;

use App\Models\User;
use App\Modules\Observability\Console\PruneObservabilityTelemetryCommand;
use App\Modules\Observability\Repositories\ObservabilityTelemetryRepository;
use App\Modules\Observability\Repositories\ObservabilityTelemetryRepositoryInterface;
use App\Modules\Observability\Support\TelemetryCollector;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * FEATURE-DEV-CONSOLE-OBSERVABILITY-1 — telemetry wiring.
 *
 * The listeners are always registered and are cheap no-ops unless an HTTP
 * request is being collected (TelemetryCollector::isCollecting()). That makes
 * them inert in queue workers and Artisan commands, and inert while the
 * recorder itself is writing — the self-observability guard.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TelemetryCollector::class);
        $this->app->bind(ObservabilityTelemetryRepositoryInterface::class, ObservabilityTelemetryRepository::class);
    }

    public function boot(): void
    {
        $collector = $this->app->make(TelemetryCollector::class);

        Event::listen(QueryExecuted::class, function (QueryExecuted $event) use ($collector): void {
            // Bindings are deliberately never read.
            $collector->recordQuery($event->sql, (float) $event->time, $event->connectionName);
        });

        Event::listen(CacheHit::class, fn () => $collector->recordCacheHit());
        Event::listen(CacheMissed::class, fn () => $collector->recordCacheMiss());

        // After Auth::logout() the guard holds no user by terminate(); note who
        // it was so the logout request can mark that user OFFLINE.
        Event::listen(Logout::class, function (Logout $event) use ($collector): void {
            if ($event->user instanceof User) {
                $collector->noteLogout($event->user);
            }
        });

        if ($this->app->runningInConsole()) {
            $this->commands([PruneObservabilityTelemetryCommand::class]);
        }
    }
}
