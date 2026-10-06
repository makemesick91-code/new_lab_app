<?php

/**
 * FIX-LEGACY-ODONTOGRAM-CROSS-BRANCH-READ-SCOPE-1.
 *
 * UPLOAD AUTHORITY != GLOBAL READ AUTHORITY.
 *
 * `create_legacy_odontogram_imports` used to sit in
 * LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS, so every intake
 * operator (Admin Klinik, Front Office) resolved to the WHOLE RME branch set and
 * could list, open and stream another branch's published odontogram archive —
 * through the record viewer, the patient history, the unified medical-record
 * index and the staging-import viewer alike, because all of them consume that
 * one scope.
 *
 * The fix is one membership change, so every surface moves together. These
 * tests are written against REAL roles and REAL online contexts: a fixture that
 * merely pinned `users.branch_id` would prove the scope arithmetic and nothing
 * about the branch a front-desk operator actually works at.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramImport;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyOdontogram\Policies\LegacyOdontogramRecordPolicy;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPatientHistoryService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramProcessingService;
use App\Modules\LegacyOdontogram\Services\LegacyOdontogramPublishService;
use App\Modules\LegacyOdontogram\Support\LegacyOdontogramWorkspaceScope;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfInspectorInterface;
use App\Modules\LegacyRme\Interfaces\LegacyRmePdfRasterizerInterface;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfInspector;
use App\Modules\LegacyRme\Services\Pdf\FakeLegacyRmePdfRasterizer;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use App\Modules\MedicalRecord\Services\UnifiedMedicalRecordIndexService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    seedAccessControl();
    lodoFlag(true);
    Storage::fake('legacy_odontogram_private');
    Bus::fake();

    // MAIN is never an RME clinic branch, and production HAS one (id 4). Without
    // it BranchContext's documented fallback lands on the first active branch,
    // which would let an unpinned operator fall back INTO scope and hide a real
    // failure — so the fixture mirrors production.
    $main = Branch::withTrashed()->firstOrNew(['code' => Branch::MAIN_CODE]);
    $main->forceFill([
        'name' => $main->exists ? $main->name : 'Kantor Pusat',
        'is_active' => true,
        'is_rme_enabled' => false,
        'deleted_at' => null,
    ])->save();

    $this->spn4 = lodoBranch('SPN4', 'Cabang Sunu');
    $this->tlk1 = lodoBranch('TLK1', 'Cabang Telkomas');
    $this->ldk2 = lodoBranch('LDK2', 'Cabang Landak');
    $this->atg3 = lodoBranch('ATG3', 'Cabang Antang');
});

/**
 * A PUBLISHED archive driven through the real intake → process → review →
 * publish pipeline, so the record has a staging import, private bytes and
 * rendered pages — every door a reader could try.
 *
 * @return array{record: LegacyOdontogramRecord, import: LegacyOdontogramImport, patient: Patient}
 */
function xbPublished(string $branchCode): array
{
    app()->instance(LegacyRmePdfInspectorInterface::class, (new FakeLegacyRmePdfInspector)->withPages(1));
    app()->instance(LegacyRmePdfRasterizerInterface::class, (new FakeLegacyRmePdfRasterizer)->withPages(1));

    $patient = lodoPatient([], $branchCode);
    $actor = lodoOperator(); // governance tier: fixture creation only

    $import = lodoStageImport($patient, '2019-06-01', $actor);
    app(LegacyOdontogramProcessingService::class)->process((int) $import->getKey());

    $import->refresh();
    app(LegacyOdontogramPublishService::class)->review($import, $actor);
    $record = app(LegacyOdontogramPublishService::class)->publish($import->refresh(), [], $actor);

    return ['record' => $record, 'import' => $import->refresh(), 'patient' => $patient];
}

/** An Admin Klinik with a real online context at `$branch`. */
function xbAdminKlinik(Branch $branch): User
{
    $user = User::factory()->create();
    rmeMakeAdminClinicActive($user, $branch);

    return $user->refresh();
}

/** A Front Office account with a real (admin-clinic) online context at `$branch`. */
function xbFrontOffice(Branch $branch): User
{
    $user = User::factory()->create();
    rmeMakeFrontOfficeActive($user, $branch);

    return $user->refresh();
}

/* ------------------------------------------------------------- the root cause */

it('no longer treats the upload permission as a governance permission', function () {
    expect(LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS)
        ->not->toContain('create_legacy_odontogram_imports')
        ->not->toContain('view_legacy_odontogram_imports')
        ->not->toContain('view_legacy_odontogram_archive');
});

