<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyImport\BatchReview\Models\LegacyReviewTriage;
use App\Modules\LegacyImport\BatchReview\Support\LegacyReviewTriageStatus;
use App\Modules\LegacyImport\Completeness\Interfaces\LegacyPatientArchiveCompletenessRepositoryInterface;
use App\Modules\LegacyImport\Completeness\Services\LegacyPatientArchiveCompletenessService;
use App\Modules\LegacyImport\Completeness\Support\LegacyCompletenessFilter;
use App\Modules\LegacyImport\Completeness\Support\LegacyPatientArchiveRow;
use App\Modules\LegacyImport\Services\LegacyImportHubService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramImportStatus;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use App\Modules\Patient\Models\LegacyPatientImportBatch;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Support\AccessControl\FrontOfficeRole;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Middleware\PermissionMiddleware;

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-PATIENT-DOCUMENT-COMPLETENESS-1 — RBAC + branch isolation
|--------------------------------------------------------------------------
|
| The page shows patient identity, so who may open it and how far they may see
| are the two properties that matter most.
|
| Super Admin      -> every RME branch (via the single global Gate::before)
| Supervisor RME   -> every RME branch (it is the archive's governance tier)
| Admin Klinik     -> its OWN branch only, and nothing when unresolvable
| anyone else      -> 403, and no sidebar entry
|
| Every denial is asserted on a DIRECT GET as well as on the sidebar, because
| a hidden menu is a convenience and never a boundary.
*/

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    $this->seed(RoleSeeder::class);

    // Four real branch codes, matching the canonical registry.
    $this->spn4 = lcaBranch('SPN4', 'Cabang Sunu');
    $this->tlk1 = lcaBranch('TLK1', 'Cabang Telkomas');
    $this->ldk2 = lcaBranch('LDK2', 'Cabang Landak');
    $this->atg3 = lcaBranch('ATG3', 'Cabang Antang');
    // MAIN exists but is NOT an RME branch, so it can never satisfy the scope.
    $this->main = Branch::query()->firstOrCreate(
        ['code' => 'MAIN'],
        ['name' => 'Kantor Pusat', 'is_active' => true, 'is_rme_enabled' => false],
    );

    $this->service = app(LegacyPatientArchiveCompletenessService::class);
});

function lcaBranch(string $code, string $name): Branch
{
    return Branch::query()->firstOrCreate(
        ['code' => $code],
        ['name' => $name, 'is_active' => true, 'is_rme_enabled' => true],
    );
}

function lcaLegacyPatient(Branch $branch, array $attributes = []): Patient
{
    $batch = LegacyPatientImportBatch::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_filename' => 'legacy-patients.csv',
        'status' => LegacyPatientImportBatch::STATUS_COMMITTED,
    ]);

    return Patient::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'import_batch_id' => $batch->id,
        'date_of_birth' => '1990-01-01',
    ], $attributes));
}

function lcaUser(string $role, ?Branch $branch = null): User
{
    $user = User::factory()->create($branch === null ? [] : ['branch_id' => $branch->id]);
    $user->assignRole($role);

    return $user->fresh();
}

function lcaUrl(array $params = []): string
{
    return route('settings.legacy-patient-completeness.index', $params);
}

function lcaGet(User $user, array $params = [])
{
    // The Sprint 66 online-context middleware is orthogonal to this page's own
    // authorization and would redirect a context-bound role before the page was
    // ever reached, so it is lifted to exercise the boundary under test.
    return test()
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->actingAs($user)
        ->get(lcaUrl($params));
}

function lcaVisibleIds(User $user, array $params = []): array
{
    $service = app(LegacyPatientArchiveCompletenessService::class);
    $query = $service->resolveQuery(
        $user,
        $params['status'] ?? null,
        $params['q'] ?? null,
        isset($params['branch_id']) ? (int) $params['branch_id'] : null,
    );

    return array_map(
        static fn (object $row): int => $row->patientId,
        $service->rows($query)->items(),
    );
}

/* ----------------------------------------------------------------- permitted */

it('allows a super admin', function (): void {
    lcaGet(lcaUser('Super Admin'))->assertOk();
});

