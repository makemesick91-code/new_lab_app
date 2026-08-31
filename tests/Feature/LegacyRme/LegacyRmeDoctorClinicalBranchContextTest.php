<?php

/**
 * LEGACY-RME-PDF-HISTORY-1B — the doctor's canonical clinical BRANCH context.
 *
 * THE PRODUCT DECISION THIS FILE PINS DOWN.
 *
 *   a doctor who genuinely treats the patient
 *   + an origin branch the doctor genuinely practises in
 *   + an already-PUBLISHED legacy record
 *   + migration capability OFF and branch admission EMPTY
 *   = READ ALLOWED
 *
 * WHAT WENT WRONG BEFORE. A doctor's branch was resolved through
 * `BranchContext::forUser()`, which answers "which single branch is this
 * operator working in right now?" — the Sprint 66 online context, then
 * `users.branch_id`, then the user branches relation, then MAIN. Production
 * doctors carry `users.branch_id = NULL`, so with no live online session the
 * answer was MAIN, a branch they have no clinical relationship with. MAIN is
 * never RME-enabled, so the scope came back EMPTY and a treating doctor was
 * refused their own patient's archive. The denial was accidental — it depended
 * on MAIN's module flags, not on the doctor.
 *
 * WHAT IS CANONICAL INSTEAD. `mst_doctor_branches` — the model relation is
 * documented as the "allowed RME practice branches (source of truth for online
 * context)", and `startDoctorSession` refuses any branch outside it. So the
 * doctor's clinical branch context is that assigned set intersected with the
 * active RME-enabled branches: deterministic, session-independent, fail-closed.
 *
 * WHAT DID NOT CHANGE — AND IS RE-PINNED BELOW. Branch membership has never
 * been authorization on its own and still is not. Every read continues through
 * authentication, a named read permission, server-resolved branch scope, the
 * treating relationship (DoctorPatientScopeService), the PUBLISHED status check
 * and the private-disk policy gate. A same-branch doctor who is not treating
 * the patient is refused; a treating doctor outside their practice branches is
 * refused; an unlinked or branchless doctor is refused.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Services\DoctorClinicalBranchResolver;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\LegacyRme\Services\LegacyRmePatientHistoryService;
use App\Modules\LegacyRme\Support\LegacyRmeFeatureGuard;
use App\Modules\LegacyRme\Support\LegacyRmeWorkspaceScope;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses()->group('LegacyRme');

beforeEach(function () {
    seedAccessControl();

    // The exact production posture this sprint has to keep working: migration
    // capability OFF, and no branch admitted to any migration wave.
    legacyRmeArchiveFlag(false);
    legacyRmeAdmittedBranches([]);

    // MAIN is never an RME clinic branch. Leaving it RME-enabled would let the
    // old BranchContext fallback land somewhere valid and mask a real failure.
    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
});

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
*/

function h1bPatient(string $branchCode = 'LDK2'): Patient
{
    return legacyRmeArchivablePatient(['date_of_birth' => '1980-01-01'], $branchCode);
}

function h1bPublished(Patient $patient, array $overrides = []): LegacyRmeRecord
{
    return LegacyRmeRecord::factory()->create(array_merge([
        'patient_id' => $patient->getKey(),
        'origin_branch_id' => $patient->branch_id,
        'rme_date' => '2019-04-17',
        'status' => LegacyRmeRecord::STATUS_PUBLISHED,
        'page_count' => 2,
    ], $overrides));
}

/**
 * A Doctor-role user whose DOCTOR MASTER declares the practice branches, which
 * is what production carries: `users.branch_id` stays NULL exactly as it is for
 * every real doctor account on the pilot.
 *
 * @param  list<Branch|int>  $practiceBranches
 */
function h1bDoctor(array $practiceBranches, ?Patient $treating = null, bool $linked = true): User
{
    // No explicit grant: the seeded Doctor ROLE already carries
    // `view_legacy_rme_archive` (LEGACY-RME-PDF-1D), which is exactly the
    // production shape this sprint has to work under.
    $user = User::factory()->create(['branch_id' => null]);
    $user->assignRole('Doctor');

    if (! $linked) {
        return $user->refresh();
    }

    $ids = collect($practiceBranches)
        ->map(fn ($branch): int => $branch instanceof Branch ? (int) $branch->getKey() : (int) $branch)
        ->all();

    $doctor = Doctor::factory()
        ->withAllowedBranches($ids)
        ->create([
            'user_id' => $user->getKey(),
            'branch_id' => $ids[0] ?? null,
            'is_active' => true,
        ]);

    if ($treating instanceof Patient) {
        PatientDoctorAssignment::factory()->create([
            'patient_id' => $treating->getKey(),
            'doctor_id' => $doctor->getKey(),
            'unassigned_at' => null,
        ]);
    }

    return $user->refresh();
}

