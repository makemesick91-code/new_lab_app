<?php

namespace App\Providers;

use App\Modules\Prescription\Gateways\CloudApiWhatsAppGateway;
use App\Modules\Prescription\Gateways\DisabledWhatsAppGateway;
use App\Modules\Prescription\Gateways\FakeWhatsAppGateway;
use App\Modules\Prescription\Gateways\WhatsAppGatewayInterface;
use App\Support\Android\AndroidReleaseGovernanceScanner;
use App\Support\Android\ApksignerFingerprintResolver;
use App\Support\Android\KotlinSourceScanner;
use App\Support\Android\SignerFingerprintResolver;
use App\Support\Deploy\ProductionShellCommandGuard;
use App\Support\DeveloperConsole\SensitiveValueMasker;
use App\Support\Devflow\CanonicalBaseRefResolver;
use App\Support\Devflow\DevflowScanner;
use App\Support\Devflow\GitChangeInspector;
use App\Support\Devflow\SharedFoundationScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // REVISION-DOCTOR-ANDROID-DIRECT-APK-SIGNING-DISTRIBUTION-1 — the
        // release verifier reads the signer certificate through this seam, so
        // a test can drive it without shipping a signed APK into the repo.
        $this->app->bind(SignerFingerprintResolver::class, ApksignerFingerprintResolver::class);

        $this->app->bind(ProductionShellCommandGuard::class, fn ($app) => new ProductionShellCommandGuard(base_path(), $app->make(SensitiveValueMasker::class)));

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
        $this->registerDoctorAppLoginRateLimiters();
    }

    /**
     * REVISION-DOCTOR-AUTO-DEVICE-APPROVAL-APP-ONLY-LOGIN-1 — dedicated buckets
     * for the doctor login surface.
     *
     * WHY NAMED LIMITERS RATHER THAN `throttle:10,1`.
     *
     * Laravel resolves an anonymous throttle signature from the DOMAIN and the
     * IP — not the URI. Every anonymously-throttled route therefore shares ONE
     * counter per caller, and the strictest limit on any of them governs all of
     * them. Adding a strict `throttle:10,1` to doctor login would have silently
     * tightened enrolment, status polling, challenge and proof to the same ten
     * requests a minute, which for a clinic whose tablets sit behind one NAT
     * address is an outage rather than a control.
     *
     * These two buckets are keyed independently, so the new surface is
     * rate limited hard without touching the Phase 3 device channel.
     *
     * `doctor-app-login` is the one endpoint that both accepts a password and
     * can create a row an approver has to look at, so it is what a credential
     * stuffer would grind and what an attacker would use to flood the approval
     * inbox. Ten a minute per address is generous for humans mistyping a
     * password and useless for that.
     */
    private function registerDoctorAppLoginRateLimiters(): void
    {
        RateLimiter::for('doctor-app-login', function (Request $request) {
            return Limit::perMinute(10)->by('doctor-app-login|'.$request->ip());
        });

        // Challenges are cheap and a client legitimately asks for one per
        // attempt, plus retries after a network blip.
        RateLimiter::for('doctor-app-login-challenge', function (Request $request) {
            return Limit::perMinute(30)->by('doctor-app-login-challenge|'.$request->ip());
        });
    }
}
