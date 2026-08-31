<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchContext;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\LabOrder\Services\LabWorkflowRequestService;
use App\Modules\LabOrder\Workflow\LabWorkflowState;
use App\Modules\LabService\Models\LabService;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeOnlineContext\Models\UserOnlineContext;
use App\Modules\RmeOnlineContext\Services\UserOnlineContextService;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RmeBranchSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * RME-BRANCH-SUN4 — Perawat picks a Cabang RME after login through the SAME
 * canonical online-context mechanism as Admin Klinik, and the chosen branch is
 * the BranchContext source for every branch-scoped feature (Lab Request, RME,
 * patients, queue) while the context is active. MAIN and inactive/non-RME
 * branches are never selectable; branch selection never adds permissions.
 */
beforeEach(function () {
    test()->seed(BranchSeeder::class);
    test()->seed(RmeBranchSeeder::class);
    seedAccessControl();
    Storage::fake('local');

    // Mirror the VPS pilot posture: MAIN does not participate in RME.
    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    test()->main = Branch::query()->where('code', Branch::MAIN_CODE)->first();
    test()->sun4 = Branch::query()->where('code', 'SUN4')->first();
    test()->tkm1 = Branch::query()->where('code', 'TLK1')->first();
    test()->ldk2 = Branch::query()->where('code', 'LDK2')->first();
    test()->atg3 = Branch::query()->where('code', 'ATG3')->first();

    test()->perawat = User::factory()->create()->assignRole('Perawat');
    test()->onlineContext = app(UserOnlineContextService::class);
});

/** @param array<string, mixed> $overrides */
function sun4LabRequestPayload(array $overrides = []): array
{
    $doctor = Doctor::factory()->create(['branch_id' => test()->sun4->id]);
    $patient = Patient::factory()->create(['branch_id' => test()->sun4->id, 'doctor_id' => $doctor->id]);
    $service = LabService::factory()->create();

    return array_merge([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'order_date' => now()->toDateString(),
        'due_date' => now()->addDays(5)->toDateString(),
        'priority' => 'NORMAL',
        'notes' => 'Order Perawat SUN4',
        'items' => [
            ['lab_service_id' => $service->id, 'tooth_number' => '21', 'quantity' => 1, 'unit_price' => 500000],
        ],
        'spk_photo' => fakeEvidencePhoto('spk.png'),
        'model_photo' => fakeEvidencePhoto('model.png'),
    ], $overrides);
}

// ---------------------------------------------------------------------------
// B. Perawat branch selection after login
// ---------------------------------------------------------------------------

it('redirects a perawat without an online context to the branch selector', function () {
    $this->actingAs(test()->perawat)
        ->get(route('dashboard'))
        ->assertRedirect(route('rme.online-context.select'));
});

it('sends a perawat to the branch selector as the post-login landing page', function () {
    $this->post(route('login'), [
        'email' => test()->perawat->email,
        'password' => 'password',
    ])->assertRedirect(route('rme.online-context.select', absolute: false));
});

it('shows every active RME branch including SUN4 and hides MAIN on the selector', function () {
    $this->actingAs(test()->perawat)
        ->get(route('rme.online-context.select'))
        ->assertOk()
        ->assertSee('Pilih cabang tempat Anda bertugas pada sesi ini.')
        ->assertSee('SUN4')
        ->assertSee('Cabang Sunu')
        ->assertSee('TLK1')
        ->assertSee('LDK2')
        ->assertSee('ATG3')
        ->assertDontSee(test()->main->name);
});

it('hides inactive and non-RME branches from the selector', function () {
    $inactive = Branch::factory()->create(['code' => 'INA9', 'name' => 'Cabang Nonaktif Uji', 'is_active' => false, 'is_rme_enabled' => true]);
    $nonRme = Branch::factory()->create(['code' => 'NRM9', 'name' => 'Cabang NonRme Uji', 'is_active' => true, 'is_rme_enabled' => false]);

    $this->actingAs(test()->perawat)
        ->get(route('rme.online-context.select'))
        ->assertOk()
        ->assertDontSee($inactive->name)
        ->assertDontSee($nonRme->name);
});

