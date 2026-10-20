<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — branch tariff resolution and mode
| (owner decision D21).
|--------------------------------------------------------------------------
|
| THE CONTRACT. The authoritative price of a treatment is the tariff OF THE
| BRANCH, active, in force on the clinical date. There is no cross-branch
| fallback, a zero tariff is not authority, and an inactive treatment has none.
| A branch is strict only when it is named in configuration — never because it
| has tariffs.
|
| REVISION-RME-MANUAL-PRICING-AUTHORITY-1 (owner D21 revised 2026-10-20): the
| resolution contract below is unchanged, but its result is now OPTIONAL
| REFERENCE — invoice creation and correction no longer ask it for a price. The
| default mode is MANUAL; strict is LEGACY_SUPERSEDED (exercised here through
| in-memory config only; the shipped config can never name a strict branch).
*/

use App\Modules\Tariff\Services\BranchTariffAuthorityService;
use App\Modules\Tariff\Support\TariffAuthorityConfig;
use App\Modules\Tariff\Support\TariffPricingMode;
use App\Modules\Tariff\Support\TariffResolution;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => rtaSetup());

function rtaResolve(int $branchId, ?int $treatmentId, string $date = '2026-10-17'): TariffResolution
{
    return app(BranchTariffAuthorityService::class)->resolveMany($branchId, [$treatmentId], $date)->for($treatmentId);
}

it('resolves the branch tariff in force, in integer cents', function () {
    $resolution = rtaResolve(test()->branch->id, test()->scaling->id);

    expect($resolution->isResolved())->toBeTrue()
        ->and($resolution->priceCents)->toBe(25_000_000)
        ->and($resolution->tariffId)->toBe((int) test()->scalingTariff->id)
        ->and($resolution->effectiveDate)->toBe('2026-01-01');
});

it('never falls back to another branch\'s tariff', function () {
    expect(rtaResolve(test()->otherBranch->id, test()->scaling->id)->outcome)->toBe(TariffResolution::NO_TARIFF);
});

it('reports a free-text line as having no treatment', function () {
    expect(rtaResolve(test()->branch->id, null)->outcome)->toBe(TariffResolution::NO_TREATMENT);
});

it('gives an inactive or soft-deleted treatment no authority', function () {
    test()->scaling->forceFill(['is_active' => false])->save();
    expect(rtaResolve(test()->branch->id, test()->scaling->id)->outcome)->toBe(TariffResolution::TREATMENT_INACTIVE);

    test()->tambal->delete();
    expect(rtaResolve(test()->branch->id, test()->tambal->id)->outcome)->toBe(TariffResolution::TREATMENT_INACTIVE);
});

it('re-validates a kept line against the tariff even after its treatment was deactivated', function () {
    test()->scaling->forceFill(['is_active' => false])->save();

    $resolution = app(BranchTariffAuthorityService::class)
        ->resolveMany(test()->branch->id, [test()->scaling->id], '2026-10-17', requireActiveTreatment: false)
        ->for(test()->scaling->id);

    expect($resolution->isResolved())->toBeTrue()->and($resolution->priceCents)->toBe(25_000_000);
});

it('treats a zero tariff as no authority (Rp0 policy is undecided)', function () {
    $free = Treatment::factory()->create(['is_active' => true]);
    rtaTariff(test()->branch, $free, '0.00');

    expect(rtaResolve(test()->branch->id, $free->id)->outcome)->toBe(TariffResolution::ZERO_PRICE);
});

it('ignores inactive and soft-deleted tariffs', function () {
    test()->scalingTariff->update(['is_active' => false]);
    expect(rtaResolve(test()->branch->id, test()->scaling->id)->outcome)->toBe(TariffResolution::NO_TARIFF);

    test()->tambalTariff->delete();
    expect(rtaResolve(test()->branch->id, test()->tambal->id)->outcome)->toBe(TariffResolution::NO_TARIFF);
});

it('uses the latest tariff in force on the date and never a future one', function () {
    rtaTariff(test()->branch, test()->scaling, '275000.00', '2026-06-01');
    rtaTariff(test()->branch, test()->scaling, '300000.00', '2027-01-01');

    expect(rtaResolve(test()->branch->id, test()->scaling->id, '2026-05-31')->priceCents)->toBe(25_000_000)
        ->and(rtaResolve(test()->branch->id, test()->scaling->id, '2026-06-01')->priceCents)->toBe(27_500_000)
        ->and(rtaResolve(test()->branch->id, test()->scaling->id, '2026-12-31')->priceCents)->toBe(27_500_000)
        ->and(rtaResolve(test()->branch->id, test()->scaling->id, '2025-12-31')->outcome)->toBe(TariffResolution::NO_TARIFF);
});

