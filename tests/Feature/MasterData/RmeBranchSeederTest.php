<?php

use App\Modules\Branch\Models\Branch;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RmeBranchSeeder;

/**
 * RME-BRANCH-SUN4 — canonical Cabang RME registry seeder.
 *
 * SUN4 (Cabang Sunu) must exist exactly once, active + RME-enabled, without
 * touching MAIN or reconfiguring existing sibling branches, and the seeder must
 * be safe to run repeatedly (deploy re-runs, fresh environments).
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
});

it('creates SUN4 Cabang Sunu as an active RME-enabled non-inventory branch', function () {
    test()->seed(RmeBranchSeeder::class);

    $sun4 = Branch::query()->where('code', 'SUN4')->get();

    expect($sun4)->toHaveCount(1)
        ->and($sun4->first()->name)->toBe('Cabang Sunu')
        ->and($sun4->first()->is_active)->toBeTrue()
        ->and($sun4->first()->is_rme_enabled)->toBeTrue()
        ->and($sun4->first()->is_inventory_enabled)->toBeFalse();
});

it('creates the full canonical RME branch registry on a fresh environment', function () {
    test()->seed(RmeBranchSeeder::class);

    foreach (RmeBranchSeeder::CANONICAL_RME_BRANCHES as $code => $name) {
        $branch = Branch::query()->where('code', $code)->first();

        expect($branch)->not->toBeNull()
            ->and($branch->name)->toBe($name)
            ->and($branch->is_active)->toBeTrue()
            ->and($branch->is_rme_enabled)->toBeTrue();
    }
});

it('is idempotent — re-running never duplicates any branch', function () {
    test()->seed(RmeBranchSeeder::class);
    test()->seed(RmeBranchSeeder::class);
    test()->seed(RmeBranchSeeder::class);

    foreach (array_keys(RmeBranchSeeder::CANONICAL_RME_BRANCHES) as $code) {
        expect(Branch::withTrashed()->where('code', $code)->count())->toBe(1);
    }
});

it('never touches the MAIN branch', function () {
    $mainBefore = Branch::query()->where('code', Branch::MAIN_CODE)->first();
    $mainBefore->update(['is_rme_enabled' => false]);

    test()->seed(RmeBranchSeeder::class);

    $mainAfter = Branch::query()->where('code', Branch::MAIN_CODE)->first();

    expect($mainAfter->name)->toBe($mainBefore->name)
        ->and($mainAfter->is_rme_enabled)->toBeFalse()
        ->and((int) $mainAfter->id)->toBe((int) $mainBefore->id);
});

it('never reconfigures an existing sibling branch (production master data wins)', function () {
    Branch::query()->create([
        'code' => 'TKM1',
        'name' => 'Cabang Telkomas Kustom Produksi',
        'is_active' => true,
        'is_rme_enabled' => false,
        'is_inventory_enabled' => true,
    ]);

    test()->seed(RmeBranchSeeder::class);

    $tkm1 = Branch::query()->where('code', 'TKM1')->first();

    expect($tkm1->name)->toBe('Cabang Telkomas Kustom Produksi')
        ->and($tkm1->is_rme_enabled)->toBeFalse()
        ->and($tkm1->is_inventory_enabled)->toBeTrue()
        ->and(Branch::withTrashed()->where('code', 'TKM1')->count())->toBe(1);
});

it('restores and re-enables a soft-deleted or disabled SUN4 instead of duplicating it', function () {
    test()->seed(RmeBranchSeeder::class);

    $sun4 = Branch::query()->where('code', 'SUN4')->first();
    $sun4->update(['is_active' => false, 'is_rme_enabled' => false]);
    $sun4->delete();

    test()->seed(RmeBranchSeeder::class);

    $restored = Branch::query()->where('code', 'SUN4')->first();

    expect($restored)->not->toBeNull()
        ->and((int) $restored->id)->toBe((int) $sun4->id)
        ->and($restored->is_active)->toBeTrue()
        ->and($restored->is_rme_enabled)->toBeTrue()
        ->and(Branch::withTrashed()->where('code', 'SUN4')->count())->toBe(1);
});
