<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-IMPORT-HUB-1 — shared fixtures for the legacy import hub.
|--------------------------------------------------------------------------
|
| Deliberately NOT added to tests/Pest.php: these helpers rewrite
| `legacy_import_hub` config, and a global helper that mutates a ceiling would
| be reachable from every unrelated suite.
|
| The filename is `helpers.php`, not `*Test.php`, so PHPUnit never mistakes it
| for a test class; each test file requires it explicitly.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyImport\Services\LegacyImportDailyQuotaService;
use Illuminate\Support\Facades\DB;

if (! function_exists('lihLimit')) {
    /**
     * Set the daily ceiling for one import type inside a test.
     *
     * `null` means "no ceiling declared", which is deliberately NOT the same as
     * 0 — the distinction the service draws, exercised here.
     */
    function lihLimit(string $type, ?int $limit): void
    {
        config()->set('legacy_import_hub.daily_limit.'.$type, $limit);
    }
}

if (! function_exists('lihBranch')) {
    /**
     * An active, RME-enabled branch identified by its BUSINESS CODE.
     *
     * The code matters: for both archive importers the owning branch is derived
     * from the branch-code segment of the patient's Nomor RM, so a fixture
     * branch has to be reachable by that code.
     */
    function lihBranch(string $code = 'TLK1', string $name = 'Cabang Telkomas'): Branch
    {
        $branch = Branch::withTrashed()->firstOrNew(['code' => $code]);

        $branch->forceFill([
            'name' => $branch->exists ? $branch->name : $name,
            'is_active' => true,
            'is_rme_enabled' => true,
            'deleted_at' => null,
        ])->save();

        return $branch->refresh();
    }
}

if (! function_exists('lihQuota')) {
    /**
     * The canonical quota service, resolved from the container so the bound
     * repository (and therefore the real locking implementation) is exercised.
     */
    function lihQuota(): LegacyImportDailyQuotaService
    {
        return app(LegacyImportDailyQuotaService::class);
    }
}

if (! function_exists('lihConsume')) {
    /**
     * Consume `$units` slots the way production does — one reservation per
     * accepted record, each inside its own transaction.
     *
     * Reserving N in a single call would exercise the batch path instead, which
     * is a different code path with different semantics.
     */
    function lihConsume(string $type, int $branchId, int $units): void
    {
        for ($i = 0; $i < $units; $i++) {
            DB::transaction(
                static fn () => lihQuota()->reserve($type, $branchId),
            );
        }
    }
}

if (! function_exists('lihOperator')) {
    /**
     * An operator holding an explicit permission set.
     *
     * Never Super Admin: `Gate::before` would bypass every check this suite
     * exists to exercise.
     *
     * @param  list<string>  $permissions
     */
    function lihOperator(array $permissions): User
    {
        return userWith($permissions);
    }
}
