<?php

declare(strict_types=1);

namespace App\Modules\Tariff\Support;

use App\Modules\Branch\Support\BranchCodeAlias;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — config-build-time parsers for
 * `config/rme_tariff_authority.php`. Pure functions: no database and no
 * environment access (the config file reads the environment, not this class).
 */
final class TariffAuthorityConfig
{
    public const KEY_STRICT_BRANCH_CODES = 'rme_tariff_authority.strict_branch_codes';

    public const KEY_EXCEPTIONS_ENABLED = 'rme_tariff_authority.price_exceptions_enabled';

    public const KEY_REASON_MIN = 'rme_tariff_authority.exception_reason_min';

    public const KEY_REASON_MAX = 'rme_tariff_authority.exception_reason_max';

    /**
     * Comma-separated branch codes → sorted, de-duplicated canonical codes.
     * A blank token is skipped; nothing is guessed or expanded (no wildcard,
     * no "all").
     *
     * @return list<string>
     */
    public static function parseBranchCodes(mixed $raw): array
    {
        if (! is_scalar($raw)) {
            return [];
        }

        $codes = [];
        foreach (explode(',', (string) $raw) as $token) {
            $canonical = BranchCodeAlias::canonicalize($token);
            if ($canonical !== null) {
                $codes[$canonical] = $canonical;
            }
        }

        $codes = array_values($codes);
        sort($codes);

        return $codes;
    }
}