it('keeps exact governance parity with the legacy RME archive', function () {
    // Same shape, same duties, separate membership: review / publish / void.
    $strip = fn (array $permissions) => collect($permissions)
        ->map(fn (string $p) => preg_replace('/_legacy_(rme|odontogram)_(imports|records)$/', '', $p))
        ->sort()->values()->all();

    expect($strip(LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS))
        ->toBe($strip(LegacyRmeWorkspaceScope::GOVERNANCE_PERMISSIONS));
});

it('pins Admin Klinik and Front Office to their own effective branch', function () {
    $scope = app(LegacyOdontogramWorkspaceScope::class);

    foreach ([xbAdminKlinik($this->spn4), xbFrontOffice($this->spn4)] as $actor) {
        expect($actor->can('create_legacy_odontogram_imports'))->toBeTrue()
            ->and($scope->branchIdsFor($actor))->toBe([(int) $this->spn4->id])
            ->and($scope->includesUnscopedRowsFor($actor))->toBeFalse()
            ->and($scope->allows($actor, (int) $this->spn4->id))->toBeTrue();

        foreach ([$this->tlk1, $this->ldk2, $this->atg3] as $foreign) {
            expect($scope->allows($actor, (int) $foreign->id))->toBeFalse();
        }

        expect($scope->allows($actor, (int) Branch::where('code', Branch::MAIN_CODE)->value('id')))->toBeFalse()
            ->and($scope->allows($actor, null))->toBeFalse();
    }
});

it('preserves the cross-branch scope of the review/publish governance tier', function () {
    $scope = app(LegacyOdontogramWorkspaceScope::class);
    $rme = collect([$this->spn4, $this->tlk1, $this->ldk2, $this->atg3])->map(fn ($b) => (int) $b->id);

    $supervisor = userInRole('Supervisor RME');

    expect($rme->diff($scope->branchIdsFor($supervisor)))->toBeEmpty()
        ->and($rme->diff($scope->branchIdsFor(superAdmin())))->toBeEmpty()
        ->and($scope->includesUnscopedRowsFor($supervisor))->toBeTrue();
});

it('fails closed on an empty scope rather than reading it as unrestricted', function () {
    // An Admin Klinik with no online context resolves to MAIN, which is not an
    // RME branch: the scope is EMPTY and must match nothing.
    $actor = User::factory()->create();
    $actor->assignRole('Admin Klinik');
    $actor = $actor->refresh();

    $theirs = xbPublished('TLK1');
    $scope = app(LegacyOdontogramWorkspaceScope::class);

    expect($scope->branchIdsFor($actor))->toBe([])
        ->and(app(LegacyOdontogramPatientHistoryService::class)
            ->publishedRecordsFor($actor, (int) $theirs['patient']->id))->toHaveCount(0)
        ->and($actor->can('view', $theirs['record']))->toBeFalse();
});

it('never widens an unpinned intake operator past one branch, even without a MAIN branch', function () {
    // BranchContext's documented last resort is "first active branch". This
    // sprint does not change that chain; what must hold is that an intake
    // permission can no longer turn that ONE fallback branch into ALL of them.
    Branch::where('code', Branch::MAIN_CODE)->forceDelete();

    $actor = User::factory()->create();
    $actor->assignRole('Front Office');

    expect(count(app(LegacyOdontogramWorkspaceScope::class)->branchIdsFor($actor->refresh())))
        ->toBeLessThanOrEqual(1);
});

/* --------------------------------------------------------- the direct viewer */

it('opens an own-branch archive and its bytes for an Admin Klinik', function () {
    $mine = xbPublished('SPN4');
    $actor = xbAdminKlinik($this->spn4);

    $this->actingAs($actor)->get(route('rme.legacy-odontograms.show', $mine['record']->getKey()))->assertOk();
    $this->actingAs($actor)->get(route('rme.legacy-odontograms.source', $mine['record']->getKey()))->assertOk();
    $this->actingAs($actor)->get(route('rme.legacy-odontograms.pages.show', [$mine['record']->getKey(), 1]))->assertOk();
});

it('answers 404 — never 403 — for every door into a foreign-branch archive', function (string $role) {
    $actor = $role === 'fo' ? xbFrontOffice($this->spn4) : xbAdminKlinik($this->spn4);

    foreach (['TLK1', 'LDK2', 'ATG3'] as $code) {
        $theirs = xbPublished($code);
        $id = $theirs['record']->getKey();

        $this->actingAs($actor)->get(route('rme.legacy-odontograms.show', $id))->assertNotFound();
        $this->actingAs($actor)->get(route('rme.legacy-odontograms.source', $id))->assertNotFound();
        $this->actingAs($actor)->get(route('rme.legacy-odontograms.pages.show', [$id, 1]))->assertNotFound();

        // A crafted branch filter is not an authority.
        $this->actingAs($actor)
            ->get(route('rme.legacy-odontograms.show', $id).'?branch_id='.$theirs['patient']->branch_id)
            ->assertNotFound();

        expect($actor->can('view', $theirs['record']))->toBeFalse()
            ->and($actor->can('viewFile', $theirs['record']))->toBeFalse();
    }
})->with(['admin klinik' => 'ak', 'front office' => 'fo']);

