<?php

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 — FIX-01.
 *
 * A document's clinic identity comes from the branch that OWNS the record,
 * never from a literal baked into a shared template and never from whichever
 * branch the person printing happens to be working in.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Odontogram\Models\Odontogram;
use App\Modules\Patient\Models\Patient;
use App\Modules\Prescription\Models\RmePrescription;
use Database\Seeders\BranchSeeder;

const FCO_TELKOMAS_LEGACY_ADDRESS = 'Jl. Telkomas Raya, Ruko No. 07';

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->tkm = Branch::factory()->create([
        'code' => 'TKMD', 'name' => 'Cabang Telkomas D', 'is_active' => true, 'is_rme_enabled' => true,
        'address' => 'Jl. Telkomas Raya No. 7, Makassar', 'phone' => '0811111111',
    ]);
    $this->ldk = Branch::factory()->create([
        'code' => 'LDKD', 'name' => 'Cabang Landak D', 'is_active' => true, 'is_rme_enabled' => true,
        'address' => 'Jl. Landak Baru No. 21, Makassar', 'phone' => '0822222222',
    ]);
    $this->doctor = Doctor::factory()->create(['name' => 'drg. Uji']);
});

function fcoDocVisit(Branch $branch): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id, 'name' => 'Pasien Dokumen']);

    return ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
        'status' => ClinicVisit::STATUS_IN_PROGRESS,
    ]);
}

/* ------------------------------------------------------------- master data */

it('lets Master Data Cabang capture and edit a branch address', function () {
    $admin = userWith(['manage_branch_master_data', 'view_branch_master_data']);

    $this->actingAs($admin)->get(route('settings.branches.create'))
        ->assertOk()->assertSee('name="address"', false)->assertSee('name="phone"', false);

    $this->actingAs($admin)->post(route('settings.branches.store'), [
        'code' => 'NEWB', 'name' => 'Cabang Baru',
        'address' => 'Jl. Baru No. 9, Makassar', 'phone' => '0899999999',
        'is_active' => 1, 'is_rme_enabled' => 1, 'is_inventory_enabled' => 0,
    ])->assertRedirect();

    $branch = Branch::query()->where('code', 'NEWB')->firstOrFail();
    expect($branch->address)->toBe('Jl. Baru No. 9, Makassar')
        ->and($branch->phone)->toBe('0899999999');

    $this->actingAs($admin)->put(route('settings.branches.update', $branch), [
        'code' => 'NEWB', 'name' => 'Cabang Baru',
        'address' => 'Jl. Pindah No. 10, Makassar', 'phone' => '0899999999',
        'is_active' => 1, 'is_rme_enabled' => 1, 'is_inventory_enabled' => 0,
    ])->assertRedirect();

    expect($branch->refresh()->address)->toBe('Jl. Pindah No. 10, Makassar');
});

/* ------------------------------------------------------------- documents */

it('prints the odontogram with the record branch address, not a hardcoded one', function () {
    $visit = fcoDocVisit($this->ldk);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $viewer = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $html = $this->actingAs($viewer)->get(route('rme.odontograms.print', $odontogram))->assertOk()->getContent();

    expect($html)->toContain('Jl. Landak Baru No. 21, Makassar')
        // The shared template used to bake the Telkomas street address into
        // every branch's odontogram printout.
        ->and($html)->not->toContain(FCO_TELKOMAS_LEGACY_ADDRESS)
        ->and($html)->not->toContain('Jl. Telkomas Raya No. 7, Makassar');
});

it('prints the prescription with the record branch address', function () {
    $visit = fcoDocVisit($this->ldk);
    $prescription = RmePrescription::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
    ]);

    $viewer = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $html = $this->actingAs($viewer)->get(route('rme.prescriptions.print', $prescription))->assertOk()->getContent();

    expect($html)->toContain('Jl. Landak Baru No. 21, Makassar')
        ->and($html)->toContain('CABANG CABANG LANDAK D')
        ->and($html)->not->toContain('Jl. Telkomas Raya No. 7, Makassar');
});

it('keeps the document identity of the record branch even when the viewer works elsewhere', function () {
    $visit = fcoDocVisit($this->ldk);
    $prescription = RmePrescription::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
    ]);

    // A Super Admin whose own context is elsewhere still prints Landak's identity.
    $html = $this->actingAs(superAdmin())
        ->get(route('rme.prescriptions.print', $prescription))->assertOk()->getContent();

    expect($html)->toContain('Jl. Landak Baru No. 21, Makassar')
        ->and($html)->not->toContain('Jl. Telkomas Raya No. 7, Makassar');
});

it('renders documents without inventing an address when the branch has none', function () {
    $blank = Branch::factory()->create([
        'code' => 'BLNK', 'name' => 'Cabang Tanpa Alamat', 'is_active' => true, 'is_rme_enabled' => true,
        'address' => null, 'phone' => null,
    ]);

    $visit = fcoDocVisit($blank);
    $prescription = RmePrescription::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
    ]);
    $odontogram = Odontogram::factory()->create([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
    ]);

    $viewer = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    // No 500, and no placeholder address presented as if it were real data.
    $rx = $this->actingAs($viewer)->get(route('rme.prescriptions.print', $prescription))->assertOk()->getContent();
    $odo = $this->actingAs($viewer)->get(route('rme.odontograms.print', $odontogram))->assertOk()->getContent();

    foreach ([$rx, $odo] as $html) {
        expect($html)->not->toContain(FCO_TELKOMAS_LEGACY_ADDRESS)
            ->and($html)->not->toContain('Makassar');
    }
});