function h1bHistory(User $user, Patient $patient): int
{
    return app(LegacyRmePatientHistoryService::class)
        ->publishedRecordsFor($user, (int) $patient->getKey())
        ->count();
}

/*
|--------------------------------------------------------------------------
| CASE A — the primary acceptance: treating doctor, branch they practise in
|--------------------------------------------------------------------------
*/

it('lets a treating doctor read a published archive from a branch they practise in', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $tkm1 = legacyRmeBranch('TLK1', 'Cabang Telkomas');

    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);

    // Production shape: the doctor is standing at TLK1 in principle but also
    // practises at LDK2, where this archive came from, and treats the patient.
    $doctor = h1bDoctor([$tkm1, $ldk2], treating: $patient);

    expect(app(DoctorClinicalBranchResolver::class)->branchIdsFor($doctor))
        ->toContain((int) $ldk2->getKey())
        ->and(app(LegacyRmeWorkspaceScope::class)->branchIdsFor($doctor))
        ->toContain((int) $ldk2->getKey())
        ->and($doctor->can('view', $record))->toBeTrue()
        ->and($doctor->can('viewFile', $record))->toBeTrue()
        ->and(h1bHistory($doctor, $patient))->toBe(1);

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertOk();
});

it('reads with users.branch_id still NULL and no online context at all', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);
    $doctor = h1bDoctor([$ldk2], treating: $patient);

    // The two things production doctors do NOT have.
    expect($doctor->branch_id)->toBeNull()
        ->and(DB::table('trx_user_online_contexts')->where('user_id', $doctor->getKey())->count())->toBe(0)
        ->and($doctor->can('view', $record))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| CASE B — no resolvable branch context → FAIL CLOSED
|--------------------------------------------------------------------------
*/

it('refuses a treating doctor who has no practice branch at all', function () {
    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);
    $doctor = h1bDoctor([], treating: $patient);

    expect(app(DoctorClinicalBranchResolver::class)->branchIdsFor($doctor))->toBe([])
        ->and(app(LegacyRmeWorkspaceScope::class)->branchIdsFor($doctor))->toBe([])
        ->and($doctor->can('view', $record))->toBeFalse()
        ->and(h1bHistory($doctor, $patient))->toBe(0);

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertNotFound();
});

it('refuses a Doctor-role user with no doctor master record', function () {
    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);
    $doctor = h1bDoctor([], linked: false);

    expect(app(DoctorClinicalBranchResolver::class)->branchIdsFor($doctor))->toBe([])
        ->and($doctor->can('view', $record))->toBeFalse();
});

it('refuses a doctor whose master record is inactive', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);
    $doctor = h1bDoctor([$ldk2], treating: $patient);

    Doctor::query()->where('user_id', $doctor->getKey())->update(['is_active' => false]);

    expect(app(DoctorClinicalBranchResolver::class)->branchIdsFor($doctor->refresh()))->toBe([])
        ->and($doctor->can('view', $record))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| CASE C — treating, but the archive is outside their practice branches
|--------------------------------------------------------------------------
*/

it('refuses a treating doctor when the origin branch is not one they practise in', function () {
    $tkm1 = legacyRmeBranch('TLK1', 'Cabang Telkomas');
    legacyRmeBranch('LDK2', 'Cabang Landak');

    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);

    // Treats the patient, but practises only at TLK1 — the archive is LDK2.
    $doctor = h1bDoctor([$tkm1], treating: $patient);

    expect($doctor->can('view', $record))->toBeFalse()
        ->and($doctor->can('viewFile', $record))->toBeFalse()
        ->and(h1bHistory($doctor, $patient))->toBe(0);

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| CASE D — same branch but NOT treating → still DENIED
|--------------------------------------------------------------------------
*/

it('still refuses a doctor who practises in the branch but does not treat the patient', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);

    $stranger = h1bDoctor([$ldk2]); // no treating relationship

    // In scope — so this is genuinely the same-branch case — and refused anyway.
    expect(app(LegacyRmeWorkspaceScope::class)->branchIdsFor($stranger))
        ->toContain((int) $ldk2->getKey())
        ->and($stranger->can('view', $record))->toBeFalse()
        ->and($stranger->can('viewFile', $record))->toBeFalse()
        ->and(h1bHistory($stranger, $patient))->toBe(0);

    // 403 (in scope, not authorized) rather than 404 (outside scope).
    $this->actingAs($stranger)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertForbidden();
});

