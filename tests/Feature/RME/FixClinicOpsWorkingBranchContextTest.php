<?php

/**
 * FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 — FIX-03 / FIX-04 / FIX-06 / FIX-09.
 *
 * The clinic and cashier workspaces are pinned to the branch the operator is
 * actually working in. These tests probe the boundary the way an attacker
 * would — crafted branch_id filters, direct record URLs, exports — rather than
 * only checking which buttons render.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Services\RmePaymentService;
use App\Modules\RmeOnlineContext\Services\BranchChangeApprovalService;
use App\Modules\RmeOnlineContext\Services\RmeWorkingBranchScope;
use App\Modules\Treatment\Models\Treatment;
use App\Support\Clinical\ClinicalClock;
use Database\Seeders\BranchSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();

    $this->tkm = Branch::factory()->create(['code' => 'TKMX', 'name' => 'Cabang Telkomas X', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->ldk = Branch::factory()->create(['code' => 'LDKX', 'name' => 'Cabang Landak X', 'is_active' => true, 'is_rme_enabled' => true]);
    $this->doctor = Doctor::factory()->create(['name' => 'drg. Uji']);
});

function fcoVisit(Branch $branch, array $overrides = []): ClinicVisit
{
    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'name' => 'Pasien '.$branch->code,
    ]);

    return ClinicVisit::factory()->create(array_merge([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => test()->doctor->id,
        'status' => ClinicVisit::STATUS_REGISTERED,
        'visit_date' => app(ClinicalClock::class)->todayString(),
    ], $overrides));
}

function fcoInvoice(ClinicVisit $visit, array $overrides = []): RmeInvoice
{
    return RmeInvoice::factory()->create(array_merge([
        'clinic_visit_id' => $visit->id,
        'branch_id' => $visit->branch_id,
        'patient_id' => $visit->patient_id,
    ], $overrides));
}

/* ---------------------------------------------------------------- scope authority */

it('pins a context-bound role to its selected working branch', function () {
    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $this->tkm);

    expect(app(RmeWorkingBranchScope::class)->branchIdsFor($admin))->toBe([(int) $this->tkm->id]);
});

it('fails closed to an empty scope when a context-bound role has no working context', function () {
    $kasir = userInRole('Kasir');

    expect(app(RmeWorkingBranchScope::class)->branchIdsFor($kasir))->toBe([])
        ->and(app(RmeWorkingBranchScope::class)->allows($kasir, (int) $this->tkm->id))->toBeFalse();
});

it('keeps the full RME set for governance roles', function () {
    $owner = userInRole('Owner');

    expect(app(RmeWorkingBranchScope::class)->branchIdsFor($owner))
        ->toContain((int) $this->tkm->id)
        ->toContain((int) $this->ldk->id);
});

it('never lets a request branch_id widen an authorised scope', function () {
    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $this->tkm);
    $scope = app(RmeWorkingBranchScope::class);

    expect($scope->resolve($admin, (int) $this->ldk->id))->toBe([(int) $this->tkm->id])
        ->and($scope->resolve($admin, (int) $this->tkm->id))->toBe([(int) $this->tkm->id]);
});

it('does not pin a Doctor, preserving the clinical practice branch model', function () {
    $doctorUser = rmeMakeDoctorOnline($this->doctor, $this->tkm);

    expect(app(RmeWorkingBranchScope::class)->isContextBound($doctorUser))->toBeFalse();
});

/* ------------------------------------------------------- FIX-04 Admin Klinik */

it('scopes the visit list, queue and RME report to the Admin Klinik working branch', function () {
    fcoVisit($this->tkm);
    fcoVisit($this->ldk);

    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $this->tkm);

    foreach ([route('rme.visits.index'), route('rme.patient-queue.index'), route('rme.reports.patients')] as $url) {
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Pasien TKMX')->assertDontSee('Pasien LDKX');
    }
});

it('ignores a crafted branch_id filter on every Admin Klinik surface', function () {
    fcoVisit($this->ldk);

    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $this->tkm);

    foreach ([
        route('rme.visits.index', ['branch_id' => $this->ldk->id]),
        route('rme.patient-queue.index', ['branch_id' => $this->ldk->id]),
        route('rme.reports.patients', ['branch_id' => $this->ldk->id]),
    ] as $url) {
        $this->actingAs($admin)->get($url)->assertOk()->assertDontSee('Pasien LDKX');
    }
});

it('denies an Admin Klinik opening a visit from another branch by direct URL', function () {
    $foreign = fcoVisit($this->ldk);

    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $this->tkm);

    $this->actingAs($admin)->get(route('rme.visits.show', $foreign))->assertForbidden();
});

