<?php

/*
| FEATURE-RME-INVOICE-CORRECTION-RECEIPT-FOUNDATION-1 — governance pins: the
| capability ships OFF, the operational permissions are granted to NO role, the
| oversight permission only to the two read-only roles, every route carries its
| permission layer, and nothing touches the laboratory billing tables.
*/

use App\Modules\AccessControl\Services\PermissionGroupingService;
use App\Modules\ClinicOversight\Support\ClinicOversightScope;
use App\Modules\PatientMerge\Support\PatientOwnedRecordRegistry;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionFeatureGuard;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionPermission;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionStatus;
use App\Modules\RmeInvoice\Support\RmeInvoiceCorrectionType;
use App\Services\Foundation\FeatureFlagService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => seedAccessControl());

it('ships the capability flag OFF, critical, with an environment override that survives config:cache', function () {
    $definition = require base_path('config/feature_flags.php');
    $flag = $definition['flags'][RmeInvoiceCorrectionFeatureGuard::FLAG];

    expect($flag['default'])->toBeFalse()
        ->and($flag['risk_level'])->toBe('critical')
        ->and($flag['env_key'])->toBe('FEATURE_RME_INVOICE_CORRECTION')
        ->and(array_key_exists('env_value', $flag))->toBeTrue('the env override must be captured at config-build time');

    // With no override the runtime answer is OFF.
    $flags = config('feature_flags.flags');
    $flags[RmeInvoiceCorrectionFeatureGuard::FLAG]['env_value'] = null;
    config()->set('feature_flags.flags', $flags);
    expect(app(RmeInvoiceCorrectionFeatureGuard::class)->enabled())->toBeFalse()
        ->and(app(FeatureFlagService::class)->enabled(RmeInvoiceCorrectionFeatureGuard::FLAG))->toBeFalse();
});

it('seeds the four new permissions and classifies each in a permission group', function () {
    $new = [...RmeInvoiceCorrectionPermission::OPERATIONAL, ClinicOversightScope::PERMISSION_BILLING_CORRECTION];
    $groups = app(PermissionGroupingService::class)->group(Permission::orderBy('name')->get());
    $grouped = collect($groups)->where('key', '!=', 'other')->flatMap(fn ($g) => collect($g['permissions'])->pluck('name'))->all();

    foreach ($new as $permission) {
        expect(Permission::where('name', $permission)->where('guard_name', 'web')->exists())->toBeTrue($permission)
            ->and($grouped)->toContain($permission);
    }
});

// REVISED by rule 195 (ACTIVATION-RME-INVOICE-CORRECTION-PREPAYMENT-2): this
// pinned the dormant posture — "NO role except the Super Admin wildcard". D1 is
// now granted: Kasir requests, Admin Klinik approves (which applies, D1a), Front
// Office — their exact union — holds both (owner decision 2026-10-21). The
// separate apply permission is still held by NO operational role, and every role
// not listed must still hold nothing.
it('grants the operational correction permissions exactly as rule 195 maps them', function () {
    $map = [
        'Kasir' => [RmeInvoiceCorrectionPermission::REQUEST],
        'Admin Klinik' => [RmeInvoiceCorrectionPermission::APPROVE],
        'Front Office' => [RmeInvoiceCorrectionPermission::REQUEST, RmeInvoiceCorrectionPermission::APPROVE],
    ];

    foreach (Role::where('name', '!=', 'Super Admin')->get() as $role) {
        $granted = $map[$role->name] ?? [];
        foreach (RmeInvoiceCorrectionPermission::OPERATIONAL as $permission) {
            expect($role->hasPermissionTo($permission))->toBe(in_array($permission, $granted, true), "{$role->name} / {$permission}");
        }
    }
});

