<?php

declare(strict_types=1);

/*
 * FEATURE-RME-MEDICAL-RECORDS-UNIFIED-NATIVE-LEGACY-1
 *
 * /rme/medical-records is a unified, patient-centric clinical READ index: one
 * row per patient holding a native medical record OR a PUBLISHED legacy RME OR
 * a PUBLISHED legacy odontogram the actor may read. Native and legacy storage
 * stay separate — nothing here may create a visit or a medical record.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LegacyOdontogram\Models\LegacyOdontogramRecord;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Models\LegacyRmeRecord;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\MedicalRecord\Support\UnifiedMedicalRecordScope;
use App\Modules\Patient\Models\Patient;
use App\Modules\RME\Models\PatientDoctorAssignment;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedAccessControl();

    $this->branchA = Branch::factory()->create(['code' => 'UMA1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->branchB = Branch::factory()->create(['code' => 'UMB2', 'is_active' => true, 'is_rme_enabled' => true]);
});

/** Reads every legacy archive in every RME branch (governance tier) + native list. */
function umiGovernor(): User
{
    return userWith([
        'view_clinic_visits',
        'view_legacy_rme_imports', 'review_legacy_rme_imports',
        'view_legacy_odontogram_imports', 'review_legacy_odontogram_imports',
    ]);
}

function umiPatient(Branch $branch, string $name): Patient
{
    return Patient::factory()->create(['name' => $name, 'branch_id' => $branch->id]);
}

function umiNative(Patient $patient, Branch $branch, string $status = MedicalRecord::STATUS_DRAFT): MedicalRecord
{
    return MedicalRecord::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'status' => $status,
    ]);
}

function umiLegacyRme(Patient $patient, Branch $branch, string $status = LegacyRmeRecord::STATUS_PUBLISHED): LegacyRmeRecord
{
    return LegacyRmeRecord::factory()->create([
        'patient_id' => $patient->id,
        'origin_branch_id' => $branch->id,
        'status' => $status,
    ]);
}

function umiLegacyOdontogram(Patient $patient, Branch $branch, string $status = LegacyOdontogramRecord::STATUS_PUBLISHED): LegacyOdontogramRecord
{
    return LegacyOdontogramRecord::factory()->create([
        'patient_id' => $patient->id,
        'branch_id' => $branch->id,
        'status' => $status,
    ]);
}

/** @return list<int> */
function umiListedIds(User $user, array $query = []): array
{
    $response = test()->actingAs($user)->get(route('rme.medical-records.index', $query));
    $response->assertOk();

    return $response->viewData('patients')->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
}

// --------------------------------------------------------------------- list

it('lists a native-only patient', function () {
    $p = umiPatient($this->branchA, 'Native Saja');
    umiNative($p, $this->branchA);

    expect(umiListedIds(umiGovernor()))->toBe([$p->id]);
});

it('lists a legacy-RME-only patient with zero native records', function () {
    $p = umiPatient($this->branchA, 'Legacy RME Saja');
    umiLegacyRme($p, $this->branchA);

    expect(MedicalRecord::where('patient_id', $p->id)->count())->toBe(0)
        ->and(umiListedIds(umiGovernor()))->toBe([$p->id]);
});

it('lists a legacy-odontogram-only patient', function () {
    $p = umiPatient($this->branchA, 'Odonto Saja');
    umiLegacyOdontogram($p, $this->branchA);

    expect(umiListedIds(umiGovernor()))->toBe([$p->id]);
});