it('denies an archive that resolves to MAIN', function () {
    $theirs = xbPublished('TLK1');
    $theirs['record']->forceFill(['branch_id' => Branch::where('code', Branch::MAIN_CODE)->value('id')])->save();

    $this->actingAs(xbAdminKlinik($this->spn4))
        ->get(route('rme.legacy-odontograms.show', $theirs['record']->getKey()))
        ->assertNotFound();
});

it('closes the staging-import door to a foreign published archive (source-import IDOR)', function () {
    $theirs = xbPublished('TLK1');
    $actor = xbAdminKlinik($this->spn4);
    $importId = $theirs['import']->getKey();

    // The controller's branch-scoped resolve() answers HTTP first; the POLICY is
    // the authority for every non-HTTP caller (batch adapters, services), so it
    // must refuse on its own rather than lean on that earlier layer.
    expect($actor->can('view', $theirs['import']))->toBeFalse()
        ->and($actor->can('viewFile', $theirs['import']))->toBeFalse()
        ->and($actor->can('cancel', $theirs['import']))->toBeFalse();

    $this->actingAs($actor)->get(route('settings.rme.legacy-odontograms.show', $importId))->assertNotFound();
    $this->actingAs($actor)->get(route('settings.rme.legacy-odontograms.pages.show', [$importId, 1]))->assertNotFound();

    $this->actingAs($actor)
        ->get(route('settings.rme.legacy-odontograms.index'))
        ->assertOk()
        ->assertDontSee($theirs['patient']->medical_record_number);
});

it('withholds a foreign patient archive status and native date on the upload page', function () {
    // Patient lookup is global, so the upload page finds a TLK1 patient for an
    // SPN4 operator. It must not then describe that patient's ARCHIVE — whether
    // one exists, or the native date that bounds it — nor claim it is absent.
    $theirs = xbPublished('TLK1');
    lodoNativeOdontogram($theirs['patient'], '2022-03-10');

    $mine = xbPublished('SPN4');
    lodoNativeOdontogram($mine['patient'], '2022-03-10');

    $actor = xbAdminKlinik($this->spn4);

    $this->actingAs($actor)
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $theirs['patient']->medical_record_number]))
        ->assertOk()
        ->assertDontSee('Legacy Odontogram sudah tersedia')
        ->assertDontSee('10-03-2022')
        ->assertDontSee('Pasien belum pernah diperiksa di sistem')
        ->assertSee('Ditampilkan setelah cabang arsip dapat ditentukan.');

    // Control: the same facts ARE shown for the operator's own branch.
    $this->actingAs($actor)
        ->get(route('settings.rme.legacy-odontograms.create', ['rm' => $mine['patient']->medical_record_number]))
        ->assertOk()
        ->assertSee('Legacy Odontogram sudah tersedia')
        ->assertSee('10-03-2022');
});

/* ------------------------------------------------ list surfaces agree with the viewer */

it('hides a foreign archive from the patient history while keeping the own one', function () {
    $mine = xbPublished('SPN4');
    $theirs = xbPublished('TLK1');
    $actor = xbFrontOffice($this->spn4);
    $history = app(LegacyOdontogramPatientHistoryService::class);

    expect($history->publishedRecordsFor($actor, (int) $mine['patient']->id))->toHaveCount(1)
        ->and($history->publishedRecordsFor($actor, (int) $theirs['patient']->id))->toHaveCount(0);
});

it('never lets the unified medical-record index surface a foreign legacy-odontogram-only patient', function () {
    $mine = xbPublished('SPN4');
    $theirs = xbPublished('TLK1');
    $actor = xbAdminKlinik($this->spn4);

    $scope = app(UnifiedMedicalRecordIndexService::class)->scopeFor($actor);

    expect($scope->legacyOdontogramBranchIds)->toBe([(int) $this->spn4->id])
        ->and($scope->legacyOdontogramIncludesUnscoped)->toBeFalse();

    $this->actingAs($actor)
        ->get(route('rme.medical-records.index'))
        ->assertOk()
        ->assertSee($mine['patient']->medical_record_number)
        ->assertDontSee($theirs['patient']->medical_record_number);
});