it('lets a perawat choose SUN4 and stores an online perawat context', function () {
    $this->actingAs(test()->perawat)
        ->post(route('rme.online-context.perawat'), ['branch_id' => test()->sun4->id])
        ->assertRedirect(route('dashboard'));

    $context = UserOnlineContext::query()->where('user_id', test()->perawat->id)->first();

    expect($context)->not->toBeNull()
        ->and($context->role_context)->toBe(UserOnlineContext::ROLE_PERAWAT)
        ->and($context->status)->toBe(UserOnlineContext::STATUS_ONLINE)
        ->and((int) $context->branch_id)->toBe((int) test()->sun4->id)
        ->and($context->clinic_room_id)->toBeNull()
        ->and(test()->onlineContext->isPerawatActive(test()->perawat))->toBeTrue();
});

it('resolves BranchContext to SUN4 while the perawat context is active even with users.branch_id NULL', function () {
    test()->perawat->forceFill(['branch_id' => null])->save();
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    expect(app(BranchContext::class)->forUser(test()->perawat->fresh()))->toBe((int) test()->sun4->id);
});

it('prioritizes the active online context branch over a static users.branch_id pin', function () {
    test()->perawat->forceFill(['branch_id' => test()->tkm1->id])->save();
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    expect(app(BranchContext::class)->forUser(test()->perawat->fresh()))->toBe((int) test()->sun4->id);
});

it('rejects MAIN as a perawat context branch', function () {
    $this->actingAs(test()->perawat)
        ->from(route('rme.online-context.select'))
        ->post(route('rme.online-context.perawat'), ['branch_id' => test()->main->id])
        ->assertSessionHasErrors('branch_id');

    expect(UserOnlineContext::query()->where('user_id', test()->perawat->id)->exists())->toBeFalse();
});

it('rejects an inactive branch as a perawat context branch', function () {
    $inactive = Branch::factory()->create(['code' => 'INA8', 'is_active' => false, 'is_rme_enabled' => true]);

    $this->actingAs(test()->perawat)
        ->post(route('rme.online-context.perawat'), ['branch_id' => $inactive->id])
        ->assertSessionHasErrors('branch_id');
});

it('rejects a non-RME branch as a perawat context branch', function () {
    $nonRme = Branch::factory()->create(['code' => 'NRM8', 'is_active' => true, 'is_rme_enabled' => false]);

    $this->actingAs(test()->perawat)
        ->post(route('rme.online-context.perawat'), ['branch_id' => $nonRme->id])
        ->assertSessionHasErrors('branch_id');
});

it('rejects the perawat context endpoint for roles that do not require it', function () {
    $kasir = User::factory()->create()->assignRole('Kasir');
    $admin = User::factory()->create()->assignRole('Admin Klinik');

    $this->actingAs($kasir)
        ->post(route('rme.online-context.perawat'), ['branch_id' => test()->sun4->id])
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('rme.online-context.perawat'), ['branch_id' => test()->sun4->id])
        ->assertForbidden();
});

it('never lets a crafted user_id switch another user\'s context', function () {
    $other = User::factory()->create()->assignRole('Perawat');
    rmeMakePerawatActive($other, test()->tkm1);

    $this->actingAs(test()->perawat)
        ->post(route('rme.online-context.perawat'), [
            'branch_id' => test()->sun4->id,
            'user_id' => $other->id,
        ])
        ->assertRedirect(route('dashboard'));

    expect((int) UserOnlineContext::query()->where('user_id', $other->id)->value('branch_id'))
        ->toBe((int) test()->tkm1->id)
        ->and((int) UserOnlineContext::query()->where('user_id', test()->perawat->id)->value('branch_id'))
        ->toBe((int) test()->sun4->id);
});

it('lets a perawat switch branches the same way as admin klinik without duplicating context rows', function () {
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    $this->actingAs(test()->perawat)
        ->post(route('rme.online-context.perawat'), ['branch_id' => test()->tkm1->id])
        ->assertRedirect(route('dashboard'));

    expect(UserOnlineContext::query()->where('user_id', test()->perawat->id)->count())->toBe(1)
        ->and((int) UserOnlineContext::query()->where('user_id', test()->perawat->id)->value('branch_id'))
        ->toBe((int) test()->tkm1->id);
});

