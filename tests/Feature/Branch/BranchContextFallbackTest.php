<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use Database\Seeders\BranchSeeder;
use Illuminate\Support\Facades\Schema;

it('does not depend on a users.branch_id column that does not exist', function () {
    // The VPS pilot confirmed users has no branch_id column.
    expect(Schema::hasColumn((new User)->getTable(), 'branch_id'))->toBeFalse();

    test()->seed(BranchSeeder::class);
    $user = User::factory()->create();
    $main = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    $context = app(BranchContext::class);

    // Resolves cleanly via the MAIN fallback — never touches the missing column.
    expect($context->forUser($user))->toBe($main->id)
        ->and($context->id())->toBe($main->id);
});

it('falls back to the RME-enabled MAIN branch for the RME module', function () {
    test()->seed(BranchSeeder::class);
    $main = Branch::where('code', Branch::MAIN_CODE)->firstOrFail();

    expect(app(BranchContext::class)->rmeBranchId())->toBe($main->id);
});

it('falls back to the first active RME-enabled branch when MAIN is not RME-enabled', function () {
    $main = Branch::factory()->main()->create(['is_rme_enabled' => false]);
    $rme = Branch::factory()->create(['code' => 'TKM1', 'is_active' => true, 'is_rme_enabled' => true]);

    expect(app(BranchContext::class)->rmeBranchId())->toBe($rme->id);
});

it('resolves an inventory branch independently of RME enablement', function () {
    $main = Branch::factory()->main()->create(['is_rme_enabled' => false, 'is_inventory_enabled' => true]);

    expect(app(BranchContext::class)->inventoryBranchId())->toBe($main->id);
});

it('throws a clear exception when no RME-enabled branch exists', function () {
    Branch::factory()->create(['code' => 'INV9', 'is_active' => true, 'is_rme_enabled' => false]);

    expect(fn () => app(BranchContext::class)->requireRmeBranchId())
        ->toThrow(RuntimeException::class, 'No active RME-enabled branch');
});

it('falls back to the first active branch when MAIN is missing', function () {
    $branch = Branch::factory()->create(['code' => 'TKM1', 'is_active' => true]);

    expect(app(BranchContext::class)->id())->toBe($branch->id);
});
