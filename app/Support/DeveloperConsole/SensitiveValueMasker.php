<?php

namespace App\Support\DeveloperConsole;

/**
 * ENT-7 — masks PII/secret-shaped content in free-text excerpts before the
 * Developer Assistance Console renders them (ENT7-DC004/DC005).
 */
class SensitiveValueMasker
{
    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return (array) config('developer_console.masking', []);
    }

    public function mask(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $config = $this->config();
        $token = (string) ($config['mask_token'] ?? '[MASKED]');

        if (($config['enabled'] ?? true) === false) {
            // Masking must never be silently disabled: governance fails the
            // readiness check, and we still collapse digit runs defensively.
            return $this->maskDigitRuns($text, 13, $token);
        }

        // Bearer / Basic authorization values first, so the key=value pass
        // below cannot leave the token behind after masking only "Bearer".
        $text = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._\-+\/=]+/i', '$1 '.$token, $text);

        foreach ((array) ($config['sensitive_key_fragments'] ?? []) as $fragment) {
            $quoted = preg_quote((string) $fragment, '/');
            $text = (string) preg_replace(
                '/((?:[\w.\-]*'.$quoted.'[\w.\-]*)\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i',
                '$1'.$token,
                $text,
            );
        }

        if (($config['mask_emails'] ?? true) === true) {
            $text = (string) preg_replace(
                '/\b[A-Za-z0-9._%+\-]+@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})\b/',
                $token.'@$1',
                $text,
            );
        }

        if (($config['mask_long_digit_runs'] ?? true) === true) {
            $min = max(8, (int) ($config['long_digit_run_min'] ?? 13));
            $text = $this->maskDigitRuns($text, $min, $token);
        }

        return $text;
    }

    private function maskDigitRuns(string $text, int $min, string $token): string
    {
        return (string) preg_replace('/\d{'.$min.',}/', $token, $text);
    }
}
