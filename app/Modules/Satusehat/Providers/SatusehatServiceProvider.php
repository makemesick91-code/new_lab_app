<?php

namespace App\Modules\Satusehat\Providers;

use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Satusehat\Gateways\DisabledSatusehatGateway;
use App\Modules\Satusehat\Gateways\HttpSatusehatGateway;
use App\Modules\Satusehat\Gateways\OAuthClientCredentialsSatusehatTokenProvider;
use App\Modules\Satusehat\Gateways\SatusehatGatewayInterface;
use App\Modules\Satusehat\Gateways\SatusehatTokenProviderInterface;
use App\Modules\Satusehat\Interfaces\SatusehatCandidateRepositoryInterface;
use App\Modules\Satusehat\Interfaces\SatusehatDataQualityIssueRepositoryInterface;
use App\Modules\Satusehat\Interfaces\SatusehatIdentifierRepositoryInterface;
use App\Modules\Satusehat\Interfaces\SatusehatMappingRepositoryInterface;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Models\SatusehatCandidate;
use App\Modules\Satusehat\Models\SatusehatCodeMapping;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use App\Modules\Satusehat\Observers\SatusehatMedicalRecordObserver;
use App\Modules\Satusehat\Observers\SatusehatVisitObserver;
use App\Modules\Satusehat\Policies\SatusehatBranchPilotProfilePolicy;
use App\Modules\Satusehat\Policies\SatusehatCandidatePolicy;
use App\Modules\Satusehat\Policies\SatusehatCodeMappingPolicy;
use App\Modules\Satusehat\Policies\SatusehatDataQualityIssuePolicy;
use App\Modules\Satusehat\Policies\SatusehatEntityIdentifierPolicy;
use App\Modules\Satusehat\Repositories\SatusehatCandidateRepository;
use App\Modules\Satusehat\Repositories\SatusehatDataQualityIssueRepository;
use App\Modules\Satusehat\Repositories\SatusehatIdentifierRepository;
use App\Modules\Satusehat\Repositories\SatusehatMappingRepository;
use App\Support\DeveloperConsole\SensitiveValueMasker;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the SATUSEHAT bounded context: repository bindings, the fail-closed
 * gateway binding (defaults to DisabledSatusehatGateway — no network), and the
 * module policies.
 */
class SatusehatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SatusehatCandidateRepositoryInterface::class, SatusehatCandidateRepository::class);
        $this->app->bind(SatusehatMappingRepositoryInterface::class, SatusehatMappingRepository::class);
        $this->app->bind(SatusehatIdentifierRepositoryInterface::class, SatusehatIdentifierRepository::class);
        // SATUSEHAT-4A — data-quality workspace.
        $this->app->bind(SatusehatDataQualityIssueRepositoryInterface::class, SatusehatDataQualityIssueRepository::class);

        // OAuth token provider (SATUSEHAT-2). Resolvable even while disabled —
        // it only opens a socket when token() is called, and only the HTTP
        // gateway (bound below only when fully enabled) ever calls it.
        $this->app->bind(SatusehatTokenProviderInterface::class, function ($app) {
            return new OAuthClientCredentialsSatusehatTokenProvider(
                $app->make(HttpFactory::class),
                (string) config('satusehat.environment', 'sandbox'),
            );
        });

        // Fail-closed gateway. Only a fully enabled + fully configured runtime
        // (SATUSEHAT-2) could ever bind the HTTP adapter; anything short of that
        // resolves to the network-silent DisabledSatusehatGateway.
        $this->app->bind(SatusehatGatewayInterface::class, function ($app) {
            $environment = (string) config('satusehat.environment', 'sandbox');

            if ($this->externalGatewayAllowed()) {
                return new HttpSatusehatGateway(
                    $environment,
                    $app->make(SatusehatTokenProviderInterface::class),
                    $app->make(HttpFactory::class),
                    $app->make(SensitiveValueMasker::class),
                );
            }

            return new DisabledSatusehatGateway($environment);
        });
    }

    public function boot(): void
    {
        Gate::policy(SatusehatCandidate::class, SatusehatCandidatePolicy::class);
        Gate::policy(SatusehatCodeMapping::class, SatusehatCodeMappingPolicy::class);
        Gate::policy(SatusehatEntityIdentifier::class, SatusehatEntityIdentifierPolicy::class);
        Gate::policy(SatusehatDataQualityIssue::class, SatusehatDataQualityIssuePolicy::class);
        Gate::policy(SatusehatBranchPilotProfile::class, SatusehatBranchPilotProfilePolicy::class);

        // Post-commit, idempotent, network-silent candidate generation. Failure
        // never rolls back a clinical/billing transaction (see the observers).
        ClinicVisit::observe(SatusehatVisitObserver::class);
        MedicalRecord::observe(SatusehatMedicalRecordObserver::class);
    }

    /**
     * The HTTP adapter is only allowed when the master switch is on AND every
     * required credential/URL is present. Missing config → fail closed
     * (disabled). SATUSEHAT-1 keeps SATUSEHAT_ENABLED=false, so this is never true.
     */
    private function externalGatewayAllowed(): bool
    {
        if (config('satusehat.enabled') !== true) {
            return false;
        }

        // SATUSEHAT-2 sandbox lock: a production environment fails closed to the
        // disabled gateway regardless of credentials.
        if ((bool) config('satusehat.sandbox_only', true) && config('satusehat.environment') !== 'sandbox') {
            return false;
        }

        foreach (['oauth_base_url', 'fhir_base_url', 'client_id', 'client_secret', 'organization_id', 'location_id'] as $key) {
            if (blank(config("satusehat.{$key}"))) {
                return false;
            }
        }

        return true;
    }
}
