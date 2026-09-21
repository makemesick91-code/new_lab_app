<?php

/**
 * DOCTOR-DEVICE-GUIDED-REGISTRATION-WORKFLOW-1 — who may reach the guided
 * workflow, and what the sidebar is allowed to imply.
 *
 * THE POINT OF THIS FILE. The workflow spans two authorities on purpose:
 * Supervisor RME FILES a tablet (`register_doctor_devices`), and only
 * `manage_doctor_devices` enrols its credential and admits it into service.
 * That split is D11's, and a seven-step wizard is exactly the shape of change
 * that quietly collapses it — one convenience route, one widened permission,
 * and the second party disappears. So the matrix below is asserted per ROUTE
 * per ROLE, and it is written as an explicit table rather than a loop over a
 * PHP array keyed by role, because a duplicate key in such an array silently
 * drops a row and the test still passes.
 *
 * The sidebar is checked too, but never as the boundary: every assertion about
 * a menu item is paired with an assertion about the route itself.
 */

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\DoctorDevice\Models\DoctorDevice;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;

beforeEach(function () {
    seedAccessControl();

    // A usable relying party, or step 2 renders its "configuration not ready"
    // branch and the enrolment control is legitimately absent for everyone —
    // which would make the two-party assertions below pass for the wrong reason.
    // https, matching DoctorPwaWebAuthnTest: an http origin is rejected as
    // `insecure_origin`, which would render the "configuration not ready"
    // branch and make the two-party assertions pass for the wrong reason.
    config()->set('app.url', 'https://clinic.example.test');
    config()->set('webauthn.relying_party.id', 'clinic.example.test');
    config()->set('webauthn.relying_party.allowed_origins', 'https://clinic.example.test');

    $this->branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $this->device = DoctorDevice::factory()->create([
        'branch_id' => $this->branch->id,
        'status' => DoctorDevice::STATUS_PENDING_APPROVAL,
    ]);
});

function regwfUser(?string $role = null): User
{
    $user = User::factory()->create();

    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user->fresh();
}

function regwfActingAs(User $user)
{
    return test()->actingAs($user)->withoutMiddleware(EnsureRmeOnlineContext::class);
}

// ---------------------------------------------------------------------------
// 1 / 2 — the menu item follows the permission
// ---------------------------------------------------------------------------

it('shows Pendaftaran Device Dokter to the filing authority and links it to the workflow', function () {
    $response = regwfActingAs(regwfUser('Supervisor RME'))->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Pendaftaran Device Dokter')
        ->assertSee(route('settings.doctor-device-registration.index'));
});

it('shows it to Super Admin, who reaches it through the global bypass', function () {
    regwfActingAs(regwfUser('Super Admin'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('settings.doctor-device-registration.index'));
});

it('hides it from roles that hold none of the device permissions', function (string $role) {
    // Admin Lab is deliberately absent from this dataset: it is a Lab-only
    // role and `/dashboard` itself is 403 for it (FIX-ADMIN-LAB-LAB-ONLY-
    // ACCESS), so asserting on the sidebar it never receives would be
    // asserting nothing. Its exclusion from the workflow is covered by the
    // direct-URL test below, which is the boundary that matters anyway.
    regwfActingAs(regwfUser($role))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('settings.doctor-device-registration.index'));
})->with(['Doctor', 'Front Office', 'Kasir']);