it('emits exactly one row per patient whatever the combination of sources', function () {
    $both = umiPatient($this->branchA, 'Dua Legacy');
    umiLegacyRme($both, $this->branchA);
    umiLegacyOdontogram($both, $this->branchA);

    $nativeRme = umiPatient($this->branchA, 'Native Dan RME');
    umiNative($nativeRme, $this->branchA);
    umiNative($nativeRme, $this->branchA);
    umiLegacyRme($nativeRme, $this->branchA);

    $nativeOdo = umiPatient($this->branchA, 'Native Dan Odonto');
    umiNative($nativeOdo, $this->branchA);
    umiLegacyOdontogram($nativeOdo, $this->branchA);

    $all = umiPatient($this->branchA, 'Semua Sumber');
    umiNative($all, $this->branchA);
    umiLegacyRme($all, $this->branchA);
    umiLegacyOdontogram($all, $this->branchA);

    $ids = umiListedIds(umiGovernor());

    expect($ids)->toHaveCount(4)
        ->and(array_unique($ids))->toHaveCount(4)
        ->and($ids)->toEqualCanonicalizing([$both->id, $nativeRme->id, $nativeOdo->id, $all->id]);
});

it('never treats a staging-only or cancelled legacy import as a clinical record', function () {
    $p = umiPatient($this->branchA, 'Hanya Staging');
    LegacyRmeImport::factory()->create(['patient_id' => $p->id, 'origin_branch_id' => $this->branchA->id]);

    expect(umiListedIds(umiGovernor()))->toBe([]);
});

it('does not list a patient whose only legacy record is VOID', function () {
    $p = umiPatient($this->branchA, 'Hanya Void');
    umiLegacyRme($p, $this->branchA, LegacyRmeRecord::STATUS_VOID);
    umiLegacyOdontogram($p, $this->branchA, LegacyOdontogramRecord::STATUS_VOID);

    expect(umiListedIds(umiGovernor()))->toBe([]);
});

it('lists a patient whose VOID record was replaced by a fresh published import', function () {
    $p = umiPatient($this->branchA, 'Void Diganti');
    umiLegacyRme($p, $this->branchA, LegacyRmeRecord::STATUS_VOID);
    umiLegacyRme($p, $this->branchA);

    expect(umiListedIds(umiGovernor()))->toBe([$p->id]);
});

it('does not list a patient with no native and no published legacy record', function () {
    umiPatient($this->branchA, 'Tanpa Rekam Medis');

    expect(umiListedIds(umiGovernor()))->toBe([]);
});

it('never lists a soft-deleted patient', function () {
    $p = umiPatient($this->branchA, 'Pasien Terhapus');
    umiLegacyRme($p, $this->branchA);
    $p->delete();

    $user = umiGovernor();
    $response = $this->actingAs($user)->get(route('rme.medical-records.index'));

    // Mutation M5: hydration alone would still drop the row, so the total and
    // the workspace are what prove the eligibility query excludes it.
    expect($response->viewData('patients')->getCollection()->all())->toBe([])
        ->and($response->viewData('patients')->total())->toBe(0)
        ->and($response->viewData('summary')['total'])->toBe(0);

    $this->actingAs($user)->get(route('rme.medical-records.patients.show', $p->id))->assertNotFound();
});

// ------------------------------------------------------------------- filters

