<?php

/**
 * FEATURE-DOCTOR-ACCOUNT-PERFORMANCE-INCOME-LINKAGE-1
 *
 * The durable contract for doctor account identity:
 *
 *   LOGIN USER  --(explicit persisted mst_doctors.user_id)-->  DOCTOR PROFILE
 *                                                                    |
 *                                                    +---------------+---------------+
 *                                                    |                               |
 *                                              KINERJA DOKTER                 PENDAPATAN DOKTER
 *
 * Identity is NEVER inferred from a display name, email, or phone at runtime.
 * One account links to at most one doctor; one doctor links to at most one account.
 * Linking never grants a role and never rewrites historical clinical or financial rows.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Doctor\Models\Doctor;
use App\Modules\Doctor\Services\DoctorIdentityResolver;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmeInvoiceItem;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Services\DoctorPerformanceReportService;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\RmeOnlineContext\Services\DoctorUserResolver;
use App\Modules\Treatment\Models\Treatment;
use Database\Seeders\BranchSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\PermissionMiddleware;

beforeEach(function () {
    test()->seed(BranchSeeder::class);
    seedAccessControl();
    // The Sprint 66.0 online-context middleware redirects a Doctor-role user with
    // no selected branch/room. That is orthogonal to identity linkage, so bypass
    // it to exercise the linkage + report boundaries directly.
    $this->withoutMiddleware(EnsureRmeOnlineContext::class);

    Branch::query()->where('code', Branch::MAIN_CODE)->update(['is_rme_enabled' => false]);

    $this->branch = Branch::factory()->create([
        'code' => 'DAL1',
        'name' => 'Cabang Relasi Akun',
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
});

/** An active user account that legitimately holds the Doctor role. */
function dalDoctorUser(?string $email = null): User
{
    $user = User::factory()->create(array_filter([
        'is_active' => true,
        'email' => $email,
    ]));
    $user->assignRole('Doctor');

    return $user->fresh();
}

/** A completed, fully paid visit attributed to $doctor — the income source of truth. */
function dalPaidVisit(Branch $branch, Doctor $doctor, Treatment $treatment, float $amount): ClinicVisit
{
    $patient = Patient::factory()->create(['branch_id' => $branch->id]);

    $visit = ClinicVisit::factory()->create([
        'branch_id' => $branch->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => ClinicVisit::STATUS_COMPLETED,
        'visit_date' => now()->toDateString(),
    ]);

    $invoice = RmeInvoice::factory()->paid()->create([
        'branch_id' => $branch->id,
        'clinic_visit_id' => $visit->id,
        'patient_id' => $visit->patient_id,
        'subtotal' => $amount,
        'grand_total' => $amount,
    ]);

    RmeInvoiceItem::create([
        'rme_invoice_id' => $invoice->id,
        'treatment_id' => $treatment->id,
        'doctor_id' => $doctor->id,
        'description' => $treatment->name,
        'qty' => 1,
        'unit_price' => $amount,
        'discount' => 0,
        'subtotal' => $amount,
    ]);

    RmePayment::factory()->create([
        'rme_invoice_id' => $invoice->id,
        'branch_id' => $branch->id,
        'amount' => $amount,
    ]);

    return $visit;
}

/* -------------------------------------------------------------------------
 | Management page — authorization (3-layer: route, policy, controller)
 * ---------------------------------------------------------------------- */

it('shows the account link page to an authorized manager, with linked and unlinked state', function () {
    $linkedUser = dalDoctorUser();
    Doctor::factory()->create(['name' => 'Dr. Sudah Terhubung', 'user_id' => $linkedUser->id]);
    Doctor::factory()->create(['name' => 'Dr. Belum Terhubung', 'user_id' => null]);

    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->get(route('settings.doctors.account-links.index'))
        ->assertOk()
        ->assertViewIs('settings.doctors.account-links.index')
        ->assertSee('Dr. Sudah Terhubung')
        ->assertSee('Dr. Belum Terhubung')
        ->assertSee('Terhubung')
        ->assertSee('Belum Terhubung');
});

it('denies the account link page to a user without the linkage permission', function () {
    // `manage doctors` alone must NOT confer the right to relink identities.
    $this->actingAs(userWith(['manage doctors']))
        ->get(route('settings.doctors.account-links.index'))
        ->assertForbidden();
});

