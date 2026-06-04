<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use Database\Seeders\BranchSeeder;

it('returns the active branch id for an authenticated user', function () {
    test()->seed(BranchSeeder::class);
    $user = User::factory()->create();
    $main = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    $this->actingAs($user);

    $context = app(BranchContext::class);

    expect($context->id())->toBe($main->id)
        ->and($context->branch())->toBeInstanceOf(Branch::class)
        ->and($context->branch()?->code)->toBe(Branch::MAIN_CODE);
});

it('falls back to the MAIN branch when no user branch assignment exists', function () {
    test()->seed(BranchSeeder::class);
    $user = User::factory()->create();
    $main = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    $context = app(BranchContext::class);

    expect($context->forUser($user))->toBe($main->id)
        ->and($context->id())->toBe($main->id);
});

it('throws a clear exception when a branch id is required but cannot be resolved', function () {
    expect(fn () => app(BranchContext::class)->requireId())
        ->toThrow(RuntimeException::class, 'No active branch could be resolved.');
});
