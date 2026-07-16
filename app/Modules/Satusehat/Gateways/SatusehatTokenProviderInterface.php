<?php

namespace App\Modules\Satusehat\Gateways;

/**
 * Supplies a valid SATUSEHAT OAuth2 access token to the HTTP gateway. The token
 * value NEVER leaves this boundary except as the Authorization header the
 * gateway attaches; it is never logged, persisted to the DB, audited, or
 * surfaced in diagnostics.
 */
interface SatusehatTokenProviderInterface
{
    /**
     * Return a currently-valid bearer token, acquiring/refreshing under a lock
     * as needed. Throws on acquisition failure — the caller classifies it.
     */
    public function token(): string;

    /**
     * Evict the cached token (e.g. after a valid 401) so the next call
     * re-acquires. Idempotent.
     */
    public function forget(): void;

    /**
     * Sanitized, value-free diagnostics for the health/readiness surface.
     *
     * @return array{token_status: string, expires_in_bucket: string, environment: string}
     */
    public function status(): array;
}