it('denies the account link page to clinical and cashier roles', function () {
    foreach (['Doctor', 'Kasir', 'Admin Klinik', 'Perawat'] as $role) {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        $this->actingAs($user->fresh())
            ->get(route('settings.doctors.account-links.index'))
            ->assertForbidden();
    }
});

it('redirects a guest to login', function () {
    $this->get(route('settings.doctors.account-links.index'))->assertRedirect(route('login'));
});

/* -------------------------------------------------------------------------
 | Linking — happy path and validation
 * ---------------------------------------------------------------------- */

it('links a doctor to an eligible account', function () {
    $doctor = Doctor::factory()->create(['user_id' => null]);
    $user = dalDoctorUser();

    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->post(route('settings.doctors.account-links.store', $doctor), ['user_id' => $user->id])
        ->assertRedirect(route('settings.doctors.account-links.index'));

    expect($doctor->fresh()->user_id)->toBe($user->id);
});

it('refuses to link an account that does not hold the Doctor role', function () {
    $doctor = Doctor::factory()->create(['user_id' => null]);
    $cashier = User::factory()->create(['is_active' => true]);
    $cashier->assignRole('Kasir');

    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->post(route('settings.doctors.account-links.store', $doctor), ['user_id' => $cashier->id])
        ->assertSessionHasErrors('user_id');

    expect($doctor->fresh()->user_id)->toBeNull();
});

it('refuses to link an inactive account', function () {
    $doctor = Doctor::factory()->create(['user_id' => null]);
    $user = dalDoctorUser();
    $user->forceFill(['is_active' => false])->save();

    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->post(route('settings.doctors.account-links.store', $doctor), ['user_id' => $user->id])
        ->assertSessionHasErrors('user_id');

    expect($doctor->fresh()->user_id)->toBeNull();
});

it('refuses to link an account already linked to another doctor', function () {
    $user = dalDoctorUser();
    $doctorA = Doctor::factory()->create(['user_id' => $user->id]);
    $doctorB = Doctor::factory()->create(['user_id' => null]);

    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->post(route('settings.doctors.account-links.store', $doctorB), ['user_id' => $user->id])
        ->assertSessionHasErrors('user_id');

    expect($doctorB->fresh()->user_id)->toBeNull()
        ->and($doctorA->fresh()->user_id)->toBe($user->id);
});

it('never silently overwrites an existing link — relink must be explicit', function () {
    $current = dalDoctorUser();
    $replacement = dalDoctorUser();
    $doctor = Doctor::factory()->create(['user_id' => $current->id]);

    // Without explicit confirmation the existing link is preserved.
    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->post(route('settings.doctors.account-links.store', $doctor), ['user_id' => $replacement->id])
        ->assertSessionHasErrors('user_id');

    expect($doctor->fresh()->user_id)->toBe($current->id);

    // With explicit confirmation the relink is performed in one transaction.
    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->post(route('settings.doctors.account-links.store', $doctor), [
            'user_id' => $replacement->id,
            'confirm_relink' => '1',
        ])
        ->assertRedirect(route('settings.doctors.account-links.index'));

    expect($doctor->fresh()->user_id)->toBe($replacement->id);
});

it('rejects an unknown account id', function () {
    $doctor = Doctor::factory()->create(['user_id' => null]);

    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->post(route('settings.doctors.account-links.store', $doctor), ['user_id' => 999999])
        ->assertSessionHasErrors('user_id');

    expect($doctor->fresh()->user_id)->toBeNull();
});

it('denies linking to an unauthorized actor and changes no relation', function () {
    $doctor = Doctor::factory()->create(['user_id' => null]);
    $user = dalDoctorUser();

    $this->actingAs(userWith(['manage doctors']))
        ->post(route('settings.doctors.account-links.store', $doctor), ['user_id' => $user->id])
        ->assertForbidden();

    expect($doctor->fresh()->user_id)->toBeNull();
});

it('does not grant any role as a side effect of linking', function () {
    $doctor = Doctor::factory()->create(['user_id' => null]);
    $user = dalDoctorUser();
    $rolesBefore = $user->getRoleNames()->sort()->values()->all();

    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->post(route('settings.doctors.account-links.store', $doctor), ['user_id' => $user->id]);

    expect($user->fresh()->getRoleNames()->sort()->values()->all())->toBe($rolesBefore);
});

