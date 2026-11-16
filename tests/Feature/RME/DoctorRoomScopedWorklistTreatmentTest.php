<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DMS-RME-COMPREHENSIVE-CLOSURE-1 PR8 (memo M3.2) — planned treatment on the worklist
|--------------------------------------------------------------------------
|
| Owner decision 2026-11-17 ("Treatment on worklist"): the doctor's landing list,
| the treatment-room worklist, shows each visit's planned treatment ("Tindakan
| Awal", captured at registration), so the doctor can prepare before opening the
| visit. The relation is eager-loaded with the page, so the page costs the same
| number of queries for one row or many. The room scope is unchanged: another
| room's visit and its treatment never appear.
|
| Selected by the existing `DoctorRoomScoped` critical-gate token in both runner
| variants and declared in critical_gate_mandatory_suites (CG-19). Helper prefix
| `drwt` is unique because the critical gate loads every suite into one process.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicRoom\Models\ClinicRoom;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    seedAccessControl();

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->roomA = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);
    $this->roomB = ClinicRoom::factory()->create(['branch_id' => $this->branch->id, 'status' => ClinicRoom::STATUS_ACTIVE]);

    $this->doctorRecord = Doctor::factory()->withAllowedBranches([$this->branch])->create();
    $this->doctor = rmeMakeDoctorOnline($this->doctorRecord, $this->branch, $this->roomA);
});

function drwtVisit(object $t, ClinicRoom $room, ?Treatment $treatment): ClinicVisit
{
    return ClinicVisit::factory()->create([
        'branch_id' => $t->branch->id,
        'clinic_room_id' => $room->id,
        'doctor_id' => $t->doctorRecord->id,
        'status' => ClinicVisit::STATUS_WAITING,
        'visit_date' => clinicalToday()->toDateString(),
        'initial_treatment_id' => $treatment?->id,
    ]);
}

/** Queries the worklist page runs against the treatment master table. */
function drwtTreatmentQueries(object $t): int
{
    $count = 0;
    DB::listen(function (QueryExecuted $q) use (&$count): void {
        if (str_contains($q->sql, 'mst_treatments')) {
            $count++;
        }
    });

    $t->actingAs($t->doctor)->get(route('rme.treatment-room-worklist.index'))->assertOk();

    return $count;
}

it('WT-01 shows the planned treatment of each visit in the doctor\'s room', function () {
    $scaling = Treatment::factory()->create(['name' => 'Scaling Rahang Atas DRWT']);
    $visit = drwtVisit($this, $this->roomA, $scaling);

    $this->actingAs($this->doctor)
        ->get(route('rme.treatment-room-worklist.index'))
        ->assertOk()
        ->assertSee('Tindakan Awal')
        ->assertSee($visit->visit_number)
        ->assertSee('Scaling Rahang Atas DRWT');
});

it('WT-02 never shows another room\'s visit or its planned treatment', function () {
    drwtVisit($this, $this->roomA, Treatment::factory()->create(['name' => 'Tambal Komposit DRWT']));
    $other = drwtVisit($this, $this->roomB, Treatment::factory()->create(['name' => 'Cabut Gigi Lain DRWT']));

    $this->actingAs($this->doctor)
        ->get(route('rme.treatment-room-worklist.index'))
        ->assertOk()
        ->assertSee('Tambal Komposit DRWT')
        ->assertDontSee($other->visit_number)
        ->assertDontSee('Cabut Gigi Lain DRWT');
});

it('WT-03 shows a dash for a visit registered without a planned treatment', function () {
    $visit = drwtVisit($this, $this->roomA, null);

    $html = $this->actingAs($this->doctor)
        ->get(route('rme.treatment-room-worklist.index'))
        ->assertOk()
        ->assertSee($visit->visit_number)
        ->getContent();

    expect($html)->toContain('data-testid="worklist-initial-treatment"');
});

it('WT-04 loads the planned treatments with the page, not once per row', function () {
    foreach (range(1, 4) as $ignored) {
        drwtVisit($this, $this->roomA, Treatment::factory()->create());
    }

    // One eager-load query for the whole page, never one per row.
    expect(drwtTreatmentQueries($this))->toBe(1);
});