it('allows supervisor rme', function (): void {
    lcaGet(lcaUser('Supervisor RME'))->assertOk();
});

it('allows admin klinik', function (): void {
    lcaGet(lcaUser('Admin Klinik', $this->spn4))->assertOk();
});

it('allows front office, because that role IS the clinic admin in practice', function (): void {
    // THE §13 MISMATCH, RESOLVED AND PINNED. The owner asked for Super Admin /
    // Supervisor RME / Admin Klinik. In this system the business identity
    // "Admin Klinik" is implemented by the `Front Office` role: it is defined
    // as the exact union of the two legacy roles it merged
    // (FrontOfficeRole::LEGACY_ADMIN_CLINIC and LEGACY_KASIR), that union is
    // test-enforced by FrontOfficeRoleTest so a permission added to Admin
    // Klinik cannot silently skip it, and production holds ZERO Admin Klinik
    // accounts — all four branch clinic admins carry this role.
    //
    // So granting Admin Klinik alone would have broken a tested invariant AND
    // reached nobody. This is a reported consequence, not an accident.
    lcaGet(lcaUser('Front Office', $this->spn4))->assertOk();
});

it('keeps front office branch-pinned despite being allowed', function (): void {
    // The grant widens WHO may open the page, never HOW FAR they see. A front
    // desk account is not governance tier, so it stays on its own branch — which
    // is what makes the wider audience acceptable.
    $mine = lcaLegacyPatient($this->spn4);
    $foreign = lcaLegacyPatient($this->tlk1);

    $frontOffice = lcaUser('Front Office', $this->spn4);
    $visible = lcaVisibleIds($frontOffice);

    expect($this->service->governsEveryBranch($frontOffice))->toBeFalse()
        ->and($visible)->toContain($mine->id)
        ->and($visible)->not->toContain($foreign->id);
});

it('keeps the merged front office role equal to the union of its legacy roles', function (): void {
    // Pinned HERE as well as in FrontOfficeRoleTest, so this sprint's own grant
    // cannot be the thing that breaks the merge. If a future change gives the
    // completeness permission to one of the two legacy roles and forgets the
    // merged one, the clinic admins silently lose the page.
    $union = collect(RoleSeeder::ROLE_PERMISSIONS[FrontOfficeRole::LEGACY_ADMIN_CLINIC])
        ->merge(RoleSeeder::ROLE_PERMISSIONS[FrontOfficeRole::LEGACY_KASIR])
        ->unique()->sort()->values()->all();

    $frontOffice = collect(RoleSeeder::ROLE_PERMISSIONS[FrontOfficeRole::NAME])
        ->unique()->sort()->values()->all();

    expect($frontOffice)->toBe($union)
        ->and($union)->toContain(LegacyPatientArchiveCompletenessService::READ_PERMISSION);
});

/* -------------------------------------------------------------------- denied */

it('denies every role that was not granted the read permission', function (string $role): void {
    $user = lcaUser($role, $this->spn4);

    expect($user->can(LegacyPatientArchiveCompletenessService::READ_PERMISSION))->toBeFalse();

    lcaGet($user)->assertForbidden();
})->with([
    'Doctor',
    'Kasir',
    'Perawat',
    'Admin Lab',
    'Technician',
    'Admin Warehouse',
    'Owner',
    'Finance',
    'Kepala Cabang',
    // NOTE: `Front Office` is deliberately ABSENT from this denial list — it is
    // the merged clinic-admin identity and is asserted as ALLOWED above, with
    // the reasoning and the measured consequence recorded there.
]);

it('denies a guest with a redirect to login rather than a 403', function (): void {
    $this->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(lcaUrl())
        ->assertRedirect(route('login'));
});

it('denies an actor holding only the legacy intake permissions', function (): void {
    // Defence in depth against the shortcut this page deliberately avoided:
    // reusing `view_legacy_*_imports` would have handed the report to every
    // upload operator as a side effect.
    $user = User::factory()->create(['branch_id' => $this->spn4->id]);
    $user->givePermissionTo(['view_legacy_rme_imports', 'create_legacy_rme_imports', 'view_legacy_odontogram_imports']);

    lcaGet($user->fresh())->assertForbidden();
});

