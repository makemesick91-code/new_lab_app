<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use RuntimeException;

/**
 * FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216) — the one-time intent of a settlement
 * start form. Same construction as the payment (rule 205) and refund request
 * (rule 215) intents — a fresh 128-bit nonce per rendered form and an HMAC under
 * the application key — but with its OWN context string and bound to the REFUND
 * REQUEST, so no payment, refund-request or settlement token validates as another.
 *
 * Only the nonce is stored (as the settlement's intent key); nothing stored
 * depends on the application key, so rotating it only invalidates open forms.
 */
final class RmeRefundSettlementIntent
{
    public const FIELD = 'settlement_intent';

    private const CONTEXT = 'rme-refund-settlement';

    private const VERSION = 'v1';

    private const TOKEN_PATTERN = '/^([0-9a-f]{32})\.([0-9a-f]{64})$/D';

    public static function issue(int $refundRequestId): string
    {
        $nonce = bin2hex(random_bytes(16));

        return $nonce.'.'.self::signature($nonce, $refundRequestId);
    }

    /** The intent key (the nonce) when the token is genuine for this request; null otherwise. */
    public static function keyFrom(mixed $token, int $refundRequestId): ?string
    {
        if (! is_string($token) || preg_match(self::TOKEN_PATTERN, $token, $matches) !== 1) {
            return null;
        }

        return hash_equals(self::signature($matches[1], $refundRequestId), $matches[2]) ? $matches[1] : null;
    }

    /**
     * The fingerprint of what was declared: an exact repeat (same settler, same
     * method, same amount) is a replay; anything else under the intent is a reuse.
     */
    public static function fingerprint(int $refundRequestId, int $settlerId, string $method, int $amountCents): string
    {
        return hash('sha256', json_encode([
            'v' => self::VERSION,
            'request' => $refundRequestId,
            'settler' => $settlerId,
            'method' => $method,
            'amount_cents' => $amountCents,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function signature(string $nonce, int $refundRequestId): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('The application key is not configured; settlement intents cannot be signed.');
        }

        return hash_hmac('sha256', self::CONTEXT.'|'.self::VERSION.'|'.$nonce.'|'.$refundRequestId, $key);
    }
}