it('treats a stale perawat context as expired and asks for re-selection', function () {
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    UserOnlineContext::query()
        ->where('user_id', test()->perawat->id)
        ->update(['last_seen_at' => now()->subMinutes(UserOnlineContextService::INACTIVITY_MINUTES + 1)]);

    expect(test()->onlineContext->isPerawatActive(test()->perawat->fresh()))->toBeFalse()
        ->and(app(BranchContext::class)->forUser(test()->perawat->fresh()))->not->toBe((int) test()->sun4->id);

    $this->actingAs(test()->perawat)
        ->get(route('dashboard'))
        ->assertRedirect(route('rme.online-context.select'));
});

it('no longer resolves BranchContext to the context branch after the branch is deactivated', function () {
    rmeMakePerawatActive(test()->perawat, test()->sun4);
    test()->sun4->update(['is_active' => false]);

    expect(app(BranchContext::class)->forUser(test()->perawat->fresh()))->not->toBe((int) test()->sun4->id);
});

it('marks the perawat context offline on logout like admin klinik', function () {
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    $this->actingAs(test()->perawat)->post(route('logout'))->assertRedirect('/');

    expect(UserOnlineContext::query()->where('user_id', test()->perawat->id)->value('status'))
        ->toBe(UserOnlineContext::STATUS_OFFLINE);
});

it('supports the shared offline action for a perawat', function () {
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    $this->actingAs(test()->perawat)
        ->post(route('rme.online-context.offline'))
        ->assertRedirect(route('rme.online-context.select'));

    expect(test()->onlineContext->isPerawatActive(test()->perawat->fresh()))->toBeFalse();
});

it('redirects guests away from the context selector', function () {
    $this->get(route('rme.online-context.select'))->assertRedirect(route('login'));
});

it('does not force context-exempt or non-context roles through the selector', function () {
    $owner = User::factory()->create()->assignRole('Owner');
    $kepalaCabang = User::factory()->create()->assignRole('Kepala Cabang');

    $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    // Kepala Cabang never works from an RME online context, so the gate leaves
    // it alone — proving the middleware still only stops the four RME roles.
    $this->actingAs($kepalaCabang)->get(route('dashboard'))->assertOk();
});

it('now routes Kasir through the selector too, since it works from a chosen branch', function () {
    // FIX-CLINIC-OPS-BRANCH-CONTEXT-WA-1 (FIX-03) — Kasir joined Doctor, Admin
    // Klinik and Perawat as a role that works from one selected branch, so it is
    // deliberately no longer a "non-context" role.
    $kasir = User::factory()->create()->assignRole('Kasir');

    $this->actingAs($kasir)->get(route('dashboard'))
        ->assertRedirect(route('rme.online-context.select'));

    rmeMakeKasirActive($kasir, test()->ldk2);

    $this->actingAs($kasir->fresh())->get(route('dashboard'))->assertOk();
});

// ---------------------------------------------------------------------------
// C. Admin Klinik regression — same mechanism, unchanged behavior
// ---------------------------------------------------------------------------

it('keeps admin klinik branch selection working and feeds BranchContext from its context', function () {
    $admin = User::factory()->create()->assignRole('Admin Klinik');

    $this->actingAs($admin)
        ->post(route('rme.online-context.admin-clinic'), ['branch_id' => test()->ldk2->id])
        ->assertRedirect(route('dashboard'));

    expect(test()->onlineContext->isAdminClinicActive($admin->fresh()))->toBeTrue()
        ->and(app(BranchContext::class)->forUser($admin->fresh()))->toBe((int) test()->ldk2->id);
});