it('keeps Approval Device Dokter where it was, outside Master Data', function () {
    // D-2: the approval inbox is a top-level operational group on purpose. A
    // workflow that "tidied" it under Master Data would put a daily queue
    // behind a security-administration screen.
    regwfActingAs(regwfUser('Supervisor RME'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('doctor-device-authorizations.index'));
});

// ---------------------------------------------------------------------------
// 3 — direct URL access, with no sidebar involved
// ---------------------------------------------------------------------------

it('refuses the workflow index to a role with no device permission', function (string $role) {
    regwfActingAs(regwfUser($role))
        ->get(route('settings.doctor-device-registration.index'))
        ->assertForbidden();
})->with(['Doctor', 'Front Office', 'Kasir', 'Admin Lab', 'Perawat']);

it('refuses every workflow page to a doctor who types the URL', function (string $routeName) {
    regwfActingAs(regwfUser('Doctor'))
        ->get(route('settings.doctor-device-registration.'.$routeName, test()->device))
        ->assertForbidden();
})->with(['show', 'device', 'webauthn', 'approval', 'doctors', 'login-test', 'readiness', 'complete', 'history']);

it('refuses an unauthenticated visitor and sends them to login', function () {
    $this->get(route('settings.doctor-device-registration.index'))
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// The matrix — every GET page, both privileged roles
// ---------------------------------------------------------------------------

it('lets the filing authority READ every step of the shared workflow', function (string $routeName) {
    // The workflow is shared, not single-operator: a filer must be able to see
    // the steps they cannot perform, or "Menunggu Super Admin" has nowhere to
    // render.
    regwfActingAs(regwfUser('Supervisor RME'))
        ->get(route('settings.doctor-device-registration.'.$routeName, test()->device))
        ->assertOk();
})->with(['show', 'device', 'webauthn', 'approval', 'doctors', 'login-test', 'readiness', 'history']);

it('lets Super Admin read every step too', function (string $routeName) {
    regwfActingAs(regwfUser('Super Admin'))
        ->get(route('settings.doctor-device-registration.'.$routeName, test()->device))
        ->assertOk();
})->with(['show', 'device', 'webauthn', 'approval', 'doctors', 'login-test', 'readiness', 'history']);

// ---------------------------------------------------------------------------
// The two-party relay, rendered
// ---------------------------------------------------------------------------

it('tells the filer whose turn it is instead of showing them a dead button', function () {
    $response = regwfActingAs(regwfUser('Supervisor RME'))
        ->get(route('settings.doctor-device-registration.webauthn', $this->device));

    $response->assertOk()
        ->assertSee('Menunggu Super Admin')
        // and NOT the enrolment control itself: no ceremony form, no button,
        // and no handler shipped for a control that is not on the page.
        ->assertDontSee(route('settings.doctor-devices.webauthn.store', test()->device))
        ->assertDontSee('Daftarkan Perangkat Ini')
        ->assertDontSee('data-webauthn-start', false);
});

it('gives the device manager the enrolment control on the same page', function () {
    regwfActingAs(regwfUser('Super Admin'))
        ->get(route('settings.doctor-device-registration.webauthn', $this->device))
        ->assertOk()
        ->assertSee(route('settings.doctor-devices.webauthn.store', $this->device))
        ->assertSee('Daftarkan Perangkat Ini')
        // The handler must actually ship, or the button is decoration.
        ->assertSee('data-webauthn-start', false)
        ->assertSee('doctorDeviceWebAuthn', false);
});

it('offers approval only to the authority that owns the trust decision', function () {
    regwfActingAs(regwfUser('Supervisor RME'))
        ->get(route('settings.doctor-device-registration.approval', $this->device))
        ->assertOk()
        ->assertSee('Menunggu Super Admin')
        ->assertDontSee(route('settings.doctor-devices.approve-registration', $this->device));

    regwfActingAs(regwfUser('Super Admin'))
        ->get(route('settings.doctor-device-registration.approval', $this->device))
        ->assertOk()
        ->assertSee(route('settings.doctor-devices.approve-registration', $this->device));
});

// ---------------------------------------------------------------------------
// 18 — the sub-sidebar is on every page, not just the first
// ---------------------------------------------------------------------------

it('renders the workflow sub-sidebar on every page of the workflow', function (string $routeName) {
    $response = regwfActingAs(regwfUser('Super Admin'))
        ->get(route('settings.doctor-device-registration.'.$routeName, test()->device));

    $response->assertOk()
        ->assertSee('data-registration-subnav', false)
        ->assertSee('Ringkasan')
        ->assertSee('Riwayat');

    // Every numbered step is reachable from wherever the operator is standing.
    foreach (['device', 'webauthn', 'approval', 'doctors', 'login-test', 'readiness', 'complete'] as $step) {
        $response->assertSee(route('settings.doctor-device-registration.'.$step, test()->device));
    }
})->with(['show', 'device', 'webauthn', 'approval', 'doctors', 'login-test', 'readiness', 'history']);

// ---------------------------------------------------------------------------
// 20 / 21 — the surfaces this workflow is a guide OVER must not change
// ---------------------------------------------------------------------------

it('leaves the existing Device Dokter registry reachable and unchanged', function () {
    regwfActingAs(regwfUser('Super Admin'))
        ->get(route('settings.doctor-devices.index'))
        ->assertOk();

    regwfActingAs(regwfUser('Supervisor RME'))
        ->get(route('settings.doctor-devices.index'))
        ->assertOk();
});

it('leaves the existing Approval Device Dokter inbox reachable and unchanged', function () {
    regwfActingAs(regwfUser('Supervisor RME'))
        ->get(route('doctor-device-authorizations.index'))
        ->assertOk();
});

it('does not let the workflow grant the device-registry management permission to anyone new', function () {
    $filer = regwfUser('Supervisor RME');

    // The whole D11 split in one assertion: filing yes, managing no.
    expect($filer->can('register_doctor_devices'))->toBeTrue()
        ->and($filer->can('manage_doctor_devices'))->toBeFalse()
        ->and($filer->can('view_doctor_devices'))->toBeFalse();
});