it('filters by source with honest totals and no duplicates', function () {
    $native = umiPatient($this->branchA, 'F Native');
    umiNative($native, $this->branchA);

    $legacyRme = umiPatient($this->branchA, 'F Legacy RME');
    umiLegacyRme($legacyRme, $this->branchA);

    $legacyOdo = umiPatient($this->branchA, 'F Legacy Odo');
    umiLegacyOdontogram($legacyOdo, $this->branchA);

    $both = umiPatient($this->branchA, 'F Keduanya');
    umiNative($both, $this->branchA);
    umiLegacyRme($both, $this->branchA);
    umiLegacyOdontogram($both, $this->branchA);

    $user = umiGovernor();

    expect(umiListedIds($user, ['source' => 'all']))->toEqualCanonicalizing([$native->id, $legacyRme->id, $legacyOdo->id, $both->id])
        ->and(umiListedIds($user, ['source' => 'native']))->toEqualCanonicalizing([$native->id, $both->id])
        ->and(umiListedIds($user, ['source' => 'legacy']))->toEqualCanonicalizing([$legacyRme->id, $legacyOdo->id, $both->id])
        ->and(umiListedIds($user, ['source' => 'native_legacy']))->toBe([$both->id])
        ->and(umiListedIds($user, ['source' => 'legacy_rme']))->toEqualCanonicalizing([$legacyRme->id, $both->id])
        ->and(umiListedIds($user, ['source' => 'legacy_odontogram']))->toEqualCanonicalizing([$legacyOdo->id, $both->id]);

    $totalFor = fn (string $source) => $this->actingAs($user)
        ->get(route('rme.medical-records.index', ['source' => $source]))
        ->viewData('patients')->total();

    // Mutation M11: the paginator total must follow the source filter too.
    expect($totalFor('native'))->toBe(2)
        ->and($totalFor('legacy'))->toBe(3)
        ->and($totalFor('native_legacy'))->toBe(1)
        ->and($totalFor('legacy_odontogram'))->toBe(2);

    $summary = $this->actingAs($user)->get(route('rme.medical-records.index'))->viewData('summary');

    expect($summary)->toBe(['total' => 4, 'native' => 2, 'legacy' => 3, 'native_and_legacy' => 1]);
});

it('treats an unknown source value as all', function () {
    $p = umiPatient($this->branchA, 'Sumber Aneh');
    umiLegacyRme($p, $this->branchA);

    expect(umiListedIds(umiGovernor(), ['source' => "x' OR 1=1 --"]))->toBe([$p->id]);
});

it('paginates after the union so totals are exact and pages never overlap', function () {
    $user = umiGovernor();
    $expected = [];

    foreach (range(1, 10) as $i) {
        $native = umiPatient($this->branchA, 'Native '.$i);
        umiNative($native, $this->branchA);
        $legacy = umiPatient($this->branchA, 'Legacy '.$i);
        umiLegacyRme($legacy, $this->branchA);
        $expected[] = $native->id;
        $expected[] = $legacy->id;
    }

    $first = $this->actingAs($user)->get(route('rme.medical-records.index'))->viewData('patients');
    $second = $this->actingAs($user)->get(route('rme.medical-records.index', ['page' => 2]))->viewData('patients');

    $seen = array_merge(
        $first->getCollection()->pluck('id')->all(),
        $second->getCollection()->pluck('id')->all(),
    );

    expect($first->total())->toBe(20)
        ->and($seen)->toHaveCount(20)
        ->and(array_unique($seen))->toHaveCount(20)
        ->and($seen)->toEqualCanonicalizing($expected);
});

it('orders patients by their latest record across sources, newest first', function () {
    $old = umiPatient($this->branchA, 'Paling Lama');
    umiNative($old, $this->branchA)->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();

    $newest = umiPatient($this->branchA, 'Paling Baru Legacy');
    umiLegacyRme($newest, $this->branchA)->forceFill(['created_at' => now()->subDay()])->saveQuietly();

    $middle = umiPatient($this->branchA, 'Tengah Odonto');
    umiLegacyOdontogram($middle, $this->branchA)->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

    expect(umiListedIds(umiGovernor()))->toBe([$newest->id, $middle->id, $old->id]);
});

it('searches unified patients by name and by medical record number', function () {
    $legacyOnly = Patient::factory()->create(['name' => 'Wahyu Arsip', 'branch_id' => $this->branchA->id, 'medical_record_number' => 'DG-UMA1-2024-7777']);
    umiLegacyRme($legacyOnly, $this->branchA);
    $native = umiPatient($this->branchA, 'Budi Native');
    umiNative($native, $this->branchA);

    $user = umiGovernor();

    expect(umiListedIds($user, ['search' => 'wahyu']))->toBe([$legacyOnly->id])
        ->and(umiListedIds($user, ['search' => '2024-7777']))->toBe([$legacyOnly->id])
        ->and(umiListedIds($user, ['search' => 'budi']))->toBe([$native->id]);
});