/* -------------------------------------------------------------------------
 | Unlink
 * ---------------------------------------------------------------------- */

it('unlinks an account while preserving the doctor and their history', function () {
    $treatment = Treatment::factory()->create();
    $user = dalDoctorUser();
    $doctor = Doctor::factory()->create(['user_id' => $user->id]);
    $visit = dalPaidVisit($this->branch, $doctor, $treatment, 250000);

    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->delete(route('settings.doctors.account-links.destroy', $doctor))
        ->assertRedirect(route('settings.doctors.account-links.index'));

    expect($doctor->fresh())->not->toBeNull()
        ->and($doctor->fresh()->user_id)->toBeNull()
        ->and(User::query()->find($user->id))->not->toBeNull()
        ->and($visit->fresh()->doctor_id)->toBe($doctor->id)
        ->and(RmeInvoice::query()->where('clinic_visit_id', $visit->id)->count())->toBe(1);
});

it('removes self access immediately after unlink', function () {
    $user = dalDoctorUser();
    $doctor = Doctor::factory()->create(['user_id' => $user->id]);

    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->delete(route('settings.doctors.account-links.destroy', $doctor));

    $this->actingAs($user->fresh())
        ->get(route('rme.reports.doctor-performance'))
        ->assertForbidden();
});

/* -------------------------------------------------------------------------
 | Audit
 * ---------------------------------------------------------------------- */

it('audits link, relink and unlink with technical identifiers only', function () {
    $doctor = Doctor::factory()->create(['user_id' => null]);
    $first = dalDoctorUser();
    $second = dalDoctorUser();
    $actor = userWith(['manage_doctor_account_links']);

    $this->actingAs($actor)->post(route('settings.doctors.account-links.store', $doctor), ['user_id' => $first->id]);
    $this->actingAs($actor)->post(route('settings.doctors.account-links.store', $doctor), [
        'user_id' => $second->id,
        'confirm_relink' => '1',
    ]);
    $this->actingAs($actor)->delete(route('settings.doctors.account-links.destroy', $doctor));

    $actions = AuditLog::query()
        ->where('entity_type', Doctor::class)
        ->where('entity_id', $doctor->id)
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($actions)->toBe([
        'DOCTOR_ACCOUNT_LINK',
        'DOCTOR_ACCOUNT_RELINK',
        'DOCTOR_ACCOUNT_UNLINK',
    ]);

    $log = AuditLog::query()->where('entity_id', $doctor->id)->orderBy('id')->first();
    expect((int) $log->performed_by)->toBe($actor->id);

    // Privacy: the audit payload carries identifiers, never personal detail.
    $payload = json_encode([$log->old_values, $log->new_values]);
    expect($payload)->not->toContain($first->email)
        ->and($payload)->not->toContain($first->name);
});

/* -------------------------------------------------------------------------
 | Kinerja + Pendapatan resolve through the link, server-side
 * ---------------------------------------------------------------------- */

it('gives a linked doctor their own kinerja and pendapatan', function () {
    $treatment = Treatment::factory()->create();
    $user = dalDoctorUser();
    $doctor = Doctor::factory()->create(['user_id' => $user->id]);
    dalPaidVisit($this->branch, $doctor, $treatment, 300000);

    $this->actingAs($user)
        ->get(route('rme.reports.doctor-performance'))
        ->assertOk();

    $access = app(DoctorPerformanceReportService::class)->resolveAccess($user->fresh());

    expect($access['mode'])->toBe('own')
        ->and($access['forced_doctor_id'])->toBe($doctor->id)
        ->and($access['can_pick_doctor'])->toBeFalse();
});

it('fails closed for an unlinked doctor account instead of showing another doctor', function () {
    $treatment = Treatment::factory()->create();
    $other = Doctor::factory()->create(['user_id' => null]);
    dalPaidVisit($this->branch, $other, $treatment, 400000);

    $user = dalDoctorUser();

    $this->actingAs($user)
        ->get(route('rme.reports.doctor-performance'))
        ->assertForbidden();

    $access = app(DoctorPerformanceReportService::class)->resolveAccess($user->fresh());
    expect($access['mode'])->toBe('unlinked')
        ->and($access['forced_doctor_id'])->toBeNull();
});