it('shows the sidebar entry only to an authorized actor', function (): void {
    $allowed = lcaUser('Admin Klinik', $this->spn4);
    $denied = lcaUser('Kasir', $this->spn4);

    $this->withoutMiddleware(EnsureRmeOnlineContext::class);

    $this->actingAs($allowed)->get(route('dashboard'))
        ->assertSee('Kelengkapan Arsip Pasien Legacy');

    $this->actingAs($denied)->get(route('dashboard'))
        ->assertDontSee('Kelengkapan Arsip Pasien Legacy');
});

/* ------------------------------------------------------------- branch isolation */

it('pins admin klinik to its own branch', function (): void {
    $mine = lcaLegacyPatient($this->spn4);
    $tlk1 = lcaLegacyPatient($this->tlk1);
    $ldk2 = lcaLegacyPatient($this->ldk2);
    $atg3 = lcaLegacyPatient($this->atg3);

    $admin = lcaUser('Admin Klinik', $this->spn4);
    $visible = lcaVisibleIds($admin);

    expect($visible)->toContain($mine->id)
        ->and($visible)->not->toContain($tlk1->id)
        ->and($visible)->not->toContain($ldk2->id)
        ->and($visible)->not->toContain($atg3->id);
});

it('never widens admin klinik through a crafted branch_id', function (): void {
    $mine = lcaLegacyPatient($this->spn4);
    $foreign = lcaLegacyPatient($this->tlk1);

    $admin = lcaUser('Admin Klinik', $this->spn4);

    // The crafted branch is DROPPED, not honoured: the scope is unchanged.
    $visible = lcaVisibleIds($admin, ['branch_id' => $this->tlk1->id]);

    expect($visible)->toContain($mine->id)->and($visible)->not->toContain($foreign->id);

    // And the HTTP surface behaves the same way — it does not 403, it just
    // keeps showing the actor's own branch.
    lcaGet($admin, ['branch_id' => $this->tlk1->id])->assertOk();
});

it('never leaks a foreign branch through any status filter', function (string $filter): void {
    $mine = lcaLegacyPatient($this->spn4);
    $foreign = lcaLegacyPatient($this->tlk1);
    LegacyRmeRecord::factory()->create(['patient_id' => $foreign->id, 'origin_branch_id' => $this->tlk1->id]);

    $admin = lcaUser('Admin Klinik', $this->spn4);
    $visible = lcaVisibleIds($admin, ['status' => $filter]);

    expect($visible)->not->toContain($foreign->id);

    if ($filter === LegacyCompletenessFilter::INCOMPLETE || $filter === LegacyCompletenessFilter::MISSING_BOTH) {
        expect($visible)->toContain($mine->id);
    }
})->with(LegacyCompletenessFilter::ALL);

it('never leaks a foreign branch through search', function (): void {
    $foreign = lcaLegacyPatient($this->tlk1, ['name' => 'Target Rahasia']);
    lcaLegacyPatient($this->spn4, ['name' => 'Pasien Sunu']);

    $admin = lcaUser('Admin Klinik', $this->spn4);

    // Searching the exact name of a patient in another branch finds nothing.
    expect(lcaVisibleIds($admin, ['q' => 'Target Rahasia']))->not->toContain($foreign->id);
});

it('counts only the actor own branch', function (): void {
    lcaLegacyPatient($this->spn4);
    lcaLegacyPatient($this->spn4);
    lcaLegacyPatient($this->tlk1);
    lcaLegacyPatient($this->ldk2);

    $admin = lcaUser('Admin Klinik', $this->spn4);
    $summary = $this->service->summary($this->service->resolveQuery($admin));

    // Two, not four. A branch-scoped actor never sees an estate-wide number.
    expect($summary['total'])->toBe(2);
});

it('offers no branch filter options to a branch-pinned actor', function (): void {
    lcaLegacyPatient($this->spn4);
    lcaLegacyPatient($this->tlk1);

    $admin = lcaUser('Admin Klinik', $this->spn4);
    $options = $this->service->branchOptions($this->service->resolveQuery($admin));

    // The filter can only ever offer branches inside the actor's scope, so a
    // pinned actor is offered their own branch and no other.
    expect(array_keys($options))->toBe([$this->spn4->id]);
});

