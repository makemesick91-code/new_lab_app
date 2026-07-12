<?php

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\LabOrder;
use App\Modules\Production\Models\LabOrderAssignment;
use App\Modules\Technician\Models\Technician;
use App\Modules\Technician\Services\TechnicianAccountAuditor;

beforeEach(fn () => seedAccessControl());

/** LAB-OPS-READINESS-1 — governed technician master deactivation (service + command). */

// ---------------------------------------------------------------------------
// Service: TechnicianAccountAuditor::deactivateMaster()
// ---------------------------------------------------------------------------

it('dry-run reports the outcome without mutating', function () {
    $tech = Technician::factory()->create(['is_active' => true]);

    $result = app(TechnicianAccountAuditor::class)->deactivateMaster($tech->id, 'legacy faker master', apply: false);

    expect($result['applied'])->toBeFalse()
        ->and($result['before']['is_active'])->toBeTrue()
        ->and($result['after']['is_active'])->toBeFalse();
    expect($tech->fresh()->is_active)->toBeTrue(); // unchanged on disk
    expect(AuditLog::query()->where('entity_type', 'mst_technicians')->count())->toBe(0);
});

it('applies deactivation, preserves history (no soft-delete, keeps user_id) and audit-logs', function () {
    $tech = Technician::factory()->create(['is_active' => true, 'user_id' => null]);

    $result = app(TechnicianAccountAuditor::class)->deactivateMaster($tech->id, 'legacy faker master tidak digunakan', apply: true);

    expect($result['applied'])->toBeTrue();
    $fresh = Technician::withTrashed()->find($tech->id);
    expect($fresh->is_active)->toBeFalse()
        ->and($fresh->trashed())->toBeFalse();          // NOT soft-deleted — history stays readable

    $log = AuditLog::query()->where('entity_type', 'mst_technicians')->where('entity_id', $tech->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe(AuditLog::ACTION_UPDATE)
        ->and($log->new_values['reason'] ?? null)->toBe('legacy faker master tidak digunakan');
});

it('rejects an empty reason', function () {
    $tech = Technician::factory()->create(['is_active' => true]);

    expect(fn () => app(TechnicianAccountAuditor::class)->deactivateMaster($tech->id, '   ', apply: true))
        ->toThrow(RuntimeException::class);
});

it('refuses to deactivate while an assignment is ACTIVE', function () {
    $tech = Technician::factory()->create(['is_active' => true]);
    LabOrderAssignment::factory()->create([
        'technician_id' => $tech->id,
        'status' => LabOrderAssignment::STATUS_ASSIGNED,
    ]);

    expect(fn () => app(TechnicianAccountAuditor::class)->deactivateMaster($tech->id, 'r', apply: true))
        ->toThrow(RuntimeException::class);
    expect($tech->fresh()->is_active)->toBeTrue();
});

it('allows deactivation when the only active-status assignment is on a COMPLETED order (stale artifact)', function () {
    $tech = Technician::factory()->create(['is_active' => true]);
    $order = LabOrder::factory()->create(['status' => LabOrder::STATUS_COMPLETED]);
    LabOrderAssignment::factory()->create([
        'technician_id' => $tech->id,
        'lab_order_id' => $order->id,
        'status' => LabOrderAssignment::STATUS_ASSIGNED, // never closed → stale, but the order is done
    ]);

    $result = app(TechnicianAccountAuditor::class)->deactivateMaster($tech->id, 'legacy faker master', apply: true);

    expect($result['active_assignments'])->toBe(0)
        ->and($result['applied'])->toBeTrue()
        ->and($tech->fresh()->is_active)->toBeFalse();
});

it('still refuses when an active assignment is on a NON-terminal order', function () {
    $tech = Technician::factory()->create(['is_active' => true]);
    $order = LabOrder::factory()->create(['status' => LabOrder::STATUS_IN_PRODUCTION]);
    LabOrderAssignment::factory()->create([
        'technician_id' => $tech->id,
        'lab_order_id' => $order->id,
        'status' => LabOrderAssignment::STATUS_IN_PROGRESS,
    ]);

    expect(fn () => app(TechnicianAccountAuditor::class)->deactivateMaster($tech->id, 'r', apply: true))
        ->toThrow(RuntimeException::class);
    expect($tech->fresh()->is_active)->toBeTrue();
});

it('allows deactivation when only historical (DONE) assignments exist', function () {
    $tech = Technician::factory()->create(['is_active' => true]);
    LabOrderAssignment::factory()->done()->create(['technician_id' => $tech->id]);

    $result = app(TechnicianAccountAuditor::class)->deactivateMaster($tech->id, 'legacy', apply: true);

    expect($result['applied'])->toBeTrue()
        ->and($tech->fresh()->is_active)->toBeFalse();
    // History row still present and readable.
    expect(LabOrderAssignment::query()->where('technician_id', $tech->id)->count())->toBe(1);
});

it('is idempotent — a second apply writes nothing new', function () {
    $tech = Technician::factory()->create(['is_active' => true]);
    app(TechnicianAccountAuditor::class)->deactivateMaster($tech->id, 'legacy', apply: true);

    $second = app(TechnicianAccountAuditor::class)->deactivateMaster($tech->id, 'legacy', apply: true);

    expect($second['idempotent_no_op'])->toBeTrue()
        ->and($second['applied'])->toBeFalse();
    expect(AuditLog::query()->where('entity_type', 'mst_technicians')->count())->toBe(1);
});

it('fails on a missing technician', function () {
    expect(fn () => app(TechnicianAccountAuditor::class)->deactivateMaster(999999, 'r', apply: true))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------------------
// Audit metadata + closure behaviour
// ---------------------------------------------------------------------------

it('closes technician_accounts to GO after the only active orphan is deactivated', function () {
    Technician::factory()->assignable()->create();                 // the 1 eligible technician (kept)
    $orphan = Technician::factory()->create(['user_id' => null, 'is_active' => true]);

    $before = app(TechnicianAccountAuditor::class)->audit();
    expect($before['summary']['decision'])->toBe('WATCH')
        ->and($before['summary']['active_orphan_count'])->toBe(1);

    app(TechnicianAccountAuditor::class)->deactivateMaster($orphan->id, 'legacy faker master', apply: true);

    $after = app(TechnicianAccountAuditor::class)->audit();
    expect($after['summary']['decision'])->toBe('GO')
        ->and($after['summary']['active_orphan_count'])->toBe(0)
        ->and($after['summary']['inactive_technician_count'])->toBe(1)
        ->and($after['eligible_technician_count'])->toBeGreaterThanOrEqual(1);
});

// ---------------------------------------------------------------------------
// Command: lab:technician-deactivate
// ---------------------------------------------------------------------------

it('command requires --reason', function () {
    $tech = Technician::factory()->create(['is_active' => true]);

    $this->artisan('lab:technician-deactivate', ['--technician' => $tech->id, '--apply' => true])
        ->assertExitCode(2); // INVALID
    expect($tech->fresh()->is_active)->toBeTrue();
});

it('command dry-run then apply', function () {
    $tech = Technician::factory()->create(['is_active' => true]);

    $this->artisan('lab:technician-deactivate', ['--technician' => $tech->id, '--reason' => 'legacy'])
        ->assertExitCode(0);
    expect($tech->fresh()->is_active)->toBeTrue();

    $this->artisan('lab:technician-deactivate', ['--technician' => $tech->id, '--reason' => 'legacy', '--apply' => true])
        ->assertExitCode(0);
    expect($tech->fresh()->is_active)->toBeFalse();
});

it('command refuses --dry-run and --apply together', function () {
    $tech = Technician::factory()->create(['is_active' => true]);

    $this->artisan('lab:technician-deactivate', [
        '--technician' => $tech->id, '--reason' => 'r', '--dry-run' => true, '--apply' => true,
    ])->assertExitCode(2);
});
