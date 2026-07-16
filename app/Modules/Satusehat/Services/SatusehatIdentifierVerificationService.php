<?php

namespace App\Modules\Satusehat\Services;

use App\Models\User;
use App\Modules\Satusehat\Gateways\SatusehatGatewayInterface;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Models\SatusehatEntityIdentifier;
use App\Modules\Satusehat\Support\SatusehatHostGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Verifies an already-registered IHS identifier against the SATUSEHAT sandbox by
 * GETting the remote resource by its id (so the NIK never appears in a URL or
 * log). Sandbox-only, rate-limited, audited, and value-free in its result. It
 * never auto-creates a Patient/Practitioner/Organization/Location and never
 * mixes sandbox/production identifiers.
 */
class SatusehatIdentifierVerificationService
{
    public function __construct(
        private readonly SatusehatGatewayInterface $gateway,
        private readonly SatusehatAuditLogger $audit,
    ) {}

    /**
     * @return array{status: string, http_status: ?int, verified: bool}
     */
    public function verify(SatusehatEntityIdentifier $identifier, User $actor): array
    {
        // Sandbox lock — never verify against production in SATUSEHAT-2.
        if ((bool) config('satusehat.sandbox_only', true) && $identifier->environment !== 'sandbox') {
            throw ValidationException::withMessages(['identifier' => 'Verifikasi hanya untuk lingkungan sandbox.']);
        }

        if (! $this->gateway->isEnabled()) {
            throw ValidationException::withMessages(['identifier' => 'Integrasi SATUSEHAT belum aktif (send_enabled=false).']);
        }

        SatusehatHostGuard::assertAllowedBaseUrl((string) config('satusehat.fhir_base_url'));

        $limiterKey = 'satusehat:verify:'.$identifier->environment.':'.$identifier->id;
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            throw ValidationException::withMessages(['identifier' => 'Terlalu banyak percobaan verifikasi — coba lagi nanti.']);
        }
        RateLimiter::hit($limiterKey, 60);

        if (blank($identifier->remote_identifier)) {
            throw ValidationException::withMessages(['identifier' => 'Identifier belum memiliki IHS id untuk diverifikasi.']);
        }

        $correlationId = (string) Str::uuid();
        $response = $this->gateway->getResource($identifier->entity_type, (string) $identifier->remote_identifier, $correlationId);

        return DB::transaction(function () use ($identifier, $response, $correlationId, $actor) {
            /** @var SatusehatEntityIdentifier $locked */
            $locked = SatusehatEntityIdentifier::query()->lockForUpdate()->findOrFail($identifier->id);

            if ($response->isSuccess()) {
                $metadata = (array) ($locked->source_metadata ?? []);
                $metadata['verified_via'] = 'get';
                $metadata['remote_resource_type'] = $response->remoteResourceType;
                $metadata['remote_version_id'] = $response->remoteVersionId;

                $locked->update([
                    'verified_at' => now(),
                    'verified_by' => $actor->id,
                    'source_metadata' => $metadata,
                ]);

                $this->audit->log('entity_identifier', $locked->id, SatusehatAuditLog::EVENT_IDENTIFIER_VERIFIED,
                    'IHS identifier terverifikasi di sandbox.', [
                        'entity_type' => $locked->entity_type,
                        'http_status' => $response->httpStatus,
                        'correlation_id' => $correlationId,
                    ], null, $actor);
            } else {
                $this->audit->log('entity_identifier', $locked->id, SatusehatAuditLog::EVENT_IDENTIFIER_VERIFIED,
                    'Verifikasi IHS identifier gagal/tidak pasti.', [
                        'entity_type' => $locked->entity_type,
                        'http_status' => $response->httpStatus,
                        'outcome' => $response->outcome,
                        'correlation_id' => $correlationId,
                    ], null, $actor);
            }

            return [
                'status' => $response->outcome,
                'http_status' => $response->httpStatus,
                'verified' => $response->isSuccess(),
            ];
        });
    }
}
