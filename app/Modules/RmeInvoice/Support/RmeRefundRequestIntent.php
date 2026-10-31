<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use RuntimeException;

/**
 * FEATURE-RME-BILLING-REFUND-REQUEST-APPROVAL-1 (rule 215; design §6) — the
 * one-time intent of a refund request form. Same construction as the payment
 * intent (rule 205): a fresh 128-bit nonce per rendered form and an HMAC under the
 * application key — but with its OWN context string and bound to the OBLIGATION,
 * so neither a payment token nor a refund token validates as the other.
 *
 * Only the nonce is stored (as the request's intent key); nothing stored depends
 * on the application key, so rotating it only invalidates forms already rendered.
 */
final class RmeRefundRequestIntent
{
    public const FIELD = 'refund_intent';

    private const CONTEXT = 'rme-refund-request';

    private const VERSION = 'v1';

    private const TOKEN_PATTERN = '/^([0-9a-f]{32})\.([0-9a-f]{64})$/D';

    public static function issue(int $obligationId): string
    {
        $nonce = bin2hex(random_bytes(16));

        return $nonce.'.'.self::signature($nonce, $obligationId);
    }

    /** The intent key (the nonce) when the token is genuine for this obligation; null otherwise. */
    public static function keyFrom(mixed $token, int $obligationId): ?string
    {
        if (! is_string($token) || preg_match(self::TOKEN_PATTERN, $token, $matches) !== 1) {
            return null;
        }

        return hash_equals(self::signature($matches[1], $obligationId), $matches[2]) ? $matches[1] : null;
    }

    /**
     * The fingerprint of what was submitted: an exact repeat (same maker, same
     * form) is a replay; anything else under the same intent is a reuse.
     *
     * @param  array<string, mixed>  $declared
     */
    public static function fingerprint(int $obligationId, int $makerId, array $declared): string
    {
        $canonical = [
            'v' => self::VERSION,
            'obligation' => $obligationId,
            'maker' => $makerId,
            'obligation_cents' => $declared['obligation_cents'] ?? null,
            'invoice_version' => $declared['invoice_version'] ?? null,
            'reason_note' => $declared['reason_note'] ?? null,
            'patient_reference' => $declared['patient_reference'] ?? null,
            'owner_reference' => $declared['owner_reference'] ?? null,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function signature(string $nonce, int $obligationId): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('The application key is not configured; refund intents cannot be signed.');
        }

        return hash_hmac('sha256', self::CONTEXT.'|'.self::VERSION.'|'.$nonce.'|'.$obligationId, $key);
    }
}