it('offers no branch option for a branch whose only legacy patient is soft-deleted', function (): void {
    // The branch filter is derived from the rows the actor can actually see, so
    // it must apply the same soft-delete rule the listing does. A branch that
    // contributes nothing to the report must not appear as a filter option —
    // selecting it would return an empty table and look like a bug.
    lcaLegacyPatient($this->spn4);
    $only = lcaLegacyPatient($this->tlk1);
    $only->delete();

    $options = $this->service->branchOptions(
        $this->service->resolveQuery(lcaUser('Supervisor RME')),
    );

    expect(array_keys($options))->toContain($this->spn4->id)
        ->and(array_keys($options))->not->toContain($this->tlk1->id);
});

it('lets the governance tier see every rme branch', function (): void {
    $spn4 = lcaLegacyPatient($this->spn4);
    $tlk1 = lcaLegacyPatient($this->tlk1);
    $ldk2 = lcaLegacyPatient($this->ldk2);
    $atg3 = lcaLegacyPatient($this->atg3);

    $visible = lcaVisibleIds(lcaUser('Supervisor RME'));

    expect($visible)->toContain($spn4->id, $tlk1->id, $ldk2->id, $atg3->id);
});

it('lets the governance tier narrow to one branch', function (): void {
    $spn4 = lcaLegacyPatient($this->spn4);
    $tlk1 = lcaLegacyPatient($this->tlk1);

    $visible = lcaVisibleIds(lcaUser('Supervisor RME'), ['branch_id' => $this->spn4->id]);

    expect($visible)->toContain($spn4->id)->and($visible)->not->toContain($tlk1->id);
});

it('fails closed when a branch-scoped actor has no resolvable rme branch', function (): void {
    lcaLegacyPatient($this->spn4);

    // Pinned to MAIN, which is not RME-enabled. An unresolvable scope must
    // yield NOTHING, never the whole estate.
    $admin = lcaUser('Admin Klinik', $this->main);

    expect($this->service->authorizedBranchIds($admin))->toBe([])
        ->and(lcaVisibleIds($admin))->toBe([])
        ->and($this->service->summary($this->service->resolveQuery($admin))['total'])->toBe(0);

    // The page still renders — with an explicit warning rather than a blank
    // table that looks like "there is no backlog".
    lcaGet($admin)->assertOk()->assertSee('Cabang belum dapat ditentukan');
});

it('hides a branchless legacy patient from a pinned actor and shows it to governance', function (): void {
    // A legacy patient with no branch carries no provenance. `branch_id IN (…)`
    // is never true for NULL, so a pinned actor cannot see it at all.
    $branchless = lcaLegacyPatient($this->spn4, ['branch_id' => null]);

    $admin = lcaUser('Admin Klinik', $this->spn4);

    expect(lcaVisibleIds($admin))->not->toContain($branchless->id)
        ->and(lcaVisibleIds(lcaUser('Supervisor RME')))->toContain($branchless->id);
});

/* ------------------------------------------------- the borrowed scope contract */

it('borrows the hub governance set rather than defining its own', function (): void {
    // ONE definition of "governs legacy imports across branches", shared with
    // the sibling hub page in the same module and the same nav group. Two
    // copies would drift, and the copy that drifts is the one that widens
    // somebody.
    $governor = lcaUser('Supervisor RME');
    $admin = lcaUser('Admin Klinik', $this->spn4);

    expect($governor->canAny(LegacyImportHubService::GOVERNANCE_PERMISSIONS))->toBeTrue()
        ->and($this->service->governsEveryBranch($governor))->toBeTrue()
        ->and($admin->canAny(LegacyImportHubService::GOVERNANCE_PERMISSIONS))->toBeFalse()
        ->and($this->service->governsEveryBranch($admin))->toBeFalse();
});

