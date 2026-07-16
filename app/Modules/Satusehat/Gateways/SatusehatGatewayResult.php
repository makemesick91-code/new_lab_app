<?php

namespace App\Modules\Satusehat\Gateways;

/**
 * Immutable, redacted result of a gateway operation. Never carries a raw API
 * response, access token, or patient payload — only a status, a safe message,
 * and a small whitelist of non-sensitive data (e.g. a remote resource id).
 */
final class SatusehatGatewayResult
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    private function __construct(
        public readonly string $status,   // ok | disabled | blocked
        public readonly string $message,
        public readonly array $data = [],
    ) {}

    /**
     * @param  array<string, scalar|null>  $data
     */
    public static function ok(string $message, array $data = []): self
    {
        return new self('ok', $message, $data);
    }

    public static function disabled(string $message): self
    {
        return new self('disabled', $message, []);
    }

    public static function blocked(string $message): self
    {
        return new self('blocked', $message, []);
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    public function isDisabled(): bool
    {
        return $this->status === 'disabled';
    }
}
