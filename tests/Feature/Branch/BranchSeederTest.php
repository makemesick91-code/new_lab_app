<?php

use App\Modules\Branch\Interfaces\BranchRepositoryInterface;
use App\Modules\Branch\Models\Branch;
use Database\Seeders\BranchSeeder;

it('seeds the default MAIN branch', function () {
    test()->seed(BranchSeeder::class);

    $main = Branch::where('code', Branch::MAIN_CODE)->first();

    expect($main)->not->toBeNull()
        ->and($main->code)->toBe('MAIN')
        ->and($main->name)->toBe('Asia Dental Lab Pusat')
        ->and($main->is_active)->toBeTrue();
});

it('is idempotent and does not duplicate the MAIN branch', function () {
    test()->seed(BranchSeeder::class);
    test()->seed(BranchSeeder::class);

    expect(Branch::where('code', Branch::MAIN_CODE)->count())->toBe(1);
});

it('resolves the default branch through the repository', function () {
    test()->seed(BranchSeeder::class);

    $repo = app(BranchRepositoryInterface::class);

    expect($repo->defaultBranch())->not->toBeNull()
        ->and($repo->defaultBranch()->code)->toBe(Branch::MAIN_CODE);
});

it('builds a MAIN branch via the factory main state', function () {
    $branch = Branch::factory()->main()->create();

    expect($branch->code)->toBe('MAIN')
        ->and($branch->is_active)->toBeTrue();
});
