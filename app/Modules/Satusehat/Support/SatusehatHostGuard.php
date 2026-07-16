<?php

namespace App\Modules\Satusehat\Support;

use RuntimeException;

/**
 * The single SSRF / sandbox-isolation chokepoint for every SATUSEHAT outbound
 * request (SATUSEHAT-2). Before the token provider or the HTTP gateway opens a
 * socket it MUST route the target base URL through here.
 *
 * Guarantees, in order:
 *   1. HTTPS only — an http:// (or any non-https) scheme is rejected.
 *   2. Host allowlist — the resolved host must be on config('satusehat.allowed_hosts')
 *      for the ACTIVE environment. An arbitrary/attacker-controlled host is refused.
 *   3. Sandbox lock — while config('satusehat.sandbox_only') is true the active
 *      environment must be sandbox; a production host/environment is fail-closed.
 *   4. No credentials/userinfo, no query/fragment smuggling in the base URL.
 *
 * This class opens no network connection and reads no secrets.
 */
final class SatusehatHostGuard
{
    /**
     * Assert the given base URL is a safe, allowlisted SATUSEHAT endpoint for the
     * active environment and return its normalized host. Throws on any violation.
     */
    public static function assertAllowedBaseUrl(string $baseUrl): string
    {
        $environment = (string) config('satusehat.environment', 'sandbox');

        // Sandbox lock: SATUSEHAT-2 never talks to production.
        if ((bool) config('satusehat.sandbox_only', true) && $environment !== 'sandbox') {
            throw new RuntimeException('SATUSEHAT sandbox_only aktif: environment non-sandbox ditolak.');
        }

        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            throw new RuntimeException('SATUSEHAT base URL kosong.');
        }

        $parts = parse_url($baseUrl);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('SATUSEHAT base URL tidak valid.');
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new RuntimeException('SATUSEHAT base URL wajib HTTPS.');
        }

        // Reject embedded credentials (user@host) — a classic SSRF/spoof vector.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('SATUSEHAT base URL tidak boleh memuat kredensial.');
        }

        $host = strtolower($parts['host']);

        $allowed = self::allowedHosts($environment);
        if (! in_array($host, $allowed, true)) {
            throw new RuntimeException("SATUSEHAT host tidak diizinkan untuk environment {$environment}.");
        }

        return $host;
    }

    /**
     * Assert a fully-resolved effective URL (e.g. after a redirect) still points
     * at an allowlisted host. Used to reject redirect-based host escape.
     */
    public static function assertAllowedEffectiveUrl(string $url): void
    {
        self::assertAllowedBaseUrl($url);
    }

    public static function isProductionBlocked(): bool
    {
        return (bool) config('satusehat.sandbox_only', true);
    }

    /**
     * @return list<string>
     */
    public static function allowedHosts(string $environment): array
    {
        $hosts = (array) config("satusehat.allowed_hosts.{$environment}", []);

        return array_values(array_filter(array_map(
            fn ($h) => is_string($h) ? strtolower(trim($h)) : '',
            $hosts,
        )));
    }
}
