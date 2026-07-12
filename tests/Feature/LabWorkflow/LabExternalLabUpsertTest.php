<?php

use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\LabOrder\Models\ExternalLab;
use App\Modules\LabOrder\Services\ExternalLabProvisioningService;
use App\Modules\LabOrder\Services\LabWorkflowPilotReadinessAuditor;

beforeEach(fn () => seedAccessControl());

/** LAB-OPS-READINESS-1 — governed external lab vendor upsert (service + command). */

// ---------------------------------------------------------------------------
// Service: ExternalLabProvisioningService::upsert()
// ---------------------------------------------------------------------------

it('creates an active vendor on apply and audit-logs', function () {
    $result = app(ExternalLabProvisioningService::class)->upsert([
        'name' => 'Lab Gigi Nyata',
        'phone' => '0411-123456',
        'notes' => 'Crown & bridge',
    ], apply: true);

    expect($result['created'])->toBeTrue()
        ->and($result['after']['is_active'])->toBeTrue();
    $lab = ExternalLab::query()->where('name', 'Lab Gigi Nyata')->first();
    expect($lab)->not->toBeNull()->and($lab->phone)->toBe('0411-123456');
    expect(AuditLog::query()->where('entity_type', 'mst_external_labs')->where('action', AuditLog::ACTION_CREATE)->count())->toBe(1);
});

it('dry-run creates nothing', function () {
    $result = app(ExternalLabProvisioningService::class)->upsert(['name' => 'Lab Dry'], apply: false);

    expect($result['applied'])->toBeFalse();
    expect(ExternalLab::query()->count())->toBe(0);
});

it('requires a name', function () {
    expect(fn () => app(ExternalLabProvisioningService::class)->upsert(['name' => '  '], apply: true))
        ->toThrow(RuntimeException::class);
});

it('is idempotent by name (case-insensitive), never duplicating', function () {
    app(ExternalLabProvisioningService::class)->upsert(['name' => 'Lab Unik'], apply: true);
    $second = app(ExternalLabProvisioningService::class)->upsert(['name' => 'lab unik'], apply: true);

    expect($second['created'])->toBeFalse();
    expect(ExternalLab::query()->count())->toBe(1);
});

it('deactivates and reactivates via is_active without deleting', function () {
    app(ExternalLabProvisioningService::class)->upsert(['name' => 'Lab Toggle'], apply: true);

    app(ExternalLabProvisioningService::class)->upsert(['name' => 'Lab Toggle', 'is_active' => false], apply: true);
    expect(ExternalLab::query()->where('name', 'Lab Toggle')->first()->is_active)->toBeFalse();
    expect(ExternalLab::query()->count())->toBe(1); // updated, not deleted

    app(ExternalLabProvisioningService::class)->upsert(['name' => 'Lab Toggle', 'is_active' => true], apply: true);
    expect(ExternalLab::query()->where('name', 'Lab Toggle')->first()->is_active)->toBeTrue();
});

it('restores a soft-deleted vendor of the same name instead of duplicating', function () {
    $lab = ExternalLab::factory()->create(['name' => 'Lab Trashed']);
    $lab->delete();
    expect(ExternalLab::query()->where('name', 'Lab Trashed')->count())->toBe(0); // soft-deleted, hidden

    app(ExternalLabProvisioningService::class)->upsert(['name' => 'Lab Trashed', 'is_active' => true], apply: true);

    expect(ExternalLab::query()->where('name', 'Lab Trashed')->count())->toBe(1)
        ->and(ExternalLab::withTrashed()->where('name', 'Lab Trashed')->count())->toBe(1); // restored, not duplicated
});

it('only persists allowlisted fields', function () {
    app(ExternalLabProvisioningService::class)->upsert([
        'name' => 'Lab Allow',
        'is_active' => true,
        'id' => 999,            // ignored
        'created_at' => '1999', // ignored
    ], apply: true);

    $lab = ExternalLab::query()->where('name', 'Lab Allow')->first();
    expect($lab->id)->not->toBe(999);
});

// ---------------------------------------------------------------------------
// Readiness closure
// ---------------------------------------------------------------------------

it('closes active_external_lab to GO and surfaces the vendor name', function () {
    app(ExternalLabProvisioningService::class)->upsert(['name' => 'Lab Eksternal Aktif'], apply: true);

    $report = app(LabWorkflowPilotReadinessAuditor::class)->audit();
    $check = collect($report['checks'])->firstWhere('key', 'active_external_lab');

    expect($check['status'])->toBe('GO')
        ->and($check['value']['count'])->toBeGreaterThanOrEqual(1)
        ->and(collect($check['value']['active_labs'])->pluck('name'))->toContain('Lab Eksternal Aktif');
});

it('leaves active_external_lab WATCH when the only vendor is inactive', function () {
    ExternalLab::factory()->inactive()->create();

    $report = app(LabWorkflowPilotReadinessAuditor::class)->audit();
    $check = collect($report['checks'])->firstWhere('key', 'active_external_lab');

    expect($check['status'])->toBe('WATCH')
        ->and($check['value']['count'])->toBe(0);
});

// ---------------------------------------------------------------------------
// Command: lab:external-lab-upsert
// ---------------------------------------------------------------------------

it('command requires --name', function () {
    $this->artisan('lab:external-lab-upsert', ['--apply' => true])->assertExitCode(2);
    expect(ExternalLab::query()->count())->toBe(0);
});

it('command dry-run then apply', function () {
    $this->artisan('lab:external-lab-upsert', ['--name' => 'Lab CLI'])->assertExitCode(0);
    expect(ExternalLab::query()->count())->toBe(0);

    $this->artisan('lab:external-lab-upsert', ['--name' => 'Lab CLI', '--apply' => true])->assertExitCode(0);
    expect(ExternalLab::query()->where('name', 'Lab CLI')->where('is_active', true)->count())->toBe(1);
});

it('command validates --active', function () {
    $this->artisan('lab:external-lab-upsert', ['--name' => 'Lab X', '--active' => 'maybe', '--apply' => true])
        ->assertExitCode(2);
});
