<?php

namespace App\Modules\Satusehat\Support;

/**
 * Deterministic clinical source fingerprint. Given a structured array of source
 * facts (assembled by the candidate/readiness service), it canonicalizes the
 * structure (recursively sorted keys) and returns a stable sha256.
 *
 * Determinism rules:
 *  - The SAME clinical data always yields the SAME hash.
 *  - Key order never affects the result (recursive ksort).
 *  - Callers MUST NOT include random values or database timestamps that drift
 *    without a clinical change (e.g. updated_at). Only clinically-meaningful
 *    fields + mapping/identifier versions are hashed.
 */
final class SatusehatSourceHasher
{
    /**
     * @param  array<string, mixed>  $facts
     */
    public function hash(array $facts): string
    {
        return hash('sha256', $this->canonicalize($facts));
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function canonicalize(array $facts): string
    {
        $normalized = $this->normalize($facts);

        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function normalize($value)
    {
        if (is_array($value)) {
            // Associative → sort by key; list → normalize each element in order.
            if ($this->isAssoc($value)) {
                ksort($value);
            }

            return array_map(fn ($v) => $this->normalize($v), $value);
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $array
     */
    private function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