it('grants the oversight permission only to Finance Klinik, Data Analyst and (RF-8a) Owner — never to the laboratory Finance role', function () {
    $holders = Role::all()
        ->filter(fn (Role $r) => $r->name !== 'Super Admin' && $r->hasPermissionTo(ClinicOversightScope::PERMISSION_BILLING_CORRECTION))
        ->pluck('name')->sort()->values()->all();

    // RF-8a (rule 215): Owner reads the area. It is read-only — Owner holds
    // no export and no correction or refund permission of any kind.
    expect($holders)->toBe(['Data Analyst', 'Finance Klinik', 'Owner'])
        ->and(Role::findByName('Owner', 'web')->hasPermissionTo(ClinicOversightScope::PERMISSION_EXPORT))->toBeFalse()
        ->and(collect(RmeInvoiceCorrectionPermission::OPERATIONAL)->merge(['request_rme_refund', 'approve_rme_refund'])
            ->filter(fn (string $p) => Role::findByName('Owner', 'web')->hasPermissionTo($p))->values()->all())->toBe([])
        ->and(Role::findByName('Finance', 'web')->hasPermissionTo(ClinicOversightScope::PERMISSION_BILLING_CORRECTION))->toBeFalse();
});

it('puts a correction permission on every operational correction route and the right one on each mutation', function () {
    $expected = [
        'rme.invoice-corrections.create' => RmeInvoiceCorrectionPermission::REQUEST,
        'rme.invoice-corrections.store' => RmeInvoiceCorrectionPermission::REQUEST,
        'rme.invoice-corrections.cancel' => RmeInvoiceCorrectionPermission::REQUEST,
        'rme.invoice-corrections.approve' => RmeInvoiceCorrectionPermission::APPROVE,
        'rme.invoice-corrections.reject' => RmeInvoiceCorrectionPermission::APPROVE,
        'rme.invoice-corrections.apply' => RmeInvoiceCorrectionPermission::APPLY,
    ];

    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'rme.invoice-corrections.'));
    expect($routes)->toHaveCount(10);

    foreach ($routes as $route) {
        $middleware = $route->gatherMiddleware();
        expect($middleware)->toContain('auth')
            ->and(collect($middleware)->contains(fn ($m) => str_starts_with((string) $m, 'permission:') && str_contains((string) $m, 'request_rme_invoice_correction')))->toBeTrue((string) $route->getName());

        $name = (string) $route->getName();
        if (isset($expected[$name])) {
            expect($middleware)->toContain('permission:'.$expected[$name])
                ->and($route->methods())->toContain($name === 'rme.invoice-corrections.create' ? 'GET' : 'POST');
        }
    }
});

it('exposes the oversight Billing Correction area as GET-only, gated by its own permission', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'oversight.billing-corrections.'));
    // FEATURE-RME-POSTPAYMENT-INVOICE-CORRECTION-1 (PP-9) adds the two read-only
    // overpayment obligation pages; rule 215 (RF-8) adds the two read-only
    // refund request pages; rule 216 (RFS-6) adds the two read-only refund
    // settlement pages. Every route in the area stays GET-only.
    expect($routes)->toHaveCount(13)
        ->and($routes->map(fn ($r) => (string) $r->getName())->values()->all())
        ->toContain(
            'oversight.billing-corrections.obligations.index', 'oversight.billing-corrections.obligations.show',
            'oversight.billing-corrections.refund-requests.index', 'oversight.billing-corrections.refund-requests.show',
            'oversight.billing-corrections.refund-settlements.index', 'oversight.billing-corrections.refund-settlements.show',
        );

    foreach ($routes as $route) {
        expect(array_diff($route->methods(), ['GET', 'HEAD']))->toBe([], (string) $route->getName())
            ->and($route->gatherMiddleware())->toContain('permission:'.ClinicOversightScope::PERMISSION_BILLING_CORRECTION);
    }
});

