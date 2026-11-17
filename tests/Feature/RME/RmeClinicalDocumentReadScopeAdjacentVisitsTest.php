<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-ADJACENT-VISITS-BRANCH-SCOPE-1 — the prev/next visit arrows
|--------------------------------------------------------------------------
|
| The prescription and odontogram pages show "Kunjungan Sebelumnya" and
| "Kunjungan Berikutnya" arrows for the same patient. They were resolved over
| every RME-enabled branch for every viewer, so a Front Office, Admin Klinik or
| Kasir account working at branch A, opening a branch-A visit, was shown a link
| (and a title naming the visit number and date) to the patient's branch-B
| visit, whose page answers that account 403 (rule 227 §1, rule 228 §5). An
| ACTIVE visit in another treatment room leaked the same way to a doctor
| (rule 138). And the prescription page read the key `prev` while the service
| returns `previous`, so its previous arrow never rendered at all.
|
| The arrows are now decided per VIEWER: a visit is offered only when the
| viewer may open that page for it (the page's own policy ability, re-asked in
| ClinicVisitService on the persisted account), and the nearest such visit is
| offered, skipping the ones the viewer may not open.
|
| Helper prefix `adjnav` is unique because the critical gate loads every suite
| into one process.
*/

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\ClinicVisit\Services\ClinicVisitService;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use App\Support\AccessControl\FrontOfficeRole;
use Database\Seeders\BranchSeeder;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    Branch::where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);
    $this->branchA = Branch::factory()->create(['code' => 'ATG3', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->branchB = Branch::factory()->create(['code' => 'LDK2', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->roomA = adjnavRoom($this->branchA);
    $this->roomB = adjnavRoom($this->branchB);
    $this->doctorA = Doctor::factory()->withAllowedBranches([$this->branchA])->create(['name' => 'drg. Navigasi A']);
    $this->doctorB = Doctor::factory()->withAllowedBranches([$this->branchB])->create(['name' => 'drg. Navigasi B']);

    // One patient treated at both branches, alternating A, B, A, B, A (oldest
    // first). V3 (branch A) is the page under test in most cases.
    $this->patient = Patient::factory()->create(['branch_id' => $this->branchA->id, 'name' => 'Pasien Navigasi']);
    $this->v = [];

    foreach ([1 => 40, 2 => 30, 3 => 20, 4 => 10, 5 => 5] as $n => $daysAgo) {
        $atA = $n % 2 === 1;

        $this->v[$n] = adjnavVisit(
            $atA ? $this->branchA : $this->branchB,
            $this->patient,
            $atA ? $this->doctorA : $this->doctorB,
            $atA ? $this->roomA : $this->roomB,
            'VIS-ADJNAV-V'.$n,
            clinicalToday()->subDays($daysAgo)->toDateString(),
        );
    }
});

function adjnavRoom(Branch $branch): ClinicRoom
{
    return ClinicRoom::factory()->create(['branch_id' => $branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
}

function adjnavVisit(
    Branch $branch,
    Patient $patient,
    Doctor $doctor,
    ClinicRoom $room,
    string $number,
    string $date,
    string $status = ClinicVisit::STATUS_COMPLETED,
): ClinicVisit {
    return ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'clinic_room_id' => $room->id,
        'visit_number' => $number,
        'visit_date' => $date,
        'status' => $status,
    ]);
}

/** A user with exactly one role, working at the given branch when context-bound. */
function adjnavUser(string $role, Branch $branch): User
{
    $user = User::factory()->create();

    match ($role) {
        FrontOfficeRole::LEGACY_ADMIN_CLINIC => rmeMakeAdminClinicActive($user, $branch),
        FrontOfficeRole::NAME => rmeMakeFrontOfficeActive($user, $branch),
        FrontOfficeRole::LEGACY_KASIR => rmeMakeKasirActive($user, $branch),
        default => $user->assignRole($role),
    };

    return $user->fresh();
}

/** The arrow link: its href and the title that names the target visit. */
function adjnavPrevLink(string $routeName, ClinicVisit $target): array
{
    return [route($routeName, $target), 'Kunjungan sebelumnya: '.$target->visit_number];
}

function adjnavNextLink(string $routeName, ClinicVisit $target): array
{
    return [route($routeName, $target), 'Kunjungan berikutnya: '.$target->visit_number];
}

dataset('adjnav narrowed roles', [
    'Front Office' => [FrontOfficeRole::NAME],
    'Admin Klinik' => [FrontOfficeRole::LEGACY_ADMIN_CLINIC],
    'Kasir' => [FrontOfficeRole::LEGACY_KASIR],
]);

dataset('adjnav pages', [
    'prescription page' => ['rme.visits.prescription.show'],
    'odontogram page' => ['rme.visits.odontogram.show'],
]);

/*
|--------------------------------------------------------------------------
| The narrowed tier: front desk and cashier read only their working branch
|--------------------------------------------------------------------------
*/

it('never offers a front-desk or cashier account another branch\'s visit in either arrow', function (string $role, string $routeName) {
    $user = adjnavUser($role, $this->branchA);

    $response = $this->actingAs($user)->get(route($routeName, $this->v[3]))->assertOk();

    // The branch-B neighbours (V2 before, V4 after) are never named or linked.
    $response->assertDontSee($this->v[2]->visit_number)
        ->assertDontSee($this->v[4]->visit_number)
        ->assertDontSee(route($routeName, $this->v[2]), false)
        ->assertDontSee(route($routeName, $this->v[4]), false);

    // The nearest visits it may open are offered instead (V1 and V5, branch A).
    $response->assertSee(adjnavPrevLink($routeName, $this->v[1]), false)
        ->assertSee(adjnavNextLink($routeName, $this->v[5]), false);
})->with('adjnav narrowed roles')->with('adjnav pages');

it('keeps same-branch navigation working and disables an arrow with nothing it may open', function (string $routeName) {
    // Front Office working at branch B, on the branch-B visit V4: the previous
    // branch-B visit is V2 (V3 is skipped); every later visit is at branch A.
    $frontOffice = adjnavUser(FrontOfficeRole::NAME, $this->branchB);

    $this->actingAs($frontOffice)->get(route($routeName, $this->v[4]))
        ->assertOk()
        ->assertSee(adjnavPrevLink($routeName, $this->v[2]), false)
        ->assertDontSee('Kunjungan berikutnya:')
        ->assertSee('Kunjungan Berikutnya')
        ->assertDontSee($this->v[1]->visit_number)
        ->assertDontSee($this->v[3]->visit_number)
        ->assertDontSee($this->v[5]->visit_number);
})->with('adjnav pages');

/*
|--------------------------------------------------------------------------
| Unchanged: the cross-branch document readers see the whole record book
|--------------------------------------------------------------------------
*/

it('keeps the cross-branch read roles on the chronological neighbours, on both arrows', function (string $role, string $routeName) {
    $user = adjnavUser($role, $this->branchA);

    // The prescription page's previous arrow is pinned here too: it read the
    // key `prev` while the service returns `previous`, so it never rendered.
    $this->actingAs($user)->get(route($routeName, $this->v[3]))
        ->assertOk()
        ->assertSee(adjnavPrevLink($routeName, $this->v[2]), false)
        ->assertSee(adjnavNextLink($routeName, $this->v[4]), false);
})->with([
    'Owner' => ['Owner'],
    'Supervisor RME' => ['Supervisor RME'],
])->with('adjnav pages');

/*
|--------------------------------------------------------------------------
| A doctor: cross-branch history, but never an ACTIVE visit of another room
|--------------------------------------------------------------------------
*/

it('offers a doctor the patient\'s visits at other branches', function (string $routeName) {
    $doctor = rmeMakeDoctorOnline($this->doctorA, $this->branchA, $this->roomA);

    $this->actingAs($doctor)->get(route($routeName, $this->v[3]))
        ->assertOk()
        ->assertSee(adjnavPrevLink($routeName, $this->v[2]), false)
        ->assertSee(adjnavNextLink($routeName, $this->v[4]), false);
})->with('adjnav pages');

it('never offers a doctor an active visit of another treatment room', function (string $routeName) {
    $doctor = rmeMakeDoctorOnline($this->doctorA, $this->branchA, $this->roomA);
    $otherRoom = adjnavRoom($this->branchA);

    // Today's visit of the same patient is waiting in ANOTHER room of branch A:
    // its pages answer this doctor 403 (rule 138), so the arrow must not offer it.
    $today = adjnavVisit(
        $this->branchA, $this->patient, $this->doctorB, $otherRoom,
        'VIS-ADJNAV-TODAY', clinicalToday()->toDateString(), ClinicVisit::STATUS_WAITING,
    );

    $this->actingAs($doctor)->get(route($routeName, $this->v[5]))
        ->assertOk()
        ->assertSee(adjnavPrevLink($routeName, $this->v[4]), false)
        ->assertDontSee($today->visit_number)
        ->assertDontSee('Kunjungan berikutnya:');

    // Positive control: once the visit is in this doctor's room, it is offered.
    $today->update(['clinic_room_id' => $this->roomA->id]);

    $this->actingAs($doctor)->get(route($routeName, $this->v[5]))
        ->assertOk()
        ->assertSee(adjnavNextLink($routeName, $today), false);
})->with('adjnav pages');

/*
|--------------------------------------------------------------------------
| The service decides it, for the explicit persisted viewer
|--------------------------------------------------------------------------
*/

it('resolves the arrows in the service per viewer and page ability', function () {
    $service = app(ClinicVisitService::class);
    $ids = fn (array $adjacent) => [$adjacent['previous']?->id, $adjacent['next']?->id];

    $owner = adjnavUser('Owner', $this->branchA);
    $frontOfficeA = adjnavUser(FrontOfficeRole::NAME, $this->branchA);

    foreach ([['viewForVisit', RmePrescription::class], ['create', Odontogram::class]] as [$ability, $page]) {
        expect($ids($service->adjacentVisits($this->v[3], $owner, $ability, $page)))
            ->toBe([$this->v[2]->id, $this->v[4]->id])
            ->and($ids($service->adjacentVisits($this->v[3], $frontOfficeA, $ability, $page)))
            ->toBe([$this->v[1]->id, $this->v[5]->id])
            // The anchor is re-asserted: handed a visit it may not open (branch B),
            // the account gets no arrow at all, not its own branch's neighbours.
            ->and($ids($service->adjacentVisits($this->v[4], $frontOfficeA, $ability, $page)))
            ->toBe([null, null]);
    }
});

it('gives no arrow to an account without a working context or a usable account (fail closed)', function () {
    $service = app(ClinicVisitService::class);

    $noContext = User::factory()->create();
    $noContext->assignRole(FrontOfficeRole::NAME);

    $deactivated = adjnavUser('Supervisor RME', $this->branchA);
    $deactivated->forceFill(['is_active' => false])->save();

    foreach ([$noContext->fresh(), $deactivated] as $viewer) {
        $adjacent = $service->adjacentVisits($this->v[3], $viewer, 'viewForVisit', RmePrescription::class);

        expect($adjacent['previous'])->toBeNull()
            ->and($adjacent['next'])->toBeNull();
    }
});

it('requires the viewer and the page ability: no caller can fall back to the estate by omission', function () {
    $parameters = (new ReflectionMethod(ClinicVisitService::class, 'adjacentVisits'))->getParameters();

    expect($parameters[1]->getName())->toBe('viewer')
        ->and((string) $parameters[1]->getType())->toBe(User::class)
        ->and($parameters[1]->isOptional())->toBeFalse()
        ->and($parameters[2]->getName())->toBe('pageAbility')
        ->and($parameters[2]->isOptional())->toBeFalse()
        ->and($parameters[3]->getName())->toBe('pageModel')
        ->and($parameters[3]->isOptional())->toBeFalse();
});