it('narrows to patients with a matching native record when a native-only filter is set', function () {
    $final = umiPatient($this->branchA, 'Final Native');
    umiNative($final, $this->branchA, MedicalRecord::STATUS_FINAL);
    $legacy = umiPatient($this->branchA, 'Legacy Tanpa Native');
    umiLegacyRme($legacy, $this->branchA);

    expect(umiListedIds(umiGovernor(), ['status' => MedicalRecord::STATUS_FINAL]))->toBe([$final->id]);
});

it('ignores an unparseable visit date instead of passing it to SQL', function () {
    $p = umiPatient($this->branchA, 'Tanggal Rusak');
    umiLegacyRme($p, $this->branchA);

    expect(umiListedIds(umiGovernor(), ['visit_date_from' => '2026-02-31']))->toBe([$p->id]);
});

// -------------------------------------------------------------- authorization

it('lists no legacy-only patient to an actor without a legacy read permission', function () {
    $legacy = umiPatient($this->branchA, 'Tidak Terlihat');
    umiLegacyRme($legacy, $this->branchA);
    umiLegacyOdontogram($legacy, $this->branchA);
    $native = umiPatient($this->branchA, 'Terlihat Native');
    umiNative($native, $this->branchA);

    $nativeReader = userWith(['view_clinic_visits']);

    $response = $this->actingAs($nativeReader)->get(route('rme.medical-records.index'));
    $response->assertOk()->assertDontSee('Tidak Terlihat')->assertSee('Terlihat Native');

    expect($response->viewData('patients')->getCollection()->pluck('id')->all())->toBe([$native->id]);
});

it('denies legacy inclusion on the permission alone, even when the branch scope would cover it', function () {
    // Mutation M4 survived without this case: the previous test's actor
    // resolved to a DIFFERENT branch, so branch scope masked a missing
    // permission check. Here the branch matches — only the permission refuses.
    $legacy = umiPatient($this->branchA, 'Cabang Cocok Tanpa Izin');
    umiLegacyRme($legacy, $this->branchA);
    umiLegacyOdontogram($legacy, $this->branchA);

    $reader = userWith(['view_clinic_visits']);
    $reader->forceFill(['branch_id' => $this->branchA->id])->save();

    expect(umiListedIds($reader->refresh()))->toBe([]);
});

it('does not let one legacy permission reveal the other archive type', function () {
    $odo = umiPatient($this->branchA, 'Hanya Odonto Lain');
    umiLegacyOdontogram($odo, $this->branchA);

    $rmeOnlyReader = userWith(['view_clinic_visits', 'view_legacy_rme_imports', 'review_legacy_rme_imports']);

    expect(umiListedIds($rmeOnlyReader))->toBe([]);
});

it('never lets a branch-scoped actor discover another branch through legacy inclusion', function () {
    $own = umiPatient($this->branchA, 'Cabang Sendiri');
    umiLegacyRme($own, $this->branchA);
    $other = umiPatient($this->branchB, 'Cabang Lain Rahasia');
    umiLegacyRme($other, $this->branchB);
    umiLegacyOdontogram($other, $this->branchB);

    $scoped = userWith(['view_clinic_visits', 'view_legacy_rme_imports', 'view_legacy_odontogram_imports']);
    $scoped->forceFill(['branch_id' => $this->branchA->id])->save();

    $response = $this->actingAs($scoped)->get(route('rme.medical-records.index', ['branch_id' => $this->branchB->id]));
    $response->assertOk()->assertDontSee('Cabang Lain Rahasia');

    expect($response->viewData('patients')->getCollection()->pluck('id')->all())->toBe([$own->id])
        ->and($response->viewData('summary')['total'])->toBe(1);

    $this->actingAs($scoped)->get(route('rme.medical-records.patients.show', $other->id))->assertNotFound();
});

it('forbids the index to a user without clinical visit permissions', function () {
    $this->actingAs(userWith(['view_legacy_rme_imports']))
        ->get(route('rme.medical-records.index'))
        ->assertForbidden();
});

