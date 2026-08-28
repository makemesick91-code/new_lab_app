<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-IMPORT-HUB-1 — the navigation surface and its boundary.
|--------------------------------------------------------------------------
|
| THE SIDEBAR IS NOT THE SECURITY BOUNDARY. Every test that asserts a menu item
| is absent has a sibling that asserts the ROUTE refuses the same actor. A
| hidden link is a courtesy; a 403 is the control.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyImport\Services\LegacyImportHubService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Support\Clinical\ClinicalClock;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../LegacyOdontogram/helpers.php';

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
});

/*
|--------------------------------------------------------------------------
| The routes exist
|--------------------------------------------------------------------------
*/

it('registers the hub and all three importer entry points', function () {
    // The hub page renders links by route NAME from the registry, so a rename
    // anywhere breaks the page. Pinning the names here makes that a test
    // failure rather than a runtime error in front of an operator.
    expect(Route::has('settings.legacy-imports.index'))->toBeTrue();
    expect(Route::has('settings.patients.import.index'))->toBeTrue();
    expect(Route::has('settings.rme.legacy-imports.index'))->toBeTrue();
    expect(Route::has('settings.rme.legacy-odontograms.index'))->toBeTrue();
});

it('gates the hub route with permission middleware, not only the controller', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => $r->getName() === 'settings.legacy-imports.index');

    expect($route)->not->toBeNull();

    $middleware = implode(' ', $route->gatherMiddleware());

    // DEFENCE IN DEPTH HAS TO BE PINNED IN DEPTH. The controller re-checks
    // reachability, so deleting this middleware leaves every behavioural test
    // green while removing a layer — a surviving mutant found exactly that. The
    // route file is edited far more often than the controller, so the layer it
    // carries gets its own assertion.
    expect($middleware)->toContain('auth');
    expect($middleware)->toContain('permission:');

    foreach ([
        'manage patients',
        'view_legacy_rme_imports',
        'create_legacy_rme_imports',
        'view_legacy_odontogram_imports',
        'create_legacy_odontogram_imports',
    ] as $permission) {
        expect($middleware)->toContain($permission);
    }
});

it('exposes the hub over GET only', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->getName() === 'settings.legacy-imports.index');

    expect($routes)->toHaveCount(1);

    // A read-only report must not offer a write verb, so a future edit that
    // adds one has to change this test deliberately.
    expect($routes->first()->methods())->toEqualCanonicalizing(['GET', 'HEAD']);
});

/*
|--------------------------------------------------------------------------
| Who may reach it
|--------------------------------------------------------------------------
*/

it('lets an operator who holds any one capability reach the hub', function () {
    lihBranch();

    foreach ([
        ['manage patients'],
        ['view_legacy_rme_imports'],
        ['create_legacy_rme_imports'],
        ['view_legacy_odontogram_imports'],
        ['create_legacy_odontogram_imports'],
    ] as $permissions) {
        $this->actingAs(lihOperator($permissions))
            ->get(route('settings.legacy-imports.index'))
            ->assertOk();
    }
});

it('refuses an actor who holds none of the three capabilities', function () {
    // A clinical role with real authority elsewhere in the application still has
    // no business on an import surface.
    $this->actingAs(lihOperator(['view_clinic_visits']))
        ->get(route('settings.legacy-imports.index'))
        ->assertForbidden();
});

it('refuses a guest', function () {
    $this->get(route('settings.legacy-imports.index'))->assertRedirect(route('login'));
});