it('still rejects the admin-clinic endpoint for a perawat', function () {
    $this->actingAs(test()->perawat)
        ->post(route('rme.online-context.admin-clinic'), ['branch_id' => test()->sun4->id])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// D. Lab Request integration — Klinik follows the SUN4 online context
// ---------------------------------------------------------------------------

it('locks the lab request Klinik field to SUN4 for a perawat with an active SUN4 context', function () {
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    $response = $this->actingAs(test()->perawat)
        ->get(route('lab-workflow-requests.create'))
        ->assertOk()
        ->assertSee('Klinik (Cabang RME)')
        ->assertSee('Cabang Sunu')
        ->assertDontSee('Cabang Telkomas');

    expect((int) $response->viewData('branch')->id)->toBe((int) test()->sun4->id);
});

it('creates a V2 draft lab order on SUN4 from a perawat context', function () {
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    $this->actingAs(test()->perawat)
        ->post(route('lab-workflow-requests.store'), sun4LabRequestPayload())
        ->assertRedirect();

    $order = LabOrder::query()->latest('id')->first();

    expect($order)->not->toBeNull()
        ->and($order->workflow_version)->toBe(LabOrder::WORKFLOW_V2)
        ->and($order->status)->toBe(LabWorkflowState::DRAFT)
        ->and((int) $order->branch_id)->toBe((int) test()->sun4->id)
        ->and($order->clinic_id)->toBeNull();
});

it('ignores a crafted branch_id on lab request store and keeps SUN4', function () {
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    $this->actingAs(test()->perawat)
        ->post(route('lab-workflow-requests.store'), sun4LabRequestPayload([
            'branch_id' => test()->tkm1->id,
        ]))
        ->assertRedirect();

    expect((int) LabOrder::query()->latest('id')->first()->branch_id)->toBe((int) test()->sun4->id);
});

it('scopes the lab request patient catalog to SUN4 plus legacy unscoped patients', function () {
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    $sun4Patient = Patient::factory()->create(['branch_id' => test()->sun4->id, 'name' => 'Pasien Sunu Satu']);
    $legacyPatient = Patient::factory()->create(['branch_id' => null, 'name' => 'Pasien Legacy Nol']);
    $tkm1Patient = Patient::factory()->create(['branch_id' => test()->tkm1->id, 'name' => 'Pasien Telkomas Satu']);

    $this->actingAs(test()->perawat);
    $options = app(LabWorkflowRequestService::class)->formOptionsForActiveBranch();
    $patientIds = $options['patients']->pluck('id');

    expect($patientIds)->toContain($sun4Patient->id)
        ->toContain($legacyPatient->id)
        ->not->toContain($tkm1Patient->id);
});

it('rejects a crafted patient and doctor from another branch on store', function () {
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    $foreignPatient = Patient::factory()->create(['branch_id' => test()->tkm1->id]);
    $foreignDoctor = Doctor::factory()->create(['branch_id' => test()->tkm1->id]);

    $this->actingAs(test()->perawat)
        ->post(route('lab-workflow-requests.store'), sun4LabRequestPayload([
            'patient_id' => $foreignPatient->id,
        ]))
        ->assertSessionHasErrors('patient_id');

    $this->actingAs(test()->perawat)
        ->post(route('lab-workflow-requests.store'), sun4LabRequestPayload([
            'doctor_id' => $foreignDoctor->id,
        ]))
        ->assertSessionHasErrors('doctor_id');

    expect(LabOrder::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// E. RME integration — visit registration follows the perawat context branch
// ---------------------------------------------------------------------------

it('forces perawat visit registration onto the active context branch and ignores a crafted branch_id', function () {
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    $patient = Patient::factory()->create(['branch_id' => test()->sun4->id]);
    $treatment = Treatment::factory()->create(['is_active' => true]);

    $this->actingAs(test()->perawat)
        ->post(route('rme.visits.store'), [
            'patient_mode' => 'existing',
            'branch_id' => test()->tkm1->id,
            'patient_id' => $patient->id,
            'initial_treatment_id' => $treatment->id,
            'visit_type' => ClinicVisit::VISIT_TYPE_NEW,
        ])
        ->assertRedirect();

    $visit = ClinicVisit::query()->latest('id')->first();

    expect($visit)->not->toBeNull()
        ->and((int) $visit->branch_id)->toBe((int) test()->sun4->id)
        ->and($visit->doctor_id)->toBeNull();
});

it('does not grant extra permissions through branch selection', function () {
    rmeMakePerawatActive(test()->perawat, test()->sun4);

    // Perawat has no manage_rme_billing / lab admin permissions — the context
    // only selects a branch, it never widens authorization.
    $this->actingAs(test()->perawat)
        ->get(route('rme.cashier.index'))
        ->assertForbidden();
});