it('keeps the legacy viewer itself policy-protected for an unrelated actor', function () {
    $p = umiPatient($this->branchA, 'Viewer Terlindungi');
    $record = umiLegacyRme($p, $this->branchA);

    $this->actingAs(userWith(['view_clinic_visits']))
        ->get(route('rme.legacy-records.show', $record->id))
        ->assertForbidden();
});

it('never renders a full KTP/NIK on the list or the workspace', function () {
    $secret = '7371010101900001';
    $p = Patient::factory()->create(['name' => 'Privasi Legacy', 'branch_id' => $this->branchA->id, 'ktp_number' => $secret]);
    umiLegacyRme($p, $this->branchA);

    $user = umiGovernor();

    $this->actingAs($user)->get(route('rme.medical-records.index'))->assertOk()->assertSee('Privasi Legacy')->assertDontSee($secret);
    $this->actingAs($user)->get(route('rme.medical-records.patients.show', $p->id))->assertOk()->assertDontSee($secret);
});

/** A Doctor-role user linked to an active doctor master practising at $branch. */
function umiDoctor(Branch $branch): array
{
    $user = User::factory()->create(['branch_id' => $branch->id]);
    $user->assignRole('Doctor');
    $doctor = Doctor::factory()->create(['user_id' => $user->getKey(), 'branch_id' => $branch->id, 'is_active' => true]);

    return [$user->refresh(), $doctor];
}

it('keeps the doctor clinical scope: same branch alone never lists a legacy archive', function () {
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);

    $treated = umiPatient($this->branchA, 'Pasien Dirawat');
    umiLegacyRme($treated, $this->branchA);
    $stranger = umiPatient($this->branchA, 'Pasien Asing Sama Cabang');
    umiLegacyRme($stranger, $this->branchA);
    umiLegacyOdontogram($stranger, $this->branchA);

    [$user, $doctor] = umiDoctor($this->branchA);
    PatientDoctorAssignment::factory()->create(['patient_id' => $treated->id, 'doctor_id' => $doctor->id, 'unassigned_at' => null]);

    expect($user->canAny(['view_legacy_rme_archive', 'view_legacy_rme_imports']))->toBeTrue();

    $response = $this->actingAs($user)->get(route('rme.medical-records.index'));
    $response->assertOk()->assertDontSee('Pasien Asing Sama Cabang');

    expect($response->viewData('patients')->getCollection()->pluck('id')->all())->toBe([$treated->id]);

    $this->actingAs($user)->get(route('rme.medical-records.patients.show', $stranger->id))->assertNotFound();
    $this->actingAs($user)->get(route('rme.medical-records.patients.show', $treated->id))->assertOk();
});

it('never counts a soft-deleted visit as a doctor clinical relationship', function () {
    // Security review MEDIUM: the SQL doctor scope counted soft-deleted visits
    // while the canonical doctorCanAccessPatient() did not, so the index could
    // disclose a legacy archive the viewer would refuse.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);

    $p = umiPatient($this->branchA, 'Relasi Kunjungan Terhapus');
    $record = umiLegacyRme($p, $this->branchA);

    [$user, $doctor] = umiDoctor($this->branchA);
    $visit = ClinicVisit::factory()->create([
        'patient_id' => $p->id,
        'doctor_id' => $doctor->id,
        'branch_id' => $this->branchA->id,
    ]);
    $visit->delete();

    expect(umiListedIds($user))->toBe([]);
    $this->actingAs($user)->get(route('rme.medical-records.patients.show', $p->id))->assertNotFound();

    // Same answer as the canonical viewer.
    expect($user->can('view', $record))->toBeFalse();
});

it('matches nothing on an empty legacy branch set, even with the unscoped allowance', function () {
    $scope = new UnifiedMedicalRecordScope(
        nativeBranchIds: [],
        legacyRmeReadable: true,
        legacyRmeBranchIds: [],
        legacyRmeIncludesUnscoped: true,
        legacyOdontogramReadable: true,
        legacyOdontogramBranchIds: [],
        legacyOdontogramIncludesUnscoped: true,
    );

    expect($scope->legacyRmeVisible())->toBeFalse()
        ->and($scope->legacyOdontogramVisible())->toBeFalse()
        ->and($scope->anyLegacyReadable())->toBeFalse();
});

