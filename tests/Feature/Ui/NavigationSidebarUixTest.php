<?php

/*
 * UIX-19 — Navigation, sidebar & information-architecture polish.
 *
 * These tests assert navigation clarity + permission-aware rendering:
 * critical entries and IA section labels render for authorized roles, and
 * unauthorized links/sections stay hidden. Sidebar @can/@canany is presentation
 * only — server-side route middleware/policy remains authoritative — so these
 * tests must never be used as an access-control substitute.
 */

use App\Modules\Branch\Models\Branch;

beforeEach(function () {
    seedAccessControl();
});

it('renders IA section labels via the menu-group-title primitive for a privileged operator', function () {
    $this->actingAs(userInRole('Super Admin'))
        ->get(route('dashboard'))
        ->assertOk()
        // IA section labels (presentation-only, semantic-token primitive).
        ->assertSee('menu-group-title', false)
        ->assertSee('Klinik & RME')
        ->assertSee('Laboratorium')
        ->assertSee('Inventaris & Pembelian')
        ->assertSee('Keuangan & Laporan')
        ->assertSee('Administrasi Sistem')
        // Critical module entries remain discoverable.
        ->assertSee('Dashboard RME')
        ->assertSee('Order Lab');
    // Note: shell-scoped legacy-chrome (teal/gray) is enforced by
    // architecture:ui-governance-check (UIX-19), not a whole-page assertDontSee —
    // other module surfaces are out of the navigation-shell scope.
});

it('keeps the clinical section label but hides admin/lab/finance sections for a clinical-only Doctor', function () {
    $this->actingAs(doctorWithOnlineContext())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Klinik & RME')
        ->assertSee('Dashboard RME')
        // Doctor is clinical-only: these IA sections must not leak.
        ->assertDontSee('Laboratorium')
        ->assertDontSee('Inventaris & Pembelian')
        ->assertDontSee('Keuangan & Laporan')
        ->assertDontSee('Administrasi Sistem');
});

it('shows the administration section label only when the user can reach an admin module', function () {
    // Super Admin can reach users/roles/permissions → section label present.
    $this->actingAs(userInRole('Super Admin'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Administrasi Sistem')
        ->assertSee('Pengaturan');

    // Admin Klinik has master-data permissions but the group is role-hidden and it
    // has no users/roles/permissions and no developer console → no admin label.
    $adminBranch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $admin = userInRole('Admin Klinik');
    rmeMakeAdminClinicActive($admin, $adminBranch);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Klinik & RME')
        ->assertDontSee('Administrasi Sistem')
        ->assertDontSee('Master Data RME');
});

it('redirects an unauthenticated visitor away from the dashboard (navigation is not a security boundary)', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});
