<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| REVISION-RME-MANUAL-PRICING-AUTHORITY-1 — governance of the D21 revision.
|--------------------------------------------------------------------------
|
| The revision is recorded where the owner's decisions live, the superseded
| tariff mandate is MARKED (never deleted) in every rule that carried it, and the
| activation posture is unchanged: correction OFF, its operational permissions
| granted to no role, strict pricing and tariff exceptions unreachable, the
| oversight roles read-only.
*/

use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPermission;
use App\Modules\RmeInvoice\Support\RmeTariffExceptionPermission;
use Spatie\Permission\Models\Role;

beforeEach(fn () => seedAccessControl());

it('records the revised D21 with provenance, a chronology and the previous D21 kept as SUPERSEDED', function () {
    $register = (string) file_get_contents(base_path('docs/decisions/rme-billing-correction-owner-decisions.md'));

    expect($register)->toContain('D21 (CURRENT) — Manual cashier pricing')
        ->and($register)->toContain('APPROVED_REVISED')
        ->and($register)->toContain('REVISION-RME-MANUAL-PRICING-AUTHORITY-1')
        ->and($register)->toContain('2026-10-20')
        ->and($register)->toContain('## Decision chronology')
        ->and($register)->toContain('D21 (2026-10-17) — Price authority — **SUPERSEDED 2026-10-20**')
        // The earlier policy text survives as the historical record.
        ->and($register)->toContain('Authoritative prices come from the approved treatment tariff master **per branch**.');
});

it('reconciles every D21 sub-decision without approving anything the owner did not decide', function () {
    $register = (string) file_get_contents(base_path('docs/decisions/rme-billing-correction-owner-decisions.md'));
    $matrix = substr($register, (int) strpos($register, '#### D21 sub-decision reconciliation'));
    $matrix = substr($matrix, 0, (int) strpos($matrix, '### D21 (2026-10-17)'));

    foreach (['D21a', 'D21b', 'D21d', 'D21e', 'D21f', 'D21h', 'D21i'] as $code) {
        expect($matrix)->toMatch('/\| '.$code.' \|[^\n]*`NOT_APPLICABLE`/');
    }
    foreach (['D21c', 'D21g'] as $code) {
        expect($matrix)->toMatch('/\| '.$code.' \|[^\n]*`PENDING_SUB_DECISION`/');
    }

    // REVISED by rule 195: D1a was decided 2026-10-21 (the checker's approval
    // applies, atomically). D21c and D21g above are STILL pending.
    expect($register)->toContain('D1a — who APPLIES an approved correction — `OWNER_POLICY_APPROVED`');
});

it('publishes rule 194 and marks the superseded tariff mandate in rules 188, 190, 191, 192 and 193', function () {
    $rule = (string) file_get_contents(base_path('.cursor/rules/194-rme-manual-pricing-authority.mdc'));
    expect($rule)->toContain('D21 REVISED')
        ->and($rule)->toContain('MANUAL_CASHIER_ENTRY')
        ->and($rule)->toContain('LEGACY_SUPERSEDED')
        ->and($rule)->toContain('D21c is PENDING_SUB_DECISION');

    $markers = [
        '188-rme-billing-correction-financial-integrity-foundation.mdc' => 'rule 194',
        '190-rme-invoice-correction-receipt-foundation.mdc' => 'SUPERSEDED AGAIN by rule 194',
        '191-rme-invoice-correction-prepayment-activation.mdc' => 'SUPERSEDED by rule 194',
        '192-rme-branch-tariff-authority-financial-pricing.mdc' => 'SUPERSEDED IN PART by rule 194',
        '193-rme-branch-tariff-provisioning-governance.mdc' => 'SCOPE NARROWED by rule 194',
    ];
    foreach ($markers as $file => $marker) {
        expect((string) file_get_contents(base_path('.cursor/rules/'.$file)))->toContain($marker);
    }

    expect((string) file_get_contents(base_path('CLAUDE.md')))->toContain('REVISION-RME-MANUAL-PRICING-AUTHORITY-1');
});

// REVISED by rule 195 (ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2): the
// manual-pricing release pinned "no role holds a correction permission". The
// activation ceremony granted D1/D1b; the flag still ships OFF by default — it is
// switched on only through the environment, and only for the SPN4 pilot.
it('keeps invoice correction OFF by default and grants its permissions exactly per rule 195', function () {
    $definition = require base_path('config/feature_flags.php');
    expect($definition['flags'][RmeInvoiceCorrectionFeatureGuard::FLAG]['default'])->toBeFalse();

    $map = [
        'Kasir' => [RmeInvoiceCorrectionPermission::REQUEST],
        'Admin Klinik' => [RmeInvoiceCorrectionPermission::APPROVE],
        'Front Office' => [RmeInvoiceCorrectionPermission::REQUEST, RmeInvoiceCorrectionPermission::APPROVE],
    ];

    foreach (Role::where('name', '!=', 'Super Admin')->get() as $role) {
        foreach (RmeInvoiceCorrectionPermission::OPERATIONAL as $permission) {
            expect($role->hasPermissionTo($permission))->toBe(in_array($permission, $map[$role->name] ?? [], true), "{$role->name} / {$permission}");
        }
    }
});

it('grants the legacy tariff-exception permissions to no role, and keeps strict pricing and exceptions source-controlled OFF', function () {
    foreach (Role::where('name', '!=', 'Super Admin')->get() as $role) {
        foreach ([RmeTariffExceptionPermission::OVERRIDE_PRICE, RmeTariffExceptionPermission::AUTHORIZE_DISCOUNT] as $permission) {
            expect($role->hasPermissionTo($permission))->toBeFalse("{$role->name} must not hold {$permission}");
        }
    }

    $source = (string) file_get_contents(base_path('config/rme_tariff_authority.php'));
    expect($source)->toContain("'strict_branch_codes' => [],")
        ->and($source)->toContain("'price_exceptions_enabled' => false,")
        ->and($source)->not->toContain('env(');
});

it('keeps Finance Klinik and Data Analyst read-only: no billing, payment or correction mutation', function () {
    $mutations = ['manage_rme_billing', ...RmeInvoiceCorrectionPermission::OPERATIONAL, RmeTariffExceptionPermission::OVERRIDE_PRICE, RmeTariffExceptionPermission::AUTHORIZE_DISCOUNT];

    foreach (['Finance Klinik', 'Data Analyst'] as $roleName) {
        $role = Role::findByName($roleName, 'web');
        foreach ($mutations as $permission) {
            expect($role->hasPermissionTo($permission))->toBeFalse("{$roleName} must not hold {$permission}");
        }
    }
});