it('ignores a requested doctor_id for a linked doctor (IDOR)', function () {
    $treatment = Treatment::factory()->create();

    $userA = dalDoctorUser();
    $doctorA = Doctor::factory()->create(['name' => 'Dr. Aa', 'user_id' => $userA->id]);
    dalPaidVisit($this->branch, $doctorA, $treatment, 111000);

    $doctorB = Doctor::factory()->create(['name' => 'Dr. Bb', 'user_id' => null]);
    dalPaidVisit($this->branch, $doctorB, $treatment, 999000);

    $this->actingAs($userA)
        ->get(route('rme.reports.doctor-performance', ['doctor_id' => $doctorB->id]))
        ->assertOk()
        ->assertDontSee('999.000');

    $access = app(DoctorPerformanceReportService::class)->resolveAccess($userA->fresh());
    expect($access['forced_doctor_id'])->toBe($doctorA->id);
});

it('keeps kinerja and pendapatan on one identity source', function () {
    $user = dalDoctorUser();
    $doctor = Doctor::factory()->create(['user_id' => $user->id]);

    $resolver = app(DoctorIdentityResolver::class);
    $access = app(DoctorPerformanceReportService::class)->resolveAccess($user->fresh());

    expect($resolver->resolveForUser($user->fresh())?->id)->toBe($doctor->id)
        ->and($access['forced_doctor_id'])->toBe($resolver->resolveForUser($user->fresh())?->id);
});

/* -------------------------------------------------------------------------
 | Historical data is never rewritten by linkage
 * ---------------------------------------------------------------------- */

it('exposes pre-existing history after linking without mutating any row', function () {
    $treatment = Treatment::factory()->create();

    $doctor = Doctor::factory()->create(['user_id' => null]);
    dalPaidVisit($this->branch, $doctor, $treatment, 500000);

    // A colleague with their own history. Without this the assertion below is
    // blind to a blanket rewrite: every visit already pointed at $doctor, so
    // re-stamping them all with $doctor->id would write the same value and look
    // untouched. The colleague's rows are what make "no row moved" falsifiable.
    $colleague = Doctor::factory()->create(['user_id' => null]);
    dalPaidVisit($this->branch, $colleague, $treatment, 750000);

    $attribution = fn () => [
        'visits' => ClinicVisit::query()->orderBy('id')->pluck('doctor_id', 'id')->all(),
        'items' => RmeInvoiceItem::query()->orderBy('id')->pluck('doctor_id', 'id')->all(),
        'invoices' => RmeInvoice::query()->orderBy('id')->pluck('grand_total', 'id')->all(),
        'payments' => RmePayment::query()->orderBy('id')->pluck('amount', 'id')->all(),
    ];

    $before = $attribution();

    $user = dalDoctorUser();
    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->post(route('settings.doctors.account-links.store', $doctor), ['user_id' => $user->id]);

    expect($attribution())->toBe($before);

    // The history simply becomes reachable through the new identity link.
    $report = app(DoctorPerformanceReportService::class)
        ->report(app(DoctorPerformanceReportService::class)->resolveAccess($user->fresh()), []);

    expect($report)->not->toBeNull();
});

/* -------------------------------------------------------------------------
 | Name / email must never be runtime authority
 * ---------------------------------------------------------------------- */

it('never resolves a doctor from a matching email address', function () {
    $doctor = Doctor::factory()->create([
        'name' => 'Dr. Email Kembar',
        'email' => 'kembar@example.test',
        'user_id' => null,
    ]);
    $user = dalDoctorUser('kembar@example.test');

    expect(app(DoctorIdentityResolver::class)->resolveForUser($user))->toBeNull()
        ->and(app(DoctorUserResolver::class)->resolveForUser($user))->toBeNull()
        ->and(app(DoctorUserResolver::class)->resolveUserForDoctor($doctor))->toBeNull();

    // And the report refuses rather than handing over that doctor's income.
    $this->actingAs($user)
        ->get(route('rme.reports.doctor-performance'))
        ->assertForbidden();
});

it('never resolves a doctor from a matching display name', function () {
    $doctor = Doctor::factory()->create(['name' => 'Dr. Nama Sama', 'user_id' => null]);
    $user = User::factory()->create(['name' => 'Dr. Nama Sama', 'is_active' => true]);
    $user->assignRole('Doctor');

    expect(app(DoctorIdentityResolver::class)->resolveForUser($user->fresh()))->toBeNull();
});