it('accepts a visit with that doctor as the treating relationship', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);
    $user = h1bDoctor([$ldk2]);

    expect($user->can('view', $record))->toBeFalse();

    ClinicVisit::factory()->create([
        'patient_id' => $patient->getKey(),
        'branch_id' => $ldk2->getKey(),
        'doctor_id' => Doctor::query()->where('user_id', $user->getKey())->value('id'),
    ]);

    expect($user->can('view', $record))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| CASE E — multi-branch doctors resolve to an exact set
|--------------------------------------------------------------------------
*/

it('resolves a multi-branch doctor to exactly their assigned branches', function () {
    $tkm1 = legacyRmeBranch('TLK1', 'Cabang Telkomas');
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $atg3 = legacyRmeBranch('ATG3', 'Cabang Antang');

    $patient = h1bPatient('LDK2');
    $doctor = h1bDoctor([$tkm1, $ldk2], treating: $patient);

    $resolved = app(DoctorClinicalBranchResolver::class)->branchIdsFor($doctor);

    sort($resolved);
    $expected = [(int) $tkm1->getKey(), (int) $ldk2->getKey()];
    sort($expected);

    expect($resolved)->toBe($expected)
        ->and($resolved)->not->toContain((int) $atg3->getKey());
});

it('reads an archive from either of the doctor two practice branches but not a third', function () {
    $tkm1 = legacyRmeBranch('TLK1', 'Cabang Telkomas');
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $atg3 = legacyRmeBranch('ATG3', 'Cabang Antang');

    $patient = h1bPatient('LDK2');
    $doctor = h1bDoctor([$tkm1, $ldk2], treating: $patient);

    $inLdk2 = h1bPublished($patient, ['origin_branch_id' => $ldk2->getKey()]);
    $inTkm1 = h1bPublished($patient, ['origin_branch_id' => $tkm1->getKey()]);
    $inAtg3 = h1bPublished($patient, ['origin_branch_id' => $atg3->getKey()]);

    expect($doctor->can('view', $inLdk2))->toBeTrue()
        ->and($doctor->can('view', $inTkm1))->toBeTrue()
        ->and($doctor->can('view', $inAtg3))->toBeFalse()
        ->and(h1bHistory($doctor, $patient))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| CASE F — a practice branch that is inactive or not RME-enabled drops out
|--------------------------------------------------------------------------
*/

it('drops a practice branch that has been deactivated', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);
    $doctor = h1bDoctor([$ldk2], treating: $patient);

    expect($doctor->can('view', $record))->toBeTrue();

    Branch::whereKey($ldk2->getKey())->update(['is_active' => false]);

    expect(app(DoctorClinicalBranchResolver::class)->branchIdsFor($doctor))->toBe([])
        ->and($doctor->can('view', $record))->toBeFalse();
});

it('drops a practice branch that is no longer RME-enabled', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);
    $doctor = h1bDoctor([$ldk2], treating: $patient);

    Branch::whereKey($ldk2->getKey())->update(['is_rme_enabled' => false]);

    expect(app(DoctorClinicalBranchResolver::class)->branchIdsFor($doctor))->toBe([])
        ->and($doctor->can('view', $record))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| CASE G — a stale online context never widens the practice set
|--------------------------------------------------------------------------
*/

