<?php

declare(strict_types=1);

namespace App\Modules\Tariff\Services;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\Tariff\Interfaces\TariffRepositoryInterface;
use App\Modules\Tariff\Support\TariffAuthorityConfig;
use App\Modules\Tariff\Support\TariffPricingMode;
use App\Modules\Tariff\Support\TariffResolution;
use App\Modules\Tariff\Support\TariffResolutionSet;

/**
 * FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — the ONE resolver for "which branch
 * tariff is in force for a treatment on a clinical date" and "is this branch
 * priced strictly".
 *
 * REVISION-RME-MANUAL-PRICING-AUTHORITY-1 (owner D21, revised 2026-10-20): the
 * tariff master is OPTIONAL REFERENCE data. Neither invoice creation nor invoice
 * correction asks this class for a price any more; the cashier's typed price is
 * the authority. resolveMany() serves readiness, the template/import tooling
 * and the LEGACY_SUPERSEDED strict path only.
 *
 * What it never does:
 *   - read a branch, a price, a total or a discount from a request;
 *   - fall back to another branch's tariff (no "same as TLK1");
 *   - treat a zero tariff as authority (Rp0 policy D21g is undecided);
 *   - promote a branch to strict because it has tariffs — strict is an
 *     explicit configuration decision, readiness is reported separately.
 *
 * Read-only; no cache. A tariff edit is visible on the very next resolution,
 * so a stale cache can never authorize a price.
 */
class BranchTariffAuthorityService
{
    public function __construct(
        private readonly TariffRepositoryInterface $tariffs,
    ) {}

    /**
     * @return list<string> canonical branch codes configured strict
     */
    public function strictBranchCodes(): array
    {
        $codes = config(TariffAuthorityConfig::KEY_STRICT_BRANCH_CODES, []);

        return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
    }

    /**
     * The pricing mode of a branch.
     *
     * REVISION-RME-MANUAL-PRICING-AUTHORITY-1 (owner D21, revised 2026-10-20):
     * every branch is MANUAL — the cashier's typed price is the price. The
     * strict list is a source-controlled empty array (no environment override),
     * so STRICT_ENFORCED is LEGACY_SUPERSEDED: reachable only through a reviewed
     * source change backed by a new owner decision. Even then strict is honoured
     * only for an active, RME-enabled branch; anything else — including an
     * unknown id — is MANUAL, which never refuses more than manual pricing does.
     * With the list empty this answers without a query.
     */
    public function modeFor(int $branchId): string
    {
        $strict = $this->strictBranchCodes();
        if ($strict === []) {
            return TariffPricingMode::MANUAL;
        }

        $branch = Branch::query()->find($branchId);
        if (! $branch instanceof Branch || ! $branch->is_active || ! $branch->is_rme_enabled) {
            return TariffPricingMode::MANUAL;
        }

        $code = BranchCodeAlias::canonicalize((string) $branch->code);

        return $code !== null && in_array($code, $strict, true)
            ? TariffPricingMode::STRICT_ENFORCED
            : TariffPricingMode::MANUAL;
    }

    public function isStrict(int $branchId): bool
    {
        return $this->modeFor($branchId) === TariffPricingMode::STRICT_ENFORCED;
    }

    /**
     * Price exceptions (override / discount) in strict mode. Disabled until the
     * owner decides D21b–D21f; even when enabled, the actor still needs the
     * specific permission and a written reason.
     */
    public function exceptionsEnabled(): bool
    {
        return config(TariffAuthorityConfig::KEY_EXCEPTIONS_ENABLED, false) === true;
    }

    /**
     * Resolve the authoritative price of every treatment named, in one branch,
     * on one clinical date (Y-m-d). Two queries regardless of line count.
     *
     * $requireActiveTreatment = false is for re-validating a line that was billed
     * earlier and is kept UNCHANGED (an invoice correction): whether its price was
     * the tariff in force on the invoice date does not depend on whether the
     * treatment is still active today. New and changed lines always require an
     * active treatment.
     *
     * @param  list<int|null>  $treatmentIds  null = a free-text line
     */
    public function resolveMany(int $branchId, array $treatmentIds, string $clinicalDate, bool $requireActiveTreatment = true): TariffResolutionSet
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($id) => $id === null ? null : (int) $id, $treatmentIds),
            fn ($id) => $id !== null && $id > 0,
        )));

        $usable = $requireActiveTreatment
            ? $this->tariffs->treatmentUsability($ids)
            : array_fill_keys($ids, true);
        $inForce = $this->tariffs->inForceForTreatments(
            $branchId,
            array_values(array_keys(array_filter($usable))),
            $clinicalDate,
        );

        $resolutions = [];
        foreach ($ids as $id) {
            if (! ($usable[$id] ?? false)) {
                $resolutions[$id] = TariffResolution::unresolved(TariffResolution::TREATMENT_INACTIVE, $id);

                continue;
            }

            $tariff = $inForce[$id] ?? null;
            if ($tariff === null) {
                $resolutions[$id] = TariffResolution::unresolved(TariffResolution::NO_TARIFF, $id);

                continue;
            }

            $cents = self::toCents($tariff->price);
            if ($cents <= 0) {
                $resolutions[$id] = TariffResolution::unresolved(TariffResolution::ZERO_PRICE, $id, (int) $tariff->id);

                continue;
            }

            $resolutions[$id] = TariffResolution::resolved(
                $id,
                (int) $tariff->id,
                $cents,
                (string) $tariff->effective_date?->toDateString(),
            );
        }

        return new TariffResolutionSet($resolutions);
    }

    /**
     * decimal(18,2) tariff price → integer cents, parsed exactly (no float).
     */
    public static function toCents(mixed $value): int
    {
        $string = is_string($value) ? trim($value) : (is_int($value) ? (string) $value : null);

        if ($string !== null && preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', $string, $m) === 1) {
            $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

            return $m[1] === '-' ? -$cents : $cents;
        }

        return (int) round(((float) $value) * 100);
    }
}
