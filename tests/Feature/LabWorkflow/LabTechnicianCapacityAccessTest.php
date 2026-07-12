<?php

use App\Modules\Technician\Models\Technician;

beforeEach(fn () => seedAccessControl());

$indexUrl = '/lab/capacity-planning';
$exportUrl = '/lab/capacity-planning/export';
$configUrl = '/lab/capacity-planning/configuration';

it('redirects a guest to login', function () use ($indexUrl) {
    $this->get($indexUrl)->assertRedirect('/login');
});

it('denies a user with no capacity permission', function () use ($indexUrl) {
    $this->actingAs(userWith([]))->get($indexUrl)->assertForbidden();
});

it('denies clinical/cashier roles', function () use ($indexUrl) {
    $this->actingAs(userWith(['manage_clinic_visits']))->get($indexUrl)->assertForbidden();
    $this->actingAs(userWith(['manage_rme_billing']))->get($indexUrl)->assertForbidden();
});

it('allows the full view tier', function () use ($indexUrl) {
    $this->actingAs(userWith(['view_lab_technician_capacity']))
        ->get($indexUrl)
        ->assertOk()
        ->assertSee('Perencanaan Kapasitas Teknisi');
});

it('allows the manage tier and its configuration page', function () use ($indexUrl, $configUrl) {
    $user = userWith(['manage_lab_technician_capacity']);
    $this->actingAs($user)->get($indexUrl)->assertOk();
    $this->actingAs($user)->get($configUrl)->assertOk()->assertSee('Konfigurasi Perencanaan Kapasitas');
});

it('forbids the configuration page for a view-only user', function () use ($configUrl) {
    $this->actingAs(userWith(['view_lab_technician_capacity']))->get($configUrl)->assertForbidden();
});

it('own tier: a linked active technician sees only their own scope', function () use ($indexUrl) {
    $user = userWith(['view_own_lab_technician_capacity']);
    Technician::factory()->create(['user_id' => $user->id, 'is_active' => true, 'name' => 'Teknisi Sendiri']);

    $this->actingAs($user->fresh())->get($indexUrl)->assertOk()->assertSee('Teknisi Sendiri');
});

it('own tier without a linked technician is denied', function () use ($indexUrl) {
    $this->actingAs(userWith(['view_own_lab_technician_capacity']))->get($indexUrl)->assertForbidden();
});

it('own tier cannot see another technician (IDOR-forced)', function () use ($indexUrl) {
    $user = userWith(['view_own_lab_technician_capacity']);
    Technician::factory()->create(['user_id' => $user->id, 'is_active' => true, 'name' => 'Teknisi Sendiri']);
    $other = Technician::factory()->assignable()->create(['name' => 'Teknisi Lain']);

    // Even if the caller forges ?technician_id=<other>, the own tier ignores it.
    $this->actingAs($user->fresh())
        ->get($indexUrl.'?technician_id='.$other->id)
        ->assertOk()
        ->assertSee('Teknisi Sendiri')
        ->assertDontSee('Teknisi Lain');
});

it('export is forbidden without export capability', function () use ($exportUrl) {
    // view-only (no export, no manage) cannot export.
    $this->actingAs(userWith(['view_lab_technician_capacity']))->get($exportUrl)->assertForbidden();
});

it('export works for an export-capable user and is PII-free', function () use ($exportUrl) {
    $response = $this->actingAs(userWith(['view_lab_technician_capacity', 'export_lab_technician_capacity']))
        ->get($exportUrl)
        ->assertOk();

    $csv = $response->streamedContent();
    expect($csv)->toContain('Teknisi');
    expect(strtolower($csv))->not->toContain('ktp');
    expect(strtolower($csv))->not->toContain('nik');
});

it('manage tier can export (implicit)', function () use ($exportUrl) {
    $this->actingAs(userWith(['manage_lab_technician_capacity']))->get($exportUrl)->assertOk();
});