it('ignores an online context branch that is not in the doctor practice set', function () {
    $tkm1 = legacyRmeBranch('TLK1', 'Cabang Telkomas');
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');

    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);
    $doctor = h1bDoctor([$tkm1], treating: $patient);

    // A context row pointing at LDK2 survives after LDK2 was removed from the
    // doctor's practice branches. The pivot is the authority, so it grants
    // nothing: revoking a practice branch revokes the reads with it.
    DB::table('trx_user_online_contexts')->insert([
        'user_id' => $doctor->getKey(),
        'branch_id' => $ldk2->getKey(),
        'clinic_room_id' => null,
        'role_context' => 'DOCTOR',
        'status' => 'ONLINE',
        'online_since' => now(),
        'last_seen_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(DoctorClinicalBranchResolver::class)->branchIdsFor($doctor))
        ->toBe([(int) $tkm1->getKey()])
        ->and($doctor->can('view', $record))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| CASE H — PUBLISHED only
|--------------------------------------------------------------------------
*/

it('shows only PUBLISHED records to an authorized treating doctor', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $patient = h1bPatient('LDK2');
    $doctor = h1bDoctor([$ldk2], treating: $patient);

    $published = h1bPublished($patient);
    $voided = h1bPublished($patient, [
        'status' => LegacyRmeRecord::STATUS_VOID,
        'voided_at' => now(),
        'void_reason' => 'Salah pasien.',
    ]);

    expect(h1bHistory($doctor, $patient))->toBe(1)
        ->and($doctor->can('view', $published))->toBeTrue()
        ->and($doctor->can('viewFile', $voided))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| CASE I — direct-ID attack and patient isolation
|--------------------------------------------------------------------------
*/

it('refuses a direct archive id belonging to another patient in the same branch', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');

    $mine = h1bPatient('LDK2');
    $theirs = h1bPatient('LDK2');

    $otherRecord = h1bPublished($theirs);
    $doctor = h1bDoctor([$ldk2], treating: $mine);

    expect($doctor->can('view', $otherRecord))->toBeFalse()
        ->and(h1bHistory($doctor, $theirs))->toBe(0);

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $otherRecord->getKey()))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| CASE J — the permission is still required
|--------------------------------------------------------------------------
*/

it('refuses a treating doctor in the right branch who lacks the read permission', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);
    $doctor = h1bDoctor([$ldk2], treating: $patient);

    // Take the clinical read permission away at the ROLE (where 1D grants it)
    // and clear Spatie's cache, so the only thing that changed is the named
    // permission — branch scope and the treating relationship both still pass.
    Role::findByName('Doctor')->revokePermissionTo('view_legacy_rme_archive');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($doctor->can('view', $record))->toBeFalse();

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| CASE K — print and export follow exactly the same boundary
|--------------------------------------------------------------------------
*/

it('lets the authorized treating doctor print and refuses the non-treating one', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);

    $treating = h1bDoctor([$ldk2], treating: $patient);
    $stranger = h1bDoctor([$ldk2]);

    $this->actingAs($treating)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.print', $record->getKey()))
        ->assertOk();

    $this->actingAs($stranger)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.print', $record->getKey()))
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| CASE L — non-doctor actors are untouched by this change
|--------------------------------------------------------------------------
*/

it('leaves a non-doctor operator pinned to their own branch context', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $tkm1 = legacyRmeBranch('TLK1', 'Cabang Telkomas');

    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);

    $operator = userWith(['view_legacy_rme_archive']);
    $operator->forceFill(['branch_id' => $ldk2->getKey()])->save();

    expect(app(LegacyRmeWorkspaceScope::class)->branchIdsFor($operator->refresh()))
        ->toBe([(int) $ldk2->getKey()])
        ->and($operator->can('view', $record))->toBeTrue();

    $elsewhere = userWith(['view_legacy_rme_archive']);
    $elsewhere->forceFill(['branch_id' => $tkm1->getKey()])->save();

    expect($elsewhere->refresh()->can('view', $record))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| CASE M — reading changes nothing
|--------------------------------------------------------------------------
*/

it('creates no clinical or legacy rows while a doctor reads the archive', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);
    $doctor = h1bDoctor([$ldk2], treating: $patient);

    $tables = [
        'trx_clinic_visits',
        'trx_medical_records',
        'trx_rme_invoices',
        'trx_rme_payments',
        'trx_lab_orders',
        'trx_lab_case_candidates',
        'trx_satusehat_candidates',
        'trx_rme_legacy_records',
        'stg_rme_legacy_imports',
    ];

    $before = collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])->all();

    $this->actingAs($doctor)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('rme.legacy-records.show', $record->getKey()))
        ->assertOk();

    h1bHistory($doctor, $patient);

    $after = collect($tables)->mapWithKeys(fn (string $t): array => [$t => DB::table($t)->count()])->all();

    expect($after)->toBe($before);
});

/*
|--------------------------------------------------------------------------
| CASE N — migration stays off; this sprint grants no mutation
|--------------------------------------------------------------------------
*/

it('keeps the migration capability off and admission empty while the doctor reads', function () {
    $ldk2 = legacyRmeBranch('LDK2', 'Cabang Landak');
    $patient = h1bPatient('LDK2');
    $record = h1bPublished($patient);
    $doctor = h1bDoctor([$ldk2], treating: $patient);

    expect($doctor->can('view', $record))->toBeTrue()
        ->and(app(LegacyRmeFeatureGuard::class)->enabled())->toBeFalse();

    // The read permission never carries a mutation ability with it.
    expect($doctor->can('create_legacy_rme_imports'))->toBeFalse()
        ->and($doctor->can('review_legacy_rme_imports'))->toBeFalse()
        ->and($doctor->can('publish_legacy_rme_imports'))->toBeFalse()
        ->and($doctor->can('void_legacy_rme_imports'))->toBeFalse()
        ->and($doctor->can('void', $record))->toBeFalse();
});