it('keeps an intake operator out of every governance set, including the odontogram one', function (): void {
    // This test used to pin the TRAP itself: it asserted that
    // LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS CONTAINED
    // `create_legacy_odontogram_imports`, and that this page stayed safe only
    // because it did not consult that list. The list was the defect — every
    // other odontogram surface DID consult it, so an Admin Klinik could read
    // every branch's published archive (FIX-LEGACY-ODONTOGRAM-CROSS-BRANCH-
    // READ-SCOPE-1). The set is now review/publish/void, so an intake operator
    // governs nothing on either page.
    $admin = lcaUser('Admin Klinik', $this->spn4);

    expect(LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS)->not->toContain('create_legacy_odontogram_imports')
        ->and($admin->canAny(LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS))->toBeFalse()
        ->and($this->service->governsEveryBranch($admin))->toBeFalse()
        ->and($this->service->authorizedBranchIds($admin))->toBe([$this->spn4->id]);
});

it('keeps the read permission out of every governance set', function (): void {
    // Granting someone the ability to READ this report must never widen their
    // branch scope.
    $permission = LegacyPatientArchiveCompletenessService::READ_PERMISSION;

    expect(LegacyImportHubService::GOVERNANCE_PERMISSIONS)->not->toContain($permission)
        ->and(LegacyRmeWorkspaceScope::GOVERNANCE_PERMISSIONS)->not->toContain($permission)
        ->and(LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS)->not->toContain($permission);
});

/* ------------------------------------------------------------ read-only surface */

it('registers exactly one route for the module, and it is a GET', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'settings.legacy-patient-completeness'));

    expect($routes)->toHaveCount(1);

    $route = $routes->first();

    expect($route->methods())->toBe(['GET', 'HEAD'])
        ->and($route->getName())->toBe('settings.legacy-patient-completeness.index');
});

it('still refuses in the controller when the route middleware is lifted', function (): void {
    // THE DEFENCE-IN-DEPTH LAYER, MADE OBSERVABLE. Every other denial test here
    // is answered by the route's `permission:` middleware, which runs first — so
    // none of them can see the controller's own re-check disappear. Lifting the
    // middleware is the only way to assert that the second layer is real.
    //
    // This matters because a route file is edited far more often than a
    // controller, and a middleware list that silently loses an entry must not
    // silently open a page over patient data.
    $user = lcaUser('Kasir', $this->spn4);

    expect($user->can(LegacyPatientArchiveCompletenessService::READ_PERMISSION))->toBeFalse();

    $this->withoutMiddleware([EnsureRmeOnlineContext::class, PermissionMiddleware::class])
        ->actingAs($user)
        ->get(lcaUrl())
        ->assertForbidden();
});

it('declares the read permission on the route middleware', function (): void {
    // The controller re-checks reachability, so a behavioural test alone cannot
    // see this middleware disappear. This pins the layer itself.
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r): bool => $r->getName() === 'settings.legacy-patient-completeness.index');

    expect($route->gatherMiddleware())
        ->toContain('permission:'.LegacyPatientArchiveCompletenessService::READ_PERMISSION);
});

it('writes nothing when the page is rendered', function (): void {
    $patient = lcaLegacyPatient($this->spn4);
    $admin = lcaUser('Admin Klinik', $this->spn4);

    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        $sql = strtolower(ltrim($query->sql));

        foreach (['insert', 'update', 'delete', 'truncate', 'drop', 'alter'] as $verb) {
            if (str_starts_with($sql, $verb)) {
                $writes[] = $query->sql;
            }
        }
    });

    lcaGet($admin)->assertOk();

    expect($writes)->toBe([]);

    // And nothing changed in the legacy estate either.
    expect(DB::table('stg_rme_legacy_imports')->count())->toBe(0)
        ->and(DB::table('trx_rme_legacy_records')->count())->toBe(0)
        ->and(DB::table('stg_odontogram_legacy_imports')->count())->toBe(0)
        ->and(DB::table('trx_odontogram_legacy_records')->count())->toBe(0)
        ->and(Patient::query()->count())->toBe(1)
        ->and($patient->fresh()->import_batch_id)->not->toBeNull();
});

