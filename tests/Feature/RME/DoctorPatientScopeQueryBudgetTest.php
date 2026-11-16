<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 (CC1-PERF) — per-row authorization cost
|--------------------------------------------------------------------------
|
| The visit list and the patient queue ask `can('update', $visit)` once per row.
| For a doctor account every one of those checks resolved "which doctor is this
| user" again (`DoctorIdentityResolver`, one query per call, several calls per
| check), so each extra row cost several identical queries.
|
| The identity is now resolved once per user per request; the per-row checks that
| depend on the row itself (patient relationship, room) are unchanged, and so is
| every authorization answer (QB-03).
|
| Selected by the existing `DoctorPatientScope` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `dpqb` is unique because the critical gate loads every suite into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Services\DoctorAccountLinkService;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    test()->dpqbBranch = Branch::factory()->create(['code' => 'QB01', 'is_rme_enabled' => true]);
    test()->dpqbRoom = ClinicRoom::factory()->create(['branch_id' => test()->dpqbBranch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    test()->dpqbDoctor = Doctor::factory()->create([
        'clinic_id' => Clinic::factory()->create()->id,
        'branch_id' => test()->dpqbBranch->id,
    ]);
    test()->dpqbUser = User::factory()->create()->assignRole('Doctor');
    test()->dpqbDoctor->update(['user_id' => test()->dpqbUser->id]);
    test()->dpqbDoctor->branches()->sync([(int) test()->dpqbBranch->id]);
    rmeMakeDoctorOnline(test()->dpqbDoctor, test()->dpqbBranch, test()->dpqbRoom, test()->dpqbUser);
    test()->dpqbUser->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);
});

/** $count active visits today in the doctor's own room, each for its own patient. */
function dpqbVisits(int $count, ?Doctor $doctor = null): void
{
    for ($i = 0; $i < $count; $i++) {
        $patient = Patient::factory()->create(['branch_id' => test()->dpqbBranch->id]);

        ClinicVisit::factory()->create([
            'branch_id' => test()->dpqbBranch->id,
            'patient_id' => $patient->id,
            'doctor_id' => ($doctor ?? test()->dpqbDoctor)->id,
            'clinic_room_id' => test()->dpqbRoom->id,
            'status' => ClinicVisit::STATUS_IN_PROGRESS,
            'visit_date' => clinicalToday()->toDateString(),
        ]);
    }
}

/** The queries one GET of $route costs the doctor, and how many of them looked the doctor up. */
function dpqbMeasure(object $test, string $route): array
{
    $total = 0;
    $identity = 0;

    DB::listen(function (QueryExecuted $query) use (&$total, &$identity): void {
        $total++;

        if (str_contains($query->sql, '"mst_doctors"') && str_contains($query->sql, '"user_id" = ?')) {
            $identity++;
        }
    });

    $test->actingAs(test()->dpqbUser)->get(route($route))->assertOk();

    return ['total' => $total, 'identity' => $identity];
}

it('QB-01 resolves the doctor identity a bounded number of times on the visit list, whatever the row count', function () {
    dpqbVisits(3);
    $small = dpqbMeasure($this, 'rme.visits.index');

    dpqbVisits(6);
    app()->forgetScopedInstances();
    $large = dpqbMeasure($this, 'rme.visits.index');

    // Six more rows may cost row-specific queries, never another identity lookup each.
    expect($large['identity'])->toBe($small['identity'])
        ->and($large['identity'])->toBeLessThanOrEqual(3);
});

it('QB-02 resolves the doctor identity a bounded number of times on the patient queue, whatever the row count', function () {
    dpqbVisits(3);
    $small = dpqbMeasure($this, 'rme.patient-queue.index');

    dpqbVisits(6);
    app()->forgetScopedInstances();
    $large = dpqbMeasure($this, 'rme.patient-queue.index');

    expect($large['identity'])->toBe($small['identity'])
        ->and($large['identity'])->toBeLessThanOrEqual(3);
});

it('QB-03 keeps every per-row answer: own active visits are editable, another doctor\'s are not', function () {
    dpqbVisits(2);
    $otherDoctor = Doctor::factory()->create(['branch_id' => test()->dpqbBranch->id]);
    dpqbVisits(2, $otherDoctor);

    $own = ClinicVisit::query()->where('doctor_id', test()->dpqbDoctor->id)->get();
    $foreign = ClinicVisit::query()->where('doctor_id', $otherDoctor->id)->get();

    foreach ($own as $visit) {
        expect(test()->dpqbUser->can('update', $visit))->toBeTrue();
    }

    foreach ($foreign as $visit) {
        expect(test()->dpqbUser->can('update', $visit))->toBeFalse();
    }
});

it('QB-04 never carries one account\'s identity to another account in the same request', function () {
    $resolver = app(DoctorIdentityResolver::class);
    $other = User::factory()->create();

    expect($resolver->resolveForUser(test()->dpqbUser)?->id)->toBe(test()->dpqbDoctor->id)
        ->and($resolver->resolveForUser($other))->toBeNull()
        ->and($resolver->resolveForUser(test()->dpqbUser)?->id)->toBe(test()->dpqbDoctor->id);
});

it('QB-05 never reads a link change back stale inside the same request (the link service clears the memo)', function () {
    $user = User::factory()->create()->assignRole('Doctor');
    $doctor = Doctor::factory()->create(['branch_id' => test()->dpqbBranch->id]);

    Route::middleware('web')->get('/_dpqb/relink', function () use ($user, $doctor) {
        $resolver = app(DoctorIdentityResolver::class);
        $links = app(DoctorAccountLinkService::class);

        $before = $resolver->resolveForUser($user)?->id;
        $links->link($doctor, (int) $user->id);
        $linked = $resolver->resolveForUser($user)?->id;
        $links->unlink($doctor->fresh());
        $unlinked = $resolver->resolveForUser($user)?->id;

        return response()->json(compact('before', 'linked', 'unlinked'));
    });

    $this->actingAs(test()->dpqbUser)
        ->getJson('/_dpqb/relink')
        ->assertOk()
        ->assertExactJson(['before' => null, 'linked' => $doctor->id, 'unlinked' => null]);
});

it('QB-06 memoises nothing outside a routed request (queue jobs, commands, direct calls)', function () {
    $user = User::factory()->create()->assignRole('Doctor');
    $doctor = Doctor::factory()->create(['branch_id' => test()->dpqbBranch->id]);
    $resolver = app(DoctorIdentityResolver::class);

    expect($resolver->resolveForUser($user))->toBeNull();

    // A raw write that bypasses the link service is still seen at once here.
    DB::table('mst_doctors')->where('id', $doctor->id)->update(['user_id' => $user->id]);

    expect($resolver->resolveForUser($user)?->id)->toBe($doctor->id);
});