it('declares no reversal, credit, settlement or Rp0 capability and enables only the pre-payment correction type', function () {
    expect(RmeInvoiceCorrectionType::ENABLED)->toBe([RmeInvoiceCorrectionType::PRE_PAYMENT]);

    $names = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => (string) $r->getName())->filter(fn ($n) => str_contains($n, 'invoice-correction') || str_contains($n, 'billing-correction'));
    foreach (['reverse', 'reversal', 'credit', 'payout', 'disburse'] as $forbidden) {
        expect($names->filter(fn ($n) => str_contains($n, $forbidden))->all())->toBe([], $forbidden);
    }

    // SUPERSEDED in part by FEATURE-RME-REFUND-SETTLEMENT-1 (rule 216): a refund
    // payout now exists, but the correction area only READS it — the two "settle"
    // names allowed here are the read-only oversight pages, never a mutation.
    expect($names->filter(fn ($n) => str_contains($n, 'settle'))->sort()->values()->all())->toBe([
        'oversight.billing-corrections.refund-settlements.index',
        'oversight.billing-corrections.refund-settlements.show',
    ]);

    // Rule 215 adds a refund REQUEST and its approval; rule 216 adds the payout as a
    // separate capability with its own permission. The only refund names in the
    // correction area are the four read-only oversight pages.
    expect($names->filter(fn ($n) => str_contains($n, 'refund'))->sort()->values()->all())->toBe([
        'oversight.billing-corrections.refund-requests.index',
        'oversight.billing-corrections.refund-requests.show',
        'oversight.billing-corrections.refund-settlements.index',
        'oversight.billing-corrections.refund-settlements.show',
    ]);
    expect(Permission::where('name', 'like', '%refund%')->pluck('name')->sort()->values()->all())
        ->toBe(['approve_rme_refund', 'request_rme_refund', 'settle_rme_refund']);
});

it('keeps the state machine closed: terminal states have no outgoing edge and only SUBMITTED/APPROVED are active', function () {
    foreach (RmeInvoiceCorrectionStatus::TERMINAL as $terminal) {
        expect(RmeInvoiceCorrectionStatus::TRANSITIONS[$terminal] ?? [])->toBe([]);
    }

    expect(RmeInvoiceCorrectionStatus::ACTIVE)->toBe([RmeInvoiceCorrectionStatus::SUBMITTED, RmeInvoiceCorrectionStatus::APPROVED])
        ->and(RmeInvoiceCorrectionStatus::canTransition('SUBMITTED', 'APPLIED'))->toBeFalse()
        ->and(RmeInvoiceCorrectionStatus::canTransition('REJECTED', 'APPROVED'))->toBeFalse()
        ->and(RmeInvoiceCorrectionStatus::canTransition('APPLIED', 'STALE'))->toBeFalse();
});

it('adds only new tables and one defaulted column, and never touches the laboratory billing tables', function () {
    foreach (['trx_rme_invoice_corrections', 'trx_rme_invoice_correction_lines', 'trx_rme_invoice_correction_events', 'trx_rme_invoice_document_versions'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }

    expect(Schema::hasColumn('trx_rme_invoices', 'correction_version'))->toBeTrue()
        ->and((new RmeInvoice)->getFillable())->not->toContain('correction_version');

    $migrations = collect(glob(database_path('migrations/2026_10_17_10000*.php')));
    expect($migrations)->toHaveCount(5);
    // Only up() is the deploy path; down() legitimately drops what up() added.
    $source = $migrations->map(fn ($f) => strstr((string) file_get_contents($f), 'public function down', true))->implode("\n");
    foreach (['trx_invoices', 'trx_invoice_items', 'trx_payments', 'trx_rme_payments'] as $untouched) {
        expect($source)->not->toContain("Schema::table('{$untouched}'");
    }
    expect($source)->not->toContain('dropColumn')
        ->and($source)->not->toContain('->change()');
});

it('registers the correction table with the patient merge so a merge re-points it instead of orphaning it', function () {
    expect(PatientOwnedRecordRegistry::REASSIGN)->toHaveKey('trx_rme_invoice_corrections')
        ->and(PatientOwnedRecordRegistry::REASSIGN['trx_rme_invoice_corrections']['column'])->toBe('patient_id');
});
