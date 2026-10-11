<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Clinic\Models\Clinic;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use Database\Seeders\BranchSeeder;

/*
|--------------------------------------------------------------------------
| REVISION-RME-VISIT-DETAIL-INVOICE-REGISTRATION-UX-1 — Revision A
|--------------------------------------------------------------------------
|
| On the visit detail page the clinical cards render in examination order:
|
|     ODONTOGRAM  ->  REKAM MEDIS  ->  RESEP
|
| The order lives in the Blade SOURCE (not CSS `order`), so DOM order, keyboard
| focus order and screen-reader reading order all match what is on screen.
| Presentation only: every card keeps its own authorisation guard, its own
| room gate and its own route.
*/

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->branch = Branch::factory()->create(['code' => 'ORD1', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->room = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->clinic = Clinic::factory()->create();

    $this->doctor = Doctor::factory()->create(['clinic_id' => $this->clinic->id, 'branch_id' => $this->branch->id]);
    $this->doctorUser = User::factory()->create()->assignRole('Doctor');
    $this->doctor->update(['user_id' => $this->doctorUser->id]);
    $this->doctor->branches()->sync([(int) $this->branch->id]);

    $patient = Patient::factory()->create([
        'branch_id' => $this->branch->id,
        'medical_record_number' => 'DG-ORD1-2026-'.fake()->unique()->numerify('####'),
    ]);

    $this->visit = rmeActiveConsentedEncounter(ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $this->doctor->id,
        'clinic_room_id' => $this->room->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]));
});

/** The exact card headings rendered by <x-ui.card title="…">. */
const ORDER_CARD_HEADINGS = [
    'odontogram' => '>Odontogram</h3>',
    'rekam_medis' => '>Rekam Medis</h3>',
    'resep' => '>Resep Dokter</h3>',
];

function orderedCardPositions(string $html): array
{
    $positions = [];

    foreach (ORDER_CARD_HEADINGS as $key => $marker) {
        expect(substr_count($html, $marker))->toBe(1, "Card heading {$marker} must render exactly once.");
        $positions[$key] = strpos($html, $marker);
    }

    return $positions;
}

function assertClinicalCardOrder(string $html): void
{
    $p = orderedCardPositions($html);

    expect($p['odontogram'])->toBeLessThan($p['rekam_medis'], 'Odontogram must come before Rekam Medis.')
        ->and($p['rekam_medis'])->toBeLessThan($p['resep'], 'Rekam Medis must come before Resep.');
}

it('renders Odontogram first, Rekam Medis second and Resep third for the treating doctor', function () {
    rmeMakeDoctorOnline($this->doctor, $this->branch, $this->room, $this->doctorUser);
    $this->doctorUser->givePermissionTo(['view_clinic_visits', 'manage_clinic_visits']);

    $html = $this->actingAs($this->doctorUser)
        ->get(route('rme.visits.show', $this->visit))
        ->assertOk()
        ->getContent();

    assertClinicalCardOrder($html);
});

it('keeps the same order for every role that may operate the visit', function (string $role) {
    $user = userInRole($role);

    $html = $this->actingAs($user)
        ->get(route('rme.visits.show', $this->visit))
        ->assertOk()
        ->getContent();

    assertClinicalCardOrder($html);
})->with([
    'Super Admin' => ['Super Admin'],
    'Supervisor RME' => ['Supervisor RME'],
]);

it('keeps the same order for a permission-only visit manager', function () {
    $manager = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $html = $this->actingAs($manager)
        ->get(route('rme.visits.show', $this->visit))
        ->assertOk()
        ->getContent();

    assertClinicalCardOrder($html);
});

it('keeps every card working after the move: each still links to its own route', function () {
    $manager = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $this->actingAs($manager)
        ->get(route('rme.visits.show', $this->visit))
        ->assertOk()
        ->assertSee(route('rme.visits.odontogram.show', $this->visit), false)
        ->assertSee(route('rme.visits.medical-record.store', $this->visit), false)
        ->assertSee(route('rme.visits.prescription.show', $this->visit), false)
        ->assertSee('Buka Odontogram')
        ->assertSee('Buat Rekam Medis');
});

it('still locks all three cards behind the room gate, in the same order', function () {
    $roomless = rmeActiveConsentedEncounter(ClinicVisit::factory()->create([
        'branch_id' => $this->branch->id,
        'patient_id' => $this->visit->patient_id,
        'doctor_id' => $this->doctor->id,
        'clinic_room_id' => null,
        'status' => ClinicVisit::STATUS_REGISTERED,
    ]));

    $html = $this->actingAs(userWith(['view_clinic_visits', 'manage_clinic_visits']))
        ->get(route('rme.visits.show', $roomless))
        ->assertOk()
        ->getContent();

    assertClinicalCardOrder($html);
    expect(substr_count($html, 'Pemeriksaan terkunci — pasien belum ditempatkan ke ruangan perawatan.'))->toBe(3)
        ->and($html)->not->toContain('Buka Odontogram');
});

it('does not expose the clinical cards to the read-only front office', function () {
    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $this->branch);

    $html = $this->actingAs($admin)
        ->get(route('rme.visits.show', $this->visit))
        ->assertOk()
        ->getContent();

    foreach (ORDER_CARD_HEADINGS as $marker) {
        expect($html)->not->toContain($marker);
    }
});

it('does not reorder with CSS, so focus and reading order follow the screen', function () {
    $source = file_get_contents(resource_path('views/rme/visits/show.blade.php'));

    // No flex/grid `order-*` utility or inline `order:` may be used to fake the
    // sequence — the source itself is in examination order.
    expect(preg_match('/\border-(?:first|last|none|\d+)\b/', $source))->toBe(0)
        ->and(preg_match('/style="[^"]*\border\s*:/', $source))->toBe(0);

    $odontogram = strpos($source, '<x-ui.card title="Odontogram">');
    $rekamMedis = strpos($source, '<x-ui.card title="Rekam Medis">');
    $resep = strpos($source, '<x-ui.card title="Resep Dokter">');

    expect($odontogram)->toBeLessThan($rekamMedis)
        ->and($rekamMedis)->toBeLessThan($resep);
});