it('lists exactly what the viewer will open (no split brain)', function () {
    $records = collect(['SPN4', 'TLK1', 'LDK2', 'ATG3'])->map(fn ($c) => xbPublished($c));
    $history = app(LegacyOdontogramPatientHistoryService::class);

    foreach ([xbAdminKlinik($this->spn4), xbFrontOffice($this->spn4), userInRole('Supervisor RME')] as $actor) {
        foreach ($records as $r) {
            $listed = $history->publishedRecordsFor($actor, (int) $r['patient']->id)->isNotEmpty();
            expect($listed)->toBe($actor->can('view', $r['record']));
        }
    }
});

/* ----------------------------------------------------- upload is not narrowed for own branch */

it('still lets a branch intake operator upload for their own branch', function () {
    $actor = xbFrontOffice($this->spn4);
    $import = lodoStageImport(lodoPatient([], 'SPN4'), '2019-06-01', $actor);

    expect($import->origin_branch_id)->toBe((int) $this->spn4->id)
        ->and($actor->can('view', $import))->toBeTrue();
});

it('refuses a branch intake operator filing another branch history, as the binding service always intended', function () {
    $actor = xbAdminKlinik($this->spn4);

    expect(fn () => lodoStageImport(lodoPatient([], 'TLK1'), '2019-06-01', $actor))
        ->toThrow(ValidationException::class);

    expect(LegacyOdontogramImport::count())->toBe(0);
});

/* --------------------------------------------------------------------- doctors */

/** A Doctor-role reader whose practice branch is SPN4. */
function xbDoctor(Branch $branch): array
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole('Doctor');
    $user->givePermissionTo('view_legacy_odontogram_archive');

    $doctor = Doctor::factory()->create([
        'user_id' => $user->getKey(),
        'branch_id' => $branch->id,
        'is_active' => true,
    ]);

    return [$user->refresh(), $doctor];
}

it('keeps a doctor non-widening: same branch alone and a soft-deleted visit both grant nothing', function () {
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);

    $archive = xbPublished('SPN4');
    [$user, $doctor] = xbDoctor($this->spn4);

    // Same branch, never treated.
    expect($user->can('view', $archive['record']))->toBeFalse();
    $this->actingAs($user)->get(route('rme.legacy-odontograms.show', $archive['record']->getKey()))->assertForbidden();

    // Only relationship is a SOFT-DELETED visit.
    $visit = ClinicVisit::factory()->create([
        'patient_id' => $archive['patient']->id,
        'doctor_id' => $doctor->id,
        'branch_id' => $this->spn4->id,
    ]);
    $visit->delete();

    expect($user->fresh()->can('view', $archive['record']))->toBeFalse()
        ->and(app(LegacyOdontogramPatientHistoryService::class)
            ->publishedRecordsFor($user->fresh(), (int) $archive['patient']->id))->toHaveCount(0);

    // A real, live visit establishes the relationship.
    $visit->restore();

    expect($user->fresh()->can('view', $archive['record']))->toBeTrue();
});

it('never lets a soft-deleted visit surface a patient to a doctor in the unified index', function () {
    // The policy path checks visits through Eloquent (SoftDeletes excludes
    // trashed rows); the unified index uses the SQL patient scope, whose
    // whereNull('v.deleted_at') is a separate statement. Both must agree.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);

    $archive = xbPublished('SPN4');
    [$user, $doctor] = xbDoctor($this->spn4);

    $visit = ClinicVisit::factory()->create([
        'patient_id' => $archive['patient']->id,
        'doctor_id' => $doctor->id,
        'branch_id' => $this->spn4->id,
    ]);
    $visit->delete();

    $this->actingAs($user->fresh())
        ->get(route('rme.medical-records.index'))
        ->assertOk()
        ->assertDontSee($archive['patient']->medical_record_number);

    // Control: the live visit DOES surface it, so the absence above is the
    // soft-delete rule and not an empty page.
    $visit->restore();

    $this->actingAs($user->fresh())
        ->get(route('rme.medical-records.index'))
        ->assertOk()
        ->assertSee($archive['patient']->medical_record_number);
});

it('keeps a treating doctor out of a foreign-branch archive', function () {
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);

    $theirs = xbPublished('TLK1');
    [$user, $doctor] = xbDoctor($this->spn4);

    ClinicVisit::factory()->create([
        'patient_id' => $theirs['patient']->id,
        'doctor_id' => $doctor->id,
        'branch_id' => $this->tlk1->id,
    ]);

    $this->actingAs($user->fresh())
        ->get(route('rme.legacy-odontograms.show', $theirs['record']->getKey()))
        ->assertNotFound();
});

it('keeps both read permissions out of the governance tier', function () {
    foreach (LegacyOdontogramRecordPolicy::READ_PERMISSIONS as $permission) {
        expect(LegacyOdontogramWorkspaceScope::GOVERNANCE_PERMISSIONS)->not->toContain($permission);
    }
});