/* ------------------------------------------------------- FIX-03 / FIX-09 cashier */

it('scopes every cashier surface to the cashier working branch', function () {
    $tkmVisit = fcoVisit($this->tkm, ['status' => ClinicVisit::STATUS_CASHIER_PENDING]);
    $ldkVisit = fcoVisit($this->ldk, ['status' => ClinicVisit::STATUS_CASHIER_PENDING]);
    fcoInvoice($tkmVisit, ['status' => RmeInvoice::STATUS_UNPAID, 'grand_total' => 100000]);
    fcoInvoice($ldkVisit, ['status' => RmeInvoice::STATUS_UNPAID, 'grand_total' => 100000]);

    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, $this->tkm);

    foreach ([
        route('rme.cashier.index'),
        route('rme.cashier.handoff'),
        route('rme.cashier.receivables'),
        route('rme.reports.payments'),
    ] as $url) {
        $this->actingAs($kasir)->get($url)->assertOk()->assertDontSee('Pasien LDKX');
    }
});

it('keeps the receivables CSV export inside the cashier working branch', function () {
    $ldkVisit = fcoVisit($this->ldk, ['status' => ClinicVisit::STATUS_CASHIER_PENDING]);
    fcoInvoice($ldkVisit, ['status' => RmeInvoice::STATUS_UNPAID, 'grand_total' => 250000]);

    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, $this->tkm);

    $response = $this->actingAs($kasir)->get(route('rme.cashier.receivables.export'))->assertOk();

    expect($response->streamedContent())->not->toContain('Pasien LDKX');
});

it('refuses a cashier payment on an invoice from another branch', function () {
    $foreignVisit = fcoVisit($this->ldk, ['status' => ClinicVisit::STATUS_CASHIER_PENDING]);
    $foreignInvoice = fcoInvoice($foreignVisit, ['status' => RmeInvoice::STATUS_UNPAID, 'grand_total' => 100000]);

    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, $this->tkm);

    // The record itself is unreachable — hiding the row is never the boundary.
    $this->actingAs($kasir)
        ->get(route('rme.cashier.show', [$foreignVisit, $foreignInvoice]))
        ->assertForbidden();

    expect($kasir->can('pay', $foreignInvoice))->toBeFalse()
        ->and($kasir->can('viewReceipt', $foreignInvoice))->toBeFalse();

    // And the service refuses too, so no controller, command or crafted request
    // can settle another branch's invoice even if it reached the payment path.
    $this->actingAs($kasir);
    expect(fn () => app(RmePaymentService::class)
        ->pay($foreignInvoice, $kasir, ['amount' => 100000, 'paid_at' => now()->toDateString()]))
        ->toThrow(ValidationException::class);

    expect($foreignInvoice->refresh()->status)->toBe(RmeInvoice::STATUS_UNPAID)
        ->and($foreignInvoice->payments()->count())->toBe(0);
});

/*
 | FEATURE-DAILY-BRANCH-CONTEXT-LOCK-1 changed the ROUTE to a mid-day branch
 | change, not the consequence of one. The property this test has always
 | asserted — cashier scope follows the working branch — is unchanged; what is
 | no longer available is switching by simply calling the selector again.
 |
 | Both halves are worth keeping, so the original single test is now two: the
 | switch is refused without an approval, and it takes effect with one.
 */
it('refuses to follow the cashier to a new branch without a Super Admin approval', function () {
    $ldkVisit = fcoVisit($this->ldk, ['status' => ClinicVisit::STATUS_CASHIER_PENDING]);
    fcoInvoice($ldkVisit, ['status' => RmeInvoice::STATUS_UNPAID, 'grand_total' => 100000]);

    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, $this->tkm);
    $this->actingAs($kasir)->get(route('rme.cashier.index'))->assertOk()->assertDontSee('Pasien LDKX');

    expect(fn () => rmeMakeKasirActive($kasir, $this->ldk))->toThrow(ValidationException::class);

    // The financial scope did not move.
    $this->actingAs($kasir)->get(route('rme.cashier.index'))->assertOk()->assertDontSee('Pasien LDKX');
});

it('follows the cashier to a new branch once a Super Admin approves the change', function () {
    $ldkVisit = fcoVisit($this->ldk, ['status' => ClinicVisit::STATUS_CASHIER_PENDING]);
    fcoInvoice($ldkVisit, ['status' => RmeInvoice::STATUS_UNPAID, 'grand_total' => 100000]);

    $kasir = userInRole('Kasir');
    rmeMakeKasirActive($kasir, $this->tkm);
    $this->actingAs($kasir)->get(route('rme.cashier.index'))->assertOk()->assertDontSee('Pasien LDKX');

    $approvals = app(BranchChangeApprovalService::class);
    $request = $approvals->request($kasir, (int) $this->ldk->id, 'Bertugas di Landak sore ini.');
    $approvals->approve((int) $request->id, userInRole('Super Admin'));

    // The approval alone moves the scope — the cashier does not have to
    // re-select, and every existing session resolves to the new branch.
    $this->actingAs($kasir)->get(route('rme.cashier.index'))->assertOk()->assertSee('Pasien LDKX');
});