it('creates no clinical artifact of any kind', function (): void {
    lcaLegacyPatient($this->spn4);

    $before = [
        'visits' => DB::table('trx_clinic_visits')->count(),
        'records' => DB::table('trx_medical_records')->count(),
        'odontograms' => DB::table('trx_odontograms')->count(),
        'invoices' => DB::table('trx_rme_invoices')->count(),
        'payments' => DB::table('trx_rme_payments')->count(),
        'lab_orders' => DB::table('trx_lab_orders')->count(),
        'satusehat' => DB::table('trx_satusehat_candidates')->count(),
    ];

    lcaGet(lcaUser('Supervisor RME'))->assertOk();

    foreach ($before as $table => $count) {
        expect(DB::table(match ($table) {
            'visits' => 'trx_clinic_visits',
            'records' => 'trx_medical_records',
            'odontograms' => 'trx_odontograms',
            'invoices' => 'trx_rme_invoices',
            'payments' => 'trx_rme_payments',
            'lab_orders' => 'trx_lab_orders',
            'satusehat' => 'trx_satusehat_candidates',
        })->count())->toBe($count);
    }
});

/* ---------------------------------------------------------------- PII boundary */

it('renders no sensitive patient field', function (): void {
    $patient = lcaLegacyPatient($this->spn4, [
        'name' => 'Pasien Legacy',
        'medical_record_number' => 'DG-SPN4-2024-0001',
        'ktp_number' => '7371010101900001',
        'phone' => '081200000001',
        'whatsapp_number' => '081200000002',
        'email' => 'rahasia@example.test',
        'address' => 'Jalan Rahasia Nomor 7',
        'occupation' => 'Arsitek',
    ]);

    $response = lcaGet(lcaUser('Supervisor RME'))->assertOk();

    // The identity pair IS disclosed — it is what makes the row actionable.
    $response->assertSee('DG-SPN4-2024-0001')->assertSee('Pasien Legacy');

    // Nothing else is.
    foreach (['7371010101900001', '081200000001', '081200000002', 'rahasia@example.test', 'Jalan Rahasia Nomor 7', 'Arsitek', '1990-01-01'] as $secret) {
        $response->assertDontSee($secret);
    }
});

it('exposes only the agreed property set on a row', function (): void {
    // THE DISCLOSURE BOUNDARY, PINNED. A new property cannot be added to the
    // row object without this assertion failing, which is what stops a future
    // field from reaching the template unreviewed.
    $properties = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(LegacyPatientArchiveRow::class))->getProperties(),
    );

    sort($properties);

    expect($properties)->toBe([
        'branchCode',
        'branchName',
        'completeness',
        'importBatchId',
        'medicalRecordNumber',
        'name',
        'odontogramArchiveDate',
        'odontogramRawStatus',
        'odontogramReviewBlocked',
        'odontogramState',
        'patientId',
        'registeredAt',
        'rmeArchiveDate',
        'rmeRawStatus',
        'rmeReviewBlocked',
        'rmeState',
    ]);
});