it('resolves every line of an invoice in a bounded number of queries', function () {
    $ids = [test()->scaling->id, test()->tambal->id, null];
    foreach (range(1, 20) as $i) {
        $ids[] = Treatment::factory()->create(['is_active' => true])->id;
    }

    DB::enableQueryLog();
    app(BranchTariffAuthorityService::class)->resolveMany(test()->branch->id, $ids, '2026-10-17');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(2);
});

// REVISED by REVISION-RME-MANUAL-PRICING-AUTHORITY-1 (owner D21 revised): this
// used to assert every branch defaults to TRANSITIONAL. Manual cashier pricing is
// now the policy, not a transition, so the default mode is MANUAL — still
// "whatever its tariff coverage" (a branch with tariffs is not thereby strict).
it('keeps every branch MANUAL by default, whatever its tariff coverage', function () {
    $authority = app(BranchTariffAuthorityService::class);

    expect($authority->modeFor(test()->branch->id))->toBe(TariffPricingMode::MANUAL)
        ->and($authority->modeFor(test()->otherBranch->id))->toBe(TariffPricingMode::MANUAL);
});

it('makes a branch strict only when it is named in configuration', function () {
    rtaStrict(['rt01']);
    $authority = app(BranchTariffAuthorityService::class);

    // REVISED by REVISION-RME-MANUAL-PRICING-AUTHORITY-1: the unnamed branch is
    // MANUAL (was TRANSITIONAL). The legacy strict path itself is still covered;
    // the shipped config can no longer name a strict branch (rule 194).
    expect($authority->modeFor(test()->branch->id))->toBe(TariffPricingMode::STRICT_ENFORCED)
        ->and($authority->modeFor(test()->otherBranch->id))->toBe(TariffPricingMode::MANUAL);
});

// REVISED by REVISION-RME-MANUAL-PRICING-AUTHORITY-1: the fallback mode is MANUAL
// (was TRANSITIONAL); the guarantee — never strict for an inactive/non-RME/unknown
// branch — is unchanged.
it('never makes an inactive or non-RME branch strict, and an unknown id is manual', function () {
    rtaStrict(['RT01']);
    test()->branch->forceFill(['is_rme_enabled' => false])->save();
    $authority = app(BranchTariffAuthorityService::class);

    expect($authority->modeFor(test()->branch->id))->toBe(TariffPricingMode::MANUAL)
        ->and($authority->modeFor(999_999))->toBe(TariffPricingMode::MANUAL);
});

it('canonicalises a deprecated branch code in the strict list (TKM1 names TLK1)', function () {
    expect(TariffAuthorityConfig::parseBranchCodes(' tkm1, LDK2 ,,ldk2 '))->toBe(['LDK2', 'TLK1']);
});

it('ships no strict branch', function () {
    $config = require base_path('config/rme_tariff_authority.php');

    expect($config['strict_branch_codes'])->toBe([]);
})->skip(fn () => getenv('RME_TARIFF_STRICT_BRANCH_CODES') !== false, 'environment override present');

it('keeps the exception switch source-controlled: no environment value can open it', function () {
    $previous = getenv('RME_TARIFF_PRICE_EXCEPTIONS_ENABLED');
    putenv('RME_TARIFF_PRICE_EXCEPTIONS_ENABLED=true');
    $_ENV['RME_TARIFF_PRICE_EXCEPTIONS_ENABLED'] = $_SERVER['RME_TARIFF_PRICE_EXCEPTIONS_ENABLED'] = 'true';

    try {
        $config = require base_path('config/rme_tariff_authority.php');
        expect($config['price_exceptions_enabled'])->toBeFalse();
    } finally {
        $previous === false ? putenv('RME_TARIFF_PRICE_EXCEPTIONS_ENABLED') : putenv('RME_TARIFF_PRICE_EXCEPTIONS_ENABLED='.$previous);
        unset($_ENV['RME_TARIFF_PRICE_EXCEPTIONS_ENABLED'], $_SERVER['RME_TARIFF_PRICE_EXCEPTIONS_ENABLED']);
    }

    $source = (string) file_get_contents(base_path('config/rme_tariff_authority.php'));
    expect($source)->toContain("'price_exceptions_enabled' => false,")
        ->and($source)->not->toContain('RME_TARIFF_PRICE_EXCEPTIONS_ENABLED');
});
