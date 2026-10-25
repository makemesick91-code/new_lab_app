<?php

declare(strict_types=1);

namespace App\Modules\RmeInvoice\Support;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * FIX-RME-PAYMENT-SUBMISSION-IDEMPOTENCY-1 (rule 205) — the identity of ONE
 * payment intent, and the canonical fingerprint of what the cashier submitted.
 *
 * THE TOKEN. Issued by the server when it renders a payment form, carried back in
 * the `payment_intent` field: `<nonce>.<signature>`. The nonce is 128 random bits
 * (unguessable, never derived from an invoice number, a patient, a timestamp or an
 * amount). The signature is an HMAC over the nonce and the invoice the form was
 * rendered for, keyed by the application key, so the server can tell a token it
 * issued for THIS invoice from one a client invented or copied from another
 * invoice. It is stateless: rendering a page writes nothing.
 *
 * THE TOKEN GRANTS NOTHING. It is an identity for de-duplication, never an
 * authority. Every payment it accompanies is authorized, branch-scoped and
 * validated exactly as before; a stolen token can at most be answered "already
 * recorded" to the account that recorded it.
 *
 * THE FINGERPRINT. A SHA-256 over the canonical form of every submitted field that
 * describes the payment. The same intent submitted again with the same fingerprint
 * is a REPLAY (answered with the original result); with a different fingerprint it
 * is a REUSE (refused). Notes and reference numbers enter the hash only — they are
 * never stored in plaintext by this mechanism.
 */
final class RmePaymentIntent
{
    public const FIELD = 'payment_intent';

    private const VERSION = 'v1';

    private const TOKEN_PATTERN = '/^([0-9a-f]{32})\.([0-9a-f]{64})$/D';

    /** A fresh, server-signed intent token for a payment form of this invoice. */
    public static function issue(int $invoiceId): string
    {
        $nonce = bin2hex(random_bytes(16));

        return $nonce.'.'.self::signature($nonce, $invoiceId);
    }

    /**
     * The intent key of a token this server issued for THIS invoice, or null when
     * the token is absent, malformed, forged or was issued for another invoice.
     */
    public static function keyFrom(mixed $token, int $invoiceId): ?string
    {
        if (! is_string($token) || preg_match(self::TOKEN_PATTERN, $token, $matches) !== 1) {
            return null;
        }

        return hash_equals(self::signature($matches[1], $invoiceId), $matches[2]) ? $matches[1] : null;
    }

    /**
     * Canonical SHA-256 of the submitted payment. Computed from the RAW request
     * data by both the payment FormRequest (early) and RmePaymentService (under
     * the lock), so the two can never disagree about what "the same submission"
     * means.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fingerprint(int $invoiceId, int $cashierId, array $data): string
    {
        $canonical = [
            'v' => self::VERSION,
            'invoice' => $invoiceId,
            'cashier' => $cashierId,
            'scope' => self::text($data['payment_scope'] ?? null),
            'confirm_control_only' => filter_var($data['confirm_control_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'plan' => self::text($data[RmePaymentDeclaredView::PLAN_FIELD] ?? null),
            'method' => self::integer($data['payment_method_id'] ?? null),
            'amount' => self::cents($data['amount'] ?? null),
            'paid_at' => self::instant($data['paid_at'] ?? null),
            'reference' => self::text($data['reference_number'] ?? null),
            'notes' => self::text($data['notes'] ?? null),
            'selected' => self::idList($data['selected_receivable_ids'] ?? []),
            'versions' => self::keyed($data['invoice_versions'] ?? [], fn ($v) => self::integer($v)),
            'balances' => self::keyed($data[RmePaymentDeclaredView::BALANCES_FIELD] ?? [], fn ($v) => self::cents($v)),
        ];

        // JSON_THROW_ON_ERROR: an encoding failure must never collapse every such
        // payload onto one fingerprint (sha256 of ''); free text is made encodable
        // losslessly by lossless(), so this can only throw on a programming error.
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Whole rupiah cents of a money value, or null when it is not a number. */
    public static function cents(mixed $value): ?int
    {
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            return (int) round(((float) $value) * 100);
        }

        return null;
    }

    private static function signature(string $nonce, int $invoiceId): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            // Fail closed: an unkeyed signature would let any client mint tokens.
            throw new RuntimeException('The application key is not configured; payment intents cannot be signed.');
        }

        return hash_hmac('sha256', 'rme-payment-intent|'.self::VERSION.'|'.$nonce.'|'.$invoiceId, $key);
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : self::lossless($trimmed);
    }

    /**
     * Security review L2 — a string that is not valid UTF-8 cannot be JSON-encoded
     * as-is. Encoding its exact bytes keeps two different invalid strings two
     * different fingerprints (substituting or dropping the bad bytes would make
     * them collide). Both forms carry a tag, so a genuine text that happens to
     * look like an encoded one can never equal it: the mapping is injective.
     */
    private static function lossless(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? 't:'.$value : 'b:'.base64_encode($value);
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }

    private static function instant(mixed $value): ?string
    {
        try {
            if ($value instanceof \DateTimeInterface) {
                return CarbonImmutable::instance($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            }

            if (is_string($value) && trim($value) !== '') {
                return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            }
        } catch (\Throwable) {
            return is_string($value) ? self::lossless(trim($value)) : null;
        }

        return null;
    }

    /** @return list<int> */
    private static function idList(mixed $values): array
    {
        $ids = [];
        foreach ((array) $values as $value) {
            $id = self::integer($value);
            if ($id !== null && $id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param  callable(mixed): mixed  $map
     * @return array<string, mixed>
     */
    private static function keyed(mixed $values, callable $map): array
    {
        $out = [];
        foreach ((array) $values as $key => $value) {
            $out['i'.self::lossless((string) $key)] = $map($value);
        }
        ksort($out, SORT_STRING);

        return $out;
    }
}