it('refuses to read legacy pipeline state for a patient outside the scope, even when handed their id', function (): void {
    // THE PER-PAGE LOOKUPS AUTHORISE, THEY DO NOT TRUST.
    //
    // slotOccupying*Statuses() and reviewBlockedPatientIds() receive explicit
    // patient ids. Today the only caller derives those ids from the already
    // scoped paginator, so re-applying the scope narrows nothing — which is
    // precisely why its absence would be invisible, and why a future row
    // action, drilldown or AJAX refresh taking ids from a request would leak
    // another branch's staging status and reviewer holds with nothing to stop
    // it. The ids say WHICH PAGE; the query says WHICH PATIENTS THIS ACTOR MAY
    // READ AT ALL.
    $foreign = lcaLegacyPatient($this->ldk2, ['medical_record_number' => 'DG-LDK2-2024-0101']);

    $foreignImport = LegacyRmeImport::factory()->create([
        'patient_id' => $foreign->id,
        'origin_branch_id' => $this->ldk2->id,
        'status' => LegacyRmeImportStatus::READY_FOR_REVIEW,
    ]);
    LegacyOdontogramImport::factory()->create([
        'patient_id' => $foreign->id,
        'origin_branch_id' => $this->ldk2->id,
        'status' => LegacyOdontogramImportStatus::PROCESSING,
    ]);
    LegacyReviewTriage::query()->create([
        'import_type' => LegacyImportType::LEGACY_RME,
        'rme_legacy_import_id' => $foreignImport->id,
        'patient_id' => $foreign->id,
        'triage_status' => LegacyReviewTriageStatus::BLOCKED,
        // decided_by / decided_at are NOT NULL: a triage annotation always
        // records the reviewer who raised it.
        'decided_by' => lcaUser('Supervisor RME')->id,
        'decided_at' => now(),
    ]);

    $repository = app(LegacyPatientArchiveCompletenessRepositoryInterface::class);
    $ids = [$foreign->id];

    // A clinic admin pinned to SPN4, handed LDK2's patient id directly.
    $pinned = $this->service->resolveQuery(lcaUser('Admin Klinik', $this->spn4));

    expect($repository->slotOccupyingRmeStatuses($pinned, $ids))->toBe([])
        ->and($repository->slotOccupyingOdontogramStatuses($pinned, $ids))->toBe([])
        ->and($repository->reviewBlockedPatientIds($pinned, $ids))->toBe([
            LegacyImportType::LEGACY_RME => [],
            LegacyImportType::LEGACY_ODONTOGRAM => [],
        ]);

    // NOT VACUOUS: the very same ids DO resolve for an actor whose scope
    // actually covers them, so the empty results above are the scope refusing
    // and not the fixture simply having no rows to find.
    $governance = $this->service->resolveQuery(lcaUser('Supervisor RME'));

    expect($repository->slotOccupyingRmeStatuses($governance, $ids))
        ->toBe([$foreign->id => LegacyRmeImportStatus::READY_FOR_REVIEW])
        ->and($repository->slotOccupyingOdontogramStatuses($governance, $ids))
        ->toBe([$foreign->id => LegacyOdontogramImportStatus::PROCESSING])
        ->and($repository->reviewBlockedPatientIds($governance, $ids)[LegacyImportType::LEGACY_RME])
        ->toBe([$foreign->id]);
});

it('refuses to read legacy pipeline state for a natively registered patient', function (): void {
    // Provenance is re-applied by the same sub-select, so the lookups cannot be
    // used to confirm that a native patient exists either.
    $native = Patient::factory()->create([
        'branch_id' => $this->spn4->id,
        'import_batch_id' => null,
        'date_of_birth' => '1990-01-01',
        'medical_record_number' => 'DG-SPN4-2024-9001',
    ]);

    LegacyRmeImport::factory()->create([
        'patient_id' => $native->id,
        'origin_branch_id' => $this->spn4->id,
        'status' => LegacyRmeImportStatus::READY_FOR_REVIEW,
    ]);

    $repository = app(LegacyPatientArchiveCompletenessRepositoryInterface::class);
    $governance = $this->service->resolveQuery(lcaUser('Supervisor RME'));

    expect($repository->slotOccupyingRmeStatuses($governance, [$native->id]))->toBe([]);
});

it('expresses the scope and the provenance predicate exactly once', function (): void {
    // THE STRUCTURAL GUARANTEE BEHIND EVERY SCOPE TEST ABOVE.
    //
    // The listing, the counters, the branch filter and the per-page lookups all
    // have to mean the same thing by "a legacy patient in my scope". Asserting
    // that on row sets alone cannot see a SECOND, hand-restated copy of the
    // predicates that happens to agree today and stops agreeing after the next
    // edit — a divergence a reader cannot spot and a behavioural test will not
    // catch. So the predicates are counted at the source: one definition, and
    // the one place that applies the branch scope is that definition.
    $source = file_get_contents(
        base_path('app/Modules/LegacyImport/Completeness/Repositories/LegacyPatientArchiveCompletenessRepository.php'),
    );

    expect($source)->toBeString()
        ->and(substr_count($source, "whereNotNull('p.import_batch_id')"))->toBe(1)
        ->and(substr_count($source, "whereNull('p.deleted_at')"))->toBe(1)
        ->and(substr_count($source, '$this->applyBranchScope('))->toBe(1)
        // …and both other surfaces consume it rather than rebuilding it.
        ->and(substr_count($source, '$this->scopedPatients('))->toBeGreaterThanOrEqual(2);
});
