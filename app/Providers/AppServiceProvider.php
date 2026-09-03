<?php

namespace App\Providers;

use App\Modules\Prescription\Gateways\CloudApiWhatsAppGateway;
use App\Modules\Prescription\Gateways\DisabledWhatsAppGateway;
use App\Modules\Prescription\Gateways\FakeWhatsAppGateway;
use App\Modules\Prescription\Gateways\WhatsAppGatewayInterface;
use App\Support\Android\AndroidReleaseGovernanceScanner;
use App\Support\Android\KotlinSourceScanner;
use App\Support\DeveloperConsole\SensitiveValueMasker;
use App\Support\Devflow\CanonicalBaseRefResolver;
use App\Support\Devflow\DevflowScanner;
use App\Support\Devflow\GitChangeInspector;
use App\Support\Devflow\SharedFoundationScanner;
use Illuminate\Http\Client\Factory as HttpFactory;
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

        // FEATURE-DOCTOR-TRUSTED-ANDROID-DEVICE-LOCK-1 Phase 3.5 — same shape:
        // a read-only scanner that reads the repository, so it needs the base
        // path rather than guessing one.
        $this->app->bind(
            AndroidReleaseGovernanceScanner::class,
            fn ($app) => new AndroidReleaseGovernanceScanner($app->make(KotlinSourceScanner::class), base_path()),
        );

        // DEVFLOW-FIX-BASE-REF-1 — the canonical base authority. Bound per
        // resolution (not shared) so each command invocation pins its own base
        // and a long-lived container never serves a stale pinned SHA.
        $this->app->bind(CanonicalBaseRefResolver::class, fn () => new CanonicalBaseRefResolver(base_path()));
        $this->app->bind(GitChangeInspector::class, fn ($app) => new GitChangeInspector(
            base_path(),
            $app->make(CanonicalBaseRefResolver::class),
        ));

        $this->bindWhatsAppGateway();
    }

    /**
     * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-02) — resolve the WhatsApp
     * Business Platform gateway.
     *
     * Fail closed: the real Cloud API client is bound ONLY when the integration
     * is switched on AND its driver is explicitly cloud_api. Anything else —
     * including a deployment with no credentials at all — gets the disabled
     * gateway, which opens no network connection under any circumstance.
     */
    private function bindWhatsAppGateway(): void
    {
        $this->app->bind(WhatsAppGatewayInterface::class, function ($app) {
            $driver = (string) config('whatsapp.driver', 'disabled');

            if ($driver === 'fake') {
                return $app->make(FakeWhatsAppGateway::class);
            }

            if (! config('whatsapp.enabled', false) || $driver !== 'cloud_api') {
                return new DisabledWhatsAppGateway;
            }

            return new CloudApiWhatsAppGateway(
                $app->make(HttpFactory::class),
                $app->make(SensitiveValueMasker::class),
                (array) config('whatsapp.cloud_api', []),
                (array) config('whatsapp.allowed_hosts', []),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