it('never widens a doctor to a legacy archive outside their practice branches', function () {
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);

    $p = umiPatient($this->branchB, 'Arsip Cabang Lain Dokter');
    umiLegacyRme($p, $this->branchB);

    [$user, $doctor] = umiDoctor($this->branchA);
    PatientDoctorAssignment::factory()->create(['patient_id' => $p->id, 'doctor_id' => $doctor->id, 'unassigned_at' => null]);

    expect(umiListedIds($user))->toBe([]);
});

// ----------------------------------------------------------- patient workspace

it('opens a legacy-only patient without a medical record or a visit', function () {
    $p = umiPatient($this->branchA, 'Buka Legacy Saja');
    $rme = umiLegacyRme($p, $this->branchA);
    $odo = umiLegacyOdontogram($p, $this->branchA);

    $visitsBefore = ClinicVisit::count();
    $recordsBefore = MedicalRecord::count();

    $this->actingAs(umiGovernor())
        ->get(route('rme.medical-records.patients.show', $p->id))
        ->assertOk()
        ->assertSee('Rekam Medis Native: Belum Ada')
        ->assertSee(route('rme.legacy-records.show', $rme->id))
        ->assertSee(route('rme.legacy-odontograms.show', $odo->id))
        ->assertDontSee('source_pdf_path')
        ->assertDontSee($rme->source_pdf_path);

    expect(ClinicVisit::count())->toBe($visitsBefore)
        ->and(MedicalRecord::count())->toBe($recordsBefore);
});

it('opens a native patient with links into the canonical RM workspace', function () {
    $p = umiPatient($this->branchA, 'Buka Native');
    $record = umiNative($p, $this->branchA);

    $this->actingAs(umiGovernor())
        ->get(route('rme.medical-records.patients.show', $p->id))
        ->assertOk()
        ->assertSee(route('rme.visits.medical-record.show', $record->clinicVisit));
});

it('answers 404 for a patient outside the index exactly like a missing one', function () {
    $p = umiPatient($this->branchA, 'Tanpa Apa Pun');
    $user = umiGovernor();

    $this->actingAs($user)->get(route('rme.medical-records.patients.show', $p->id))->assertNotFound();
    $this->actingAs($user)->get(route('rme.medical-records.patients.show', 999999))->assertNotFound();
});

it('creates no clinical row while rendering the index', function () {
    $p = umiPatient($this->branchA, 'Tanpa Mutasi');
    umiLegacyRme($p, $this->branchA);

    $counts = fn () => [
        ClinicVisit::count(), MedicalRecord::count(),
        LegacyRmeRecord::count(), LegacyOdontogramRecord::count(), Patient::count(),
    ];

    $before = $counts();
    $this->actingAs(umiGovernor())->get(route('rme.medical-records.index'))->assertOk();

    expect($counts())->toBe($before);
});

it('runs a constant number of queries regardless of page size', function () {
    $user = umiGovernor();

    $measure = function () use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get(route('rme.medical-records.index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    foreach (range(1, 2) as $i) {
        $p = umiPatient($this->branchA, 'Q '.$i);
        umiNative($p, $this->branchA);
        umiLegacyRme($p, $this->branchA);
        umiLegacyOdontogram($p, $this->branchA);
    }
    $measure(); // warm the permission cache
    $small = $measure();

    foreach (range(3, 12) as $i) {
        $p = umiPatient($this->branchA, 'Q '.$i);
        umiNative($p, $this->branchA);
        umiLegacyRme($p, $this->branchA);
        umiLegacyOdontogram($p, $this->branchA);
    }
    $large = $measure();

    expect($large)->toBe($small);
});
