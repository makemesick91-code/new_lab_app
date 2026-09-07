<?php

namespace App\Modules\DoctorDevice\Support;

use RuntimeException;

/**
 * DOCTOR-PWA-WEBAUTHN-1 — the resolved, validated relying party.
 *
 * WHY THIS IS A CLASS AND NOT A CONFIG READ AT THE CALL SITE
 *
 * Three separate things have to agree or the ceremony is either impossible or
 * insecure: the RP ID, the origin the browser reports, and the scheme the app
 * is actually served over. Reading them ad hoc in a controller means each call
 * site gets its own chance to disagree, and the disagreement surfaces as an
 * unexplained browser-side failure on a tablet in a clinic.
 *
 * So they are resolved once, checked against each other once, and a
 * configuration that could not possibly work is refused at construction rather
 * than at the moment a doctor needs to log in.
 *
 * THE CHECK THAT MATTERS MOST
 *
 * `assertUsable()` refuses a plaintext origin outside local development. That
 * is not belt-and-braces: an assertion delivered over http is an assertion an
 * on-path attacker can read and, worse, an origin the browser will refuse to
 * run the ceremony from at all. Failing here produces a diagnosable server
 * error instead of a silent client-side dead end.
 */
final class WebAuthnRelyingParty
{
    private const PERMITTED_USER_VERIFICATION = ['required', 'preferred'];

    /** @param list<string> $allowedOrigins */
    private function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly array $allowedOrigins,
        private readonly bool $allowInsecureLocalhost,
    ) {}

    public static function fromConfig(): self
    {
        $configuredId = trim((string) config('webauthn.relying_party.id', ''));
        $appUrl = trim((string) config('app.url', ''));

        $origins = self::resolveOrigins($appUrl);

        // Deriving from APP_URL is the intended posture: an RP ID typed a
        // second time is an RP ID that can drift from the origin the PWA is
        // actually served from.
        $id = $configuredId !== ''
            ? strtolower($configuredId)
            : strtolower((string) (parse_url($appUrl, PHP_URL_HOST) ?: ''));

        return new self(
            $id,
            (string) config('webauthn.relying_party.name', 'DaengtisiaMS'),
            $origins,
            (bool) config('webauthn.relying_party.allow_insecure_localhost', false),
        );
    }

    /** @return list<string> */
    private static function resolveOrigins(string $appUrl): array
    {
        $configured = trim((string) config('webauthn.relying_party.allowed_origins', ''));

        $raw = $configured !== ''
            ? explode(',', $configured)
            : [$appUrl];

        $origins = [];

        foreach ($raw as $candidate) {
            $normalized = self::normalizeOrigin($candidate);

            if ($normalized !== null && ! in_array($normalized, $origins, true)) {
                $origins[] = $normalized;
            }
        }

        return $origins;
    }

    /**
     * Reduce a URL to a bare scheme://host[:port] origin.
     *
     * Paths, queries and fragments are discarded rather than rejected, because
     * APP_URL legitimately carries a trailing slash and an origin comparison
     * that tripped over it would be a maintenance trap, not a security control.
     */
    private static function normalizeOrigin(string $candidate): ?string
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return null;
        }

        $parts = parse_url($candidate);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $origin = $scheme.'://'.$host;

        // Default ports are omitted, matching what a browser reports.
        if (isset($parts['port'])
            && ! ($scheme === 'https' && $parts['port'] === 443)
            && ! ($scheme === 'http' && $parts['port'] === 80)) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function allowedOrigins(): array
    {
        return $this->allowedOrigins;
    }

    /**
     * User verification policy, refusing anything outside the permitted set.
     *
     * `discouraged` is not permitted at all. Silently accepting it would turn a
     * "a human unlocked this tablet" guarantee into "a tablet was present",
     * which is exactly the downgrade this feature exists to prevent — and a
     * downgrade that happened through a typo would be invisible.
     */
    public function userVerification(): string
    {
        $value = strtolower(trim((string) config('webauthn.ceremony.user_verification', 'required')));

        if (! in_array($value, self::PERMITTED_USER_VERIFICATION, true)) {
            throw new RuntimeException(
                'WebAuthn user verification must be one of: '
                .implode(', ', self::PERMITTED_USER_VERIFICATION)
            );
        }

        return $value;
    }

    public function requiresUserVerification(): bool
    {
        return $this->userVerification() === 'required';
    }

    /**
     * Is this configuration capable of running a ceremony at all?
     *
     * Called before any options are issued. Returning a reason rather than a
     * boolean so the failure is diagnosable in a log without the operator
     * having to guess which of the three inputs disagreed.
     */
    public function usabilityFailure(): ?string
    {
        if ($this->id === '') {
            return 'relying_party_id_missing';
        }

        if ($this->allowedOrigins === []) {
            return 'allowed_origins_missing';
        }

        foreach ($this->allowedOrigins as $origin) {
            $host = (string) (parse_url($origin, PHP_URL_HOST) ?: '');
            $scheme = (string) (parse_url($origin, PHP_URL_SCHEME) ?: '');

            if ($scheme !== 'https' && ! $this->isPermittedInsecureHost($host)) {
                return 'insecure_origin';
            }

            // The browser enforces this too, but it enforces it by silently
            // refusing the ceremony. Checking it here turns a dead end into a
            // message.
            if (! $this->originMatchesRelyingParty($host)) {
                return 'origin_outside_relying_party';
            }
        }

        return null;
    }

    public function assertUsable(): void
    {
        $failure = $this->usabilityFailure();

        if ($failure !== null) {
            throw new RuntimeException('WebAuthn relying party is not usable: '.$failure);
        }
    }

    /**
     * localhost over http is the one plaintext origin the WebAuthn spec treats
     * as a secure context, which makes it the only way to exercise a real
     * ceremony in development. It is refused in a production-like environment
     * regardless of the flag, so leaving the flag on cannot become a production
     * bypass.
     */
    private function isPermittedInsecureHost(string $host): bool
    {
        if (! $this->allowInsecureLocalhost) {
            return false;
        }

        if (in_array(app()->environment(), ['production', 'pilot'], true)) {
            return false;
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    /** An origin host must be the RP ID, or a subdomain of it. */
    private function originMatchesRelyingParty(string $host): bool
    {
        if ($host === $this->id) {
            return true;
        }

        return str_ends_with($host, '.'.$this->id);
    }

    public function isAllowedOrigin(string $origin): bool
    {
        $normalized = self::normalizeOrigin($origin);

        return $normalized !== null && in_array($normalized, $this->allowedOrigins, true);
    }
}
