<?php

use App\Models\User;
use App\Modules\Technician\Models\Technician;
use App\Modules\Technician\Services\TechnicianAccountAuditor;
use App\Modules\Technician\Services\TechnicianAssignmentEligibility;
use Spatie\Permission\Models\Role;

beforeEach(fn () => seedAccessControl());

/** An active user that already holds the Technician role (link target). */
function technicianRoleUser(bool $active = true): User
{
    $user = User::factory()->create(['is_active' => $active]);
    Role::findOrCreate(TechnicianAssignmentEligibility::ROLE, 'web');
    $user->assignRole(TechnicianAssignmentEligibility::ROLE);

    return $user;
}

// ---------------------------------------------------------------------------
// audit()
// ---------------------------------------------------------------------------

it('reports GO with at least one eligible technician', function () {
    Technician::factory()->assignable()->create();

    $report = app(TechnicianAccountAuditor::class)->audit();

    expect($report['eligible_technician_count'])->toBeGreaterThanOrEqual(1)
        ->and($report['summary']['decision'])->toBe('GO');
});

it('reports NO-GO when there is no eligible technician (the documented pilot blocker)', function () {
    $report = app(TechnicianAccountAuditor::class)->audit();

    expect($report['eligible_technician_count'])->toBe(0)
        ->and($report['summary']['decision'])->toBe('NO-GO')
        ->and($report['summary']['critical_codes'])->toContain('no_eligible_technician');
});

it('flags an orphan technician (active master, no user link) as WATCH', function () {
    Technician::factory()->assignable()->create();               // keeps one eligible → not NO-GO
    Technician::factory()->create(['user_id' => null, 'is_active' => true]);

    $report = app(TechnicianAccountAuditor::class)->audit();

    expect($report['summary']['decision'])->toBe('WATCH')
        ->and($report['summary']['anomaly_codes'])->toContain('orphan_technician_no_user');
});

it('flags a linked user without the Technician role as WATCH', function () {
    Technician::factory()->assignable()->create();
    $user = User::factory()->create(['is_active' => true]);       // no Technician role
    Technician::factory()->create(['user_id' => $user->id, 'is_active' => true]);

    $report = app(TechnicianAccountAuditor::class)->audit();

    expect($report['summary']['anomaly_codes'])->toContain('technician_user_missing_role');
});

it('flags a duplicate user link as critical NO-GO', function () {
    $user = technicianRoleUser();
    Technician::factory()->create(['user_id' => $user->id, 'is_active' => true]);
    Technician::factory()->create(['user_id' => $user->id, 'is_active' => true]);

    $report = app(TechnicianAccountAuditor::class)->audit();

    expect($report['summary']['decision'])->toBe('NO-GO')
        ->and($report['summary']['critical_codes'])->toContain('duplicate_user_link');
});

// ---------------------------------------------------------------------------
// linkUser()
// ---------------------------------------------------------------------------

it('previews a link in dry-run without persisting and projects eligibility', function () {
    $tech = Technician::factory()->create(['user_id' => null, 'is_active' => true]);
    $user = technicianRoleUser();

    $result = app(TechnicianAccountAuditor::class)->linkUser($tech->id, $user->id, apply: false);

    expect($result['applied'])->toBeFalse()
        ->and($result['after']['eligible'])->toBeTrue();
    expect($tech->fresh()->user_id)->toBeNull();                 // nothing persisted
});

it('applies a link and is idempotent on re-run', function () {
    $tech = Technician::factory()->create(['user_id' => null, 'is_active' => true]);
    $user = technicianRoleUser();
    $svc = app(TechnicianAccountAuditor::class);

    $first = $svc->linkUser($tech->id, $user->id, apply: true);
    expect($first['applied'])->toBeTrue();
    expect($tech->fresh()->user_id)->toBe($user->id);

    $second = $svc->linkUser($tech->id, $user->id, apply: true);
    expect($second['idempotent_no_op'])->toBeTrue();
});

it('refuses to link a user that lacks the Technician role (never changes the role)', function () {
    $tech = Technician::factory()->create(['user_id' => null, 'is_active' => true]);
    $user = User::factory()->create(['is_active' => true]);      // no role

    expect(fn () => app(TechnicianAccountAuditor::class)->linkUser($tech->id, $user->id, apply: true))
        ->toThrow(RuntimeException::class);

    expect($tech->fresh()->user_id)->toBeNull();
});

it('refuses an ambiguous link (user already linked to another active technician)', function () {
    $user = technicianRoleUser();
    Technician::factory()->create(['user_id' => $user->id, 'is_active' => true]);
    $other = Technician::factory()->create(['user_id' => null, 'is_active' => true]);

    expect(fn () => app(TechnicianAccountAuditor::class)->linkUser($other->id, $user->id, apply: true))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// command exit codes
// ---------------------------------------------------------------------------

it('strict command exits 2 when anomalies exist', function () {
    $this->artisan('lab:technician-account-audit', ['--strict' => true])->assertExitCode(2);
});

it('strict command exits 0 with a clean eligible technician', function () {
    Technician::factory()->assignable()->create();

    $this->artisan('lab:technician-account-audit', ['--strict' => true])->assertExitCode(0);
});
