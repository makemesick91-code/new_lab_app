<?php

namespace App\Modules\Satusehat\Gateways;

use App\Modules\Satusehat\Support\SatusehatOutcome;

/**
 * Immutable, fully-sanitized result of a single FHIR gateway operation. It
 * carries ONLY safe metadata — outcome classification, HTTP status, the remote
 * resource id/type/version, a correlation id, a bounded list of sanitized
 * OperationOutcome issues, and an optional Retry-After. It NEVER carries the
 * access token, the raw response body, patient payload, or NIK.
 */
final class SatusehatApiResponse
{
    /**
     * @param  list<array{severity: string, code: string, diagnostics: string}>  $issues
     */
    private function __construct(
        public readonly string $outcome,
        public readonly string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $remoteResourceType = null,
        public readonly ?string $remoteResourceId = null,
        public readonly ?string $remoteVersionId = null,
        public readonly ?string $correlationId = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly array $issues = [],
    ) {}

    public static function success(string $resourceType, string $remoteId, ?string $versionId, int $httpStatus, string $correlationId): self
    {
        return new self(
            outcome: SatusehatOutcome::SUCCESS,
            message: 'ok',
            httpStatus: $httpStatus,
            remoteResourceType: $resourceType,
            remoteResourceId: $remoteId,
            remoteVersionId: $versionId,
            correlationId: $correlationId,
        );
    }

    /**
     * @param  list<array{severity: string, code: string, diagnostics: string}>  $issues
     */
    public static function retryable(string $message, ?int $httpStatus, string $correlationId, ?int $retryAfterSeconds = null, array $issues = []): self
    {
        return new self(SatusehatOutcome::RETRYABLE, $message, $httpStatus, null, null, null, $correlationId, $retryAfterSeconds, $issues);
    }

    /**
     * @param  list<array{severity: string, code: string, diagnostics: string}>  $issues
     */
    public static function permanent(string $message, ?int $httpStatus, string $correlationId, array $issues = []): self
    {
        return new self(SatusehatOutcome::PERMANENT, $message, $httpStatus, null, null, null, $correlationId, null, $issues);
    }

    public static function unknown(string $message, ?int $httpStatus, string $correlationId): self
    {
        return new self(SatusehatOutcome::UNKNOWN, $message, $httpStatus, null, null, null, $correlationId);
    }

    public function isSuccess(): bool
    {
        return $this->outcome === SatusehatOutcome::SUCCESS;
    }

    public function requiresReconciliation(): bool
    {
        return $this->outcome === SatusehatOutcome::UNKNOWN;
    }

    public function isRetryable(): bool
    {
        return $this->outcome === SatusehatOutcome::RETRYABLE;
    }

    public function isPermanent(): bool
    {
        return $this->outcome === SatusehatOutcome::PERMANENT;
    }
}