it('sends a Kasir without a working context to the branch selector', function () {
    $kasir = userInRole('Kasir');

    $this->actingAs($kasir)->get(route('rme.cashier.index'))
        ->assertRedirect(route('rme.online-context.select'));
});

it('lets a Kasir start a working branch context', function () {
    $kasir = userInRole('Kasir');

    $this->actingAs($kasir)
        ->post(route('rme.online-context.kasir'), ['branch_id' => $this->tkm->id])
        ->assertRedirect();

    expect(app(RmeWorkingBranchScope::class)->activeBranchId($kasir->refresh()))->toBe((int) $this->tkm->id);
});

it('refuses a Kasir working context on a non-RME branch', function () {
    $kasir = userInRole('Kasir');
    $nonRme = Branch::factory()->create([
        'code' => 'NONRME', 'name' => 'Cabang Non RME',
        'is_active' => true, 'is_rme_enabled' => false,
    ]);

    $this->actingAs($kasir)
        ->post(route('rme.online-context.kasir'), ['branch_id' => $nonRme->id])
        ->assertSessionHasErrors('branch_id');

    expect(app(RmeWorkingBranchScope::class)->activeBranchId($kasir->refresh()))->toBeNull();
});

/* ------------------------------------------------------- FIX-06 clinical today */

it('shows only today on the visit list until a date filter is used', function () {
    $clock = app(ClinicalClock::class);
    fcoVisit($this->tkm, ['visit_date' => $clock->todayString()]);

    $yesterday = $clock->today()->subDay()->toDateString();
    $old = fcoVisit($this->tkm, ['visit_date' => $yesterday]);
    $old->patient->update(['name' => 'Pasien Kemarin']);

    $viewer = userWith(['view_clinic_visits', 'manage_clinic_visits']);

    $this->actingAs($viewer)->get(route('rme.visits.index'))
        ->assertOk()->assertSee('Pasien TKMX')->assertDontSee('Pasien Kemarin');

    $this->actingAs($viewer)->get(route('rme.visits.index', ['visit_date' => $yesterday]))
        ->assertOk()->assertSee('Pasien Kemarin');

    $this->actingAs($viewer)->get(route('rme.visits.index', [
        'date_from' => $clock->today()->subDays(7)->toDateString(),
        'date_to' => $clock->todayString(),
    ]))->assertOk()->assertSee('Pasien Kemarin');

    // Explicitly clearing the date is a deliberate request for the full history.
    $this->actingAs($viewer)->get(route('rme.visits.index', ['visit_date' => '']))
        ->assertOk()->assertSee('Pasien Kemarin');
});

it('resolves the visit list default from the clinical calendar, not UTC', function () {
    $viewer = userWith(['view_clinic_visits']);

    $this->actingAs($viewer)->get(route('rme.visits.index'))
        ->assertOk()
        ->assertSee(app(ClinicalClock::class)->todayString());
});

it('stamps a new visit with the clinic calendar day, not a UTC today', function () {
    // Between 00:00 and 08:00 WITA the UTC date is still yesterday. A visit
    // registered in that window used to be stamped with YESTERDAY's date, queue
    // number and visit number — and would then be missing from the "today" list
    // this sprint makes the default.
    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $this->tkm);

    $patient = Patient::factory()->create(['branch_id' => $this->tkm->id, 'name' => 'Pasien Subuh']);

    $treatment = Treatment::factory()->create(['is_active' => true]);

    $this->actingAs($admin)->post(route('rme.visits.store'), [
        'patient_mode' => 'existing',
        'branch_id' => $this->tkm->id,
        'patient_id' => $patient->id,
        'doctor_id' => $this->doctor->id,
        'initial_treatment_id' => $treatment->id,
        'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $visit = ClinicVisit::query()->where('patient_id', $patient->id)->firstOrFail();
    $clinicalToday = app(ClinicalClock::class)->todayString();

    expect($visit->visit_date->toDateString())->toBe($clinicalToday)
        ->and($visit->visit_number)->toContain(str_replace('-', '', $clinicalToday));

    // And it is therefore visible on the default (today) list.
    $this->actingAs($admin)->get(route('rme.visits.index'))->assertOk()->assertSee('Pasien Subuh');
});