it('refuses direct access to each importer without its own permission', function () {
    // Holding the hub's weakest key must not carry into the importers: an actor
    // with only `manage patients` may reach the hub, and still gets 403 on both
    // archives.
    $actor = lihOperator(['manage patients']);

    $this->actingAs($actor)->get(route('settings.rme.legacy-imports.index'))->assertForbidden();
    $this->actingAs($actor)->get(route('settings.rme.legacy-odontograms.index'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The sidebar
|--------------------------------------------------------------------------
*/

it('shows the Import Data Legacy group with all three entries to a full operator', function () {
    lihBranch();

    $response = $this->actingAs(lihOperator([
        'manage patients',
        'view_legacy_rme_imports',
        'create_legacy_rme_imports',
        'view_legacy_odontogram_imports',
        'create_legacy_odontogram_imports',
    ]))->get(route('settings.legacy-imports.index'))->assertOk();

    $response->assertSee('Import Data Legacy', false);
    $response->assertSee('Upload Legacy Pasien', false);
    $response->assertSee('Upload Legacy RME', false);
    $response->assertSee('Upload Legacy Odontogram', false);
});

it('hides an entry whose permission the actor does not hold', function () {
    lihBranch();

    $response = $this->actingAs(lihOperator(['manage patients']))
        ->get(route('settings.legacy-imports.index'))
        ->assertOk();

    $response->assertSee('Upload Legacy Pasien', false);
    $response->assertDontSee('Upload Legacy RME', false);
    $response->assertDontSee('Upload Legacy Odontogram', false);
});

it('hides an archive entry while its migration flag is off', function () {
    lihBranch();
    legacyRmeArchiveFlag(false);
    lodoFlag(false);

    $response = $this->actingAs(lihOperator([
        'view_legacy_rme_imports',
        'create_legacy_rme_imports',
        'view_legacy_odontogram_imports',
        'create_legacy_odontogram_imports',
    ]))->get(route('settings.legacy-imports.index'))->assertOk();

    $response->assertDontSee('Upload Legacy RME', false);
    $response->assertDontSee('Upload Legacy Odontogram', false);
});

/*
|--------------------------------------------------------------------------
| What the page reports
|--------------------------------------------------------------------------
*/

it('reports the ceiling, what was used and what is left', function () {
    // legacyRmeBranch() (not lihBranch()) because this test asserts `aktif`,
    // and after FEATURE-LEGACY-IMPORT-HUB-1A that word means every gate is
    // open — admission and a running wave included. The fixture admits and
    // enrols the branch exactly as a real activation does.
    $branch = legacyRmeBranch();

    lihConsume(LegacyImportType::LEGACY_RME, (int) $branch->id, 7);

    $actor = lihOperator(['view_legacy_rme_imports', 'create_legacy_rme_imports']);
    $actor->forceFill(['branch_id' => $branch->id])->save();

    $overview = app(LegacyImportHubService::class)->overview($actor->refresh());
    $rme = collect($overview['types'])->firstWhere('type', LegacyImportType::LEGACY_RME);

    expect($rme['limit'])->toBe(100);
    expect($rme['used_today'])->toBe(7);
    expect($rme['remaining_today'])->toBe(93);
    expect($rme['status'])->toBe('aktif');
});

it('reports a capability as nonaktif when its migration flag is off', function () {
    $branch = lihBranch();
    legacyRmeArchiveFlag(false);

    $actor = lihOperator(['view_legacy_rme_imports']);
    $actor->forceFill(['branch_id' => $branch->id])->save();

    $overview = app(LegacyImportHubService::class)->overview($actor->refresh());
    $rme = collect($overview['types'])->firstWhere('type', LegacyImportType::LEGACY_RME);

    // Reporting "aktif" for a surface that refuses every upload is exactly the
    // lie this status field exists to prevent.
    expect($rme['status'])->toBe('nonaktif');
    expect($rme['capability_enabled'])->toBeFalse();
});

it('marks only legacy RME as carrying gates beyond the flag and the route', function () {
    $branch = lihBranch();

    $actor = lihOperator(['view_legacy_rme_imports']);
    $actor->forceFill(['branch_id' => $branch->id])->save();

    $overview = app(LegacyImportHubService::class)->overview($actor->refresh());

    // `has_additional_gates` is a property of the TYPE — does anything beyond
    // the flag and the route govern it — and is fixed for its lifetime. What
    // those gates currently SAY is `additional_gates`, asserted separately.
    // Publishing only the first is what let a fully closed migration read as
    // "Aktif" for an entire release.
    expect(collect($overview['types'])->firstWhere('type', LegacyImportType::LEGACY_RME)['has_additional_gates'])->toBeTrue();
    expect(collect($overview['types'])->firstWhere('type', LegacyImportType::LEGACY_PATIENT)['has_additional_gates'])->toBeFalse();

    // A type without extra gates carries no gate state to report, rather than
    // an empty one that could be misread as "evaluated and open".
    expect(collect($overview['types'])->firstWhere('type', LegacyImportType::LEGACY_PATIENT)['additional_gates'])->toBeNull();
});

it('reports the clinical calendar the ceiling rolls over on', function () {
    lihBranch();

    $overview = app(LegacyImportHubService::class)->overview(lihOperator(['manage patients']));

    expect($overview['timezone'])->toBe(app(ClinicalClock::class)->timezone());
    expect($overview['clinical_date'])->toBe(app(ClinicalClock::class)->todayString());
});

/*
|--------------------------------------------------------------------------
| Branch scope — the IDOR boundary
|--------------------------------------------------------------------------
*/

it('shows a single-branch operator only their own branch', function () {
    $own = lihBranch('TKM1', 'Cabang Telkomas');
    $other = lihBranch('LDK2', 'Cabang Landak');

    lihConsume(LegacyImportType::LEGACY_RME, (int) $other->id, 5);

    $actor = lihOperator(['create_legacy_rme_imports']);
    $actor->forceFill(['branch_id' => $own->id])->save();

    $overview = app(LegacyImportHubService::class)->overview($actor->refresh());

    expect(collect($overview['branches'])->pluck('id')->all())->toBe([(int) $own->id]);

    // Another branch's consumption is not merely hidden from the table — it is
    // not in the totals either.
    $rme = collect($overview['types'])->firstWhere('type', LegacyImportType::LEGACY_RME);
    expect($rme['used_today'])->toBe(0);
});

it('shows the governance tier every RME branch', function () {
    $a = lihBranch('TKM1', 'Cabang Telkomas');
    $b = lihBranch('LDK2', 'Cabang Landak');

    $actor = lihOperator(['publish_legacy_rme_imports']);
    $actor->forceFill(['branch_id' => $a->id])->save();

    $overview = app(LegacyImportHubService::class)->overview($actor->refresh());

    expect(collect($overview['branches'])->pluck('id')->all())
        ->toEqualCanonicalizing([(int) $a->id, (int) $b->id]);
});

it('never widens beyond one branch for an unplaced non-governance actor', function () {
    $a = lihBranch('TKM1', 'Cabang Telkomas');
    $b = lihBranch('LDK2', 'Cabang Landak');

    // An operator with no `branch_id` still resolves through BranchContext's
    // canonical fallback chain (online context → column → relation → default),
    // which is shared with every other branch-scoped surface and is NOT this
    // sprint's to change. What IS this sprint's contract is that the fallback
    // yields AT MOST the one branch it resolved — never the governance-wide set.
    $actor = lihOperator(['create_legacy_odontogram_imports']);
    $actor->forceFill(['branch_id' => null])->save();

    $overview = app(LegacyImportHubService::class)->overview($actor->refresh());

    expect(count($overview['branches']))->toBeLessThanOrEqual(1);
    expect(collect($overview['branches'])->pluck('id')->all())
        ->not->toEqualCanonicalizing([(int) $a->id, (int) $b->id]);
});

it('shows no branch when the resolved one is not RME-enabled', function () {
    $rme = lihBranch('TKM1', 'Cabang Telkomas');

    // MAIN is never an RME branch, and a legacy import can never be charged to
    // it. An actor pinned there sees nothing rather than borrowing the RME set.
    $main = Branch::query()
        ->where('code', Branch::MAIN_CODE)
        ->first();

    if ($main === null) {
        $main = Branch::query()->create([
            'code' => Branch::MAIN_CODE,
            'name' => 'Pusat',
            'is_active' => true,
            'is_rme_enabled' => false,
        ]);
    }

    $main->forceFill(['is_active' => true, 'is_rme_enabled' => false])->save();

    $actor = lihOperator(['create_legacy_odontogram_imports']);
    $actor->forceFill(['branch_id' => $main->id])->save();

    $overview = app(LegacyImportHubService::class)->overview($actor->refresh());

    expect($overview['branches'])->toBe([]);
    expect(collect($overview['branches'])->pluck('id')->all())->not->toContain((int) $rme->id);
});

it('ignores a branch supplied by the request', function () {
    $own = lihBranch('TKM1', 'Cabang Telkomas');
    $other = lihBranch('LDK2', 'Cabang Landak');

    lihConsume(LegacyImportType::LEGACY_RME, (int) $other->id, 9);

    $actor = lihOperator(['create_legacy_rme_imports']);
    $actor->forceFill(['branch_id' => $own->id])->save();

    // A forged branch id in the query string must change nothing: the scope is
    // resolved from the actor, never read from the request.
    $response = $this->actingAs($actor->refresh())
        ->get(route('settings.legacy-imports.index', ['branch_id' => $other->id]))
        ->assertOk();

    $response->assertSee('TKM1', false);
    $response->assertDontSee('Cabang Landak', false);
});

/*
|--------------------------------------------------------------------------
| Privacy
|--------------------------------------------------------------------------
*/

it('renders no patient identity on the hub', function () {
    $branch = lihBranch();

    $patient = lodoPatient(['name' => 'Budi Rahasia', 'ktp_number' => '3201010101010001']);

    lihConsume(LegacyImportType::LEGACY_RME, (int) $branch->id, 1);

    $actor = lihOperator(['view_legacy_rme_imports']);
    $actor->forceFill(['branch_id' => $branch->id])->save();

    $response = $this->actingAs($actor->refresh())
        ->get(route('settings.legacy-imports.index'))
        ->assertOk();

    // Counts, limits, branch codes and labels. Nothing that identifies a person.
    $response->assertDontSee('Budi Rahasia', false);
    $response->assertDontSee('3201010101010001', false);
    $response->assertDontSee($patient->medical_record_number, false);
});

/*
|--------------------------------------------------------------------------
| Role grants — FEATURE-LEGACY-IMPORT-HUB-1
|--------------------------------------------------------------------------
|
| FIX-04b created the legacy ODONTOGRAM permissions and assigned them to no
| role, so a complete capability was reachable only by Super Admin. These pin
| the grant that closed it, and — just as importantly — the duties it withholds.
*/

it('grants Admin Klinik legacy odontogram INTAKE, mirroring the legacy RME archive', function () {
    $actor = userInRole('Admin Klinik');

    expect($actor->can('view_legacy_odontogram_imports'))->toBeTrue();
    expect($actor->can('create_legacy_odontogram_imports'))->toBeTrue();

    // The operator who files a chart must not be the one who certifies it into
    // patient history. Withholding these is the separation, not an oversight.
    expect($actor->can('review_legacy_odontogram_imports'))->toBeFalse();
    expect($actor->can('publish_legacy_odontogram_imports'))->toBeFalse();
    expect($actor->can('void_legacy_odontogram_records'))->toBeFalse();
});

it('grants Supervisor RME legacy odontogram REVIEW and PUBLISH but never intake', function () {
    $actor = userInRole('Supervisor RME');

    expect($actor->can('view_legacy_odontogram_imports'))->toBeTrue();
    expect($actor->can('review_legacy_odontogram_imports'))->toBeTrue();
    expect($actor->can('publish_legacy_odontogram_imports'))->toBeTrue();

    // Cannot review its own intake, and cannot retract published evidence.
    expect($actor->can('create_legacy_odontogram_imports'))->toBeFalse();
    expect($actor->can('void_legacy_odontogram_records'))->toBeFalse();
});

it('grants no legacy import duty to a clinical or cashier role', function () {
    foreach (['Doctor', 'Kasir'] as $role) {
        $actor = userInRole($role);

        foreach ([
            'create_legacy_odontogram_imports',
            'review_legacy_odontogram_imports',
            'publish_legacy_odontogram_imports',
            'void_legacy_odontogram_records',
            'create_legacy_rme_imports',
            'review_legacy_rme_imports',
            'publish_legacy_rme_imports',
        ] as $permission) {
            expect($actor->can($permission))->toBeFalse("{$role} must not hold {$permission}");
        }
    }
});

it('lets Admin Klinik actually reach the legacy odontogram surface it now holds', function () {
    lihBranch();

    // The grant is only real if the route accepts it. Admin Klinik was
    // previously excluded from the Master Data RME sidebar group entirely, so
    // the capability existed with no way in.
    //
    // The Sprint 66 online-context middleware redirects an Admin Klinik with no
    // selected RME context (302, orthogonal to this grant), so it is bypassed to
    // exercise the AUTHORIZATION boundary this test is about.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs(userInRole('Admin Klinik'))
        ->get(route('settings.rme.legacy-odontograms.index'))
        ->assertOk();
});
