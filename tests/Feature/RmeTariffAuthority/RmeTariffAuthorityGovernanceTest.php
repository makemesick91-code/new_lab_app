<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-RME-BRANCH-TARIFF-AUTHORITY-1 — release posture (rule 192).
|--------------------------------------------------------------------------
|
| This release ships the tariff authority, the D10 payment block and the D21
| correction gate. It activates NOTHING: the correction flag stays OFF, the
| exception permissions are granted to no role, exceptions are switched off and
| no branch is strict. These assertions pin that posture so a later edit cannot
| quietly turn a governance foundation into an activation.
*/

use App\Modules\AccessControl\Services\PermissionGroupingService;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeTariffExceptionPermission;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(fn () => seedAccessControl());

it('keeps the invoice correction capability flag OFF by default', function () {
    $definition = require base_path('config/feature_flags.php');

    expect($definition['flags'][RmeInvoiceCorrectionFeatureGuard::FLAG]['default'])->toBeFalse();
});

it('seeds the two exception permissions, classifies them, and grants them to NO role except the Super Admin wildcard', function () {
    $groups = app(PermissionGroupingService::class)->group(Permission::orderBy('name')->get());
    $rme = collect($groups)->firstWhere('key', 'rme');

    foreach (RmeTariffExceptionPermission::ALL as $permission) {
        expect(Permission::where('name', $permission)->where('guard_name', 'web')->exists())->toBeTrue($permission)
            ->and(collect($rme['permissions'])->pluck('name')->all())->toContain($permission);

        foreach (Role::where('name', '!=', 'Super Admin')->get() as $role) {
            expect($role->hasPermissionTo($permission))->toBeFalse("{$role->name} must not hold {$permission} before D21b is decided");
        }
    }
});

it('reads its configuration only from the config file, never env() in application code', function () {
    $offenders = [];
    $paths = [
        app_path('Modules/Tariff'),
        app_path('Modules/RmeInvoice/Services/RmeInvoicePricingService.php'),
        app_path('Modules/RmeInvoice/Services/RmeTariffAuthorityReadinessService.php'),
    ];

    foreach ($paths as $path) {
        $files = is_dir($path) ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS))) : [new SplFileInfo($path)];
        foreach ($files as $file) {
            if ($file->getExtension() === 'php' && preg_match('/\benv\s*\(/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('records the four owner decisions and the open sub-decisions in the decision register', function () {
    $register = (string) file_get_contents(base_path('docs/decisions/rme-billing-correction-owner-decisions.md'));

    foreach (['### D1 ', '### D7 ', '### D10 ', '### D21 ', 'D21a', 'D21b', 'D21c', 'D21d', 'D21h', 'task-supplied decision evidence'] as $needle) {
        expect($register)->toContain($needle);
    }

    expect($register)->toContain('`OWNER_POLICY_APPROVED`')
        // REVISED by rule 195: D1a was decided 2026-10-21 (the checker's approval
        // applies). The superseded PENDING text is kept, labelled, not deleted.
        ->and($register)->toContain('D1a — who APPLIES an approved correction — `OWNER_POLICY_APPROVED`')
        ->and($register)->toContain('Superseded text (2026-10-17): "PENDING_SUB_DECISION');
});

it('publishes rule 192 and keeps rule 191\'s D10 text marked superseded rather than deleted', function () {
    $rule = (string) file_get_contents(base_path('.cursor/rules/192-rme-branch-tariff-authority-financial-pricing.mdc'));
    $rule191 = (string) file_get_contents(base_path('.cursor/rules/191-rme-invoice-correction-prepayment-activation.mdc'));

    expect($rule)->toContain('STRICT_ENFORCED')
        ->and($rule)->toContain('INVOICE_CORRECTION_ACTIVE')
        ->and($rule191)->toContain('SUPERSEDED IN PART by rule 192');
});