/* -------------------------------------------------------------------------
 | Candidate selector — only eligible accounts are offered
 * ---------------------------------------------------------------------- */

it('offers only active, Doctor-role, unlinked accounts as candidates', function () {
    $eligible = dalDoctorUser();

    $alreadyLinked = dalDoctorUser();
    Doctor::factory()->create(['user_id' => $alreadyLinked->id]);

    $inactive = dalDoctorUser();
    $inactive->forceFill(['is_active' => false])->save();

    $wrongRole = User::factory()->create(['is_active' => true]);
    $wrongRole->assignRole('Kasir');

    Doctor::factory()->create(['user_id' => null]);

    $response = $this->actingAs(userWith(['manage_doctor_account_links']))
        ->get(route('settings.doctors.account-links.index'))
        ->assertOk();

    $response->assertSee($eligible->email)
        ->assertDontSee($inactive->email)
        ->assertDontSee($wrongRole->email);
});

/* -------------------------------------------------------------------------
 | Sidebar is UX only — never the security boundary
 * ---------------------------------------------------------------------- */

it('shows the Master Data entry to an authorized manager and hides it otherwise', function () {
    $this->actingAs(userWith(['manage_doctor_account_links']))
        ->get(route('settings.doctors.account-links.index'))
        ->assertOk()
        ->assertSee('Relasi Akun Dokter');

    $this->actingAs(userWith(['view dashboard', 'manage doctors']))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Relasi Akun Dokter');
});

/* -------------------------------------------------------------------------
 | Database-level one-to-one guarantee
 * ---------------------------------------------------------------------- */

it('enforces one account to one doctor at the database level', function () {
    $user = dalDoctorUser();
    Doctor::factory()->create(['user_id' => $user->id]);

    expect(fn () => Doctor::factory()->create(['user_id' => $user->id]))
        ->toThrow(QueryException::class);
});

/* -------------------------------------------------------------------------
 | Defence in depth — each guard is proven on its own, not just in aggregate
 |
 | The route middleware and the controller's policy check are deliberately
 | redundant. Asserting only "an unauthorized request gets 403" cannot tell the
 | two apart: remove either one and the other still answers 403, so a silently
 | weakened layer would look healthy. Each layer is therefore pinned directly.
 * ---------------------------------------------------------------------- */

it('grants the link ability only to the dedicated permission', function () {
    $authorized = userWith(['manage_doctor_account_links']);
    $doctorMaintainerOnly = userWith(['manage doctors']);
    $nobody = userWith([]);

    expect(Gate::forUser($authorized)->allows('manageAccountLink', Doctor::class))->toBeTrue()
        ->and(Gate::forUser($doctorMaintainerOnly)->allows('manageAccountLink', Doctor::class))->toBeFalse()
        ->and(Gate::forUser($nobody)->allows('manageAccountLink', Doctor::class))->toBeFalse();
});

it('refuses at the controller even when the route guard is not the one stopping it', function () {
    // With the route's permission middleware lifted, the controller's own
    // authorize() call must still refuse — otherwise the endpoint depends on a
    // single guard.
    $this->withoutMiddleware(PermissionMiddleware::class);

    $doctor = Doctor::factory()->create(['user_id' => null]);
    $user = dalDoctorUser();

    $this->actingAs(userWith(['manage doctors']))
        ->get(route('settings.doctors.account-links.index'))
        ->assertForbidden();

    $this->actingAs(userWith(['manage doctors']))
        ->post(route('settings.doctors.account-links.store', $doctor), ['user_id' => $user->id])
        ->assertForbidden();

    $this->actingAs(userWith(['manage doctors']))
        ->delete(route('settings.doctors.account-links.destroy', $doctor))
        ->assertForbidden();

    expect($doctor->fresh()->user_id)->toBeNull();
});

it('declares the permission guard on every account-link route', function () {
    foreach (['index', 'store', 'destroy'] as $action) {
        $route = Route::getRoutes()->getByName("settings.doctors.account-links.{$action}");

        expect($route)->not->toBeNull("route settings.doctors.account-links.{$action} must exist")
            ->and($route->gatherMiddleware())
            ->toContain('permission:manage_doctor_account_links');
    }
});
