<?php

/**
 * REVISION-DOCTOR-PWA-WEBAUTHN-ONLY-ACCESS-1 Stage 3 — the device-loss drill
 * evidence producer.
 *
 * The property under test is not "can it write a file". It is that the EASY
 * paths do not produce a passing gate: a template cannot be signed, a pass
 * cannot be recorded without an attributable human attestation, and the
 * template marker cannot reach a real record.
 */

use App\Modules\DoctorAccess\Services\DoctorGlobalEnforcementReadinessService;
use Illuminate\Support\Facades\Storage;

function dldPath(): string
{
    return (string) config('doctor_global_enforcement_readiness.device_loss_rehearsal.evidence_path');
}

function dldGate(): array
{
    $report = app(DoctorGlobalEnforcementReadinessService::class)->build();

    return (array) (($report['prerequisites'] ?? [])['device_loss_runbook_rehearsed'] ?? []);
}

beforeEach(function () {
    Storage::fake('local');
});

it('reports UNVERIFIED when nobody has rehearsed, rather than FAIL', function () {
    // Absence is the ordinary state of a rung nobody has reached. A gate that
    // is red for months gets deleted rather than fixed.
    expect(dldGate()['measured'] ?? null)->toBe('UNVERIFIED');
});

it('writes a template that deliberately does not satisfy the gate', function () {
    $this->artisan('doctor:device-loss-drill', ['--create-template' => true])
        ->assertExitCode(0);

    expect(Storage::disk('local')->exists(dldPath()))->toBeTrue();

    // A template that validates is a template that gets signed.
    expect(dldGate()['measured'] ?? null)->toBe('UNVERIFIED');
});

it('refuses to record a pass without an attributable attestation', function () {
    $this->artisan('doctor:device-loss-drill', [
        '--record' => true,
        '--drill-id' => 'SPN4-2026-09-18',
        '--runbook' => 'docs/runbooks/doctor-device-loss-rehearsal.md',
        '--outcome' => 'passed',
        '--clinician-regained-access' => 'yes',
    ])->assertExitCode(1);

    expect(Storage::disk('local')->exists(dldPath()))->toBeFalse();
});

it('refuses a real record that carries the template marker', function () {
    $this->artisan('doctor:device-loss-drill', [
        '--record' => true,
        '--drill-id' => 'TEMPLATE-sneaky',
        '--runbook' => 'docs/runbooks/doctor-device-loss-rehearsal.md',
        '--outcome' => 'failed',
        '--clinician-regained-access' => 'no',
    ])->assertExitCode(1);

    expect(Storage::disk('local')->exists(dldPath()))->toBeFalse();
});

it('records a FAILED rehearsal, because a drill that went badly is still evidence', function () {
    $this->artisan('doctor:device-loss-drill', [
        '--record' => true,
        '--drill-id' => 'SPN4-2026-09-18-failed',
        '--runbook' => 'docs/runbooks/doctor-device-loss-rehearsal.md',
        '--outcome' => 'failed',
        '--clinician-regained-access' => 'no',
        '--notes' => 'Break-glass grant did not end the open session on revoke.',
    ])->assertExitCode(0);

    $payload = json_decode((string) Storage::disk('local')->get(dldPath()), true);

    expect($payload['outcome'])->toBe('failed')
        ->and($payload['clinician_regained_access'])->toBeFalse();

    // Recorded, and still not a pass.
    expect(dldGate()['measured'] ?? null)->not->toBe('PASS');
});

it('moves the gate to PASS only for an attested, completed rehearsal', function () {
    $this->artisan('doctor:device-loss-drill', [
        '--record' => true,
        '--drill-id' => 'SPN4-2026-09-18',
        '--runbook' => 'docs/runbooks/doctor-device-loss-rehearsal.md',
        '--outcome' => 'passed',
        '--clinician-regained-access' => 'yes',
        '--performed-by' => 'Supervisor RME',
        '--notes' => 'Tablet withheld; break-glass granted; revoke ended the open session.',
        '--confirm-performed' => true,
    ])->assertExitCode(0);

    expect(dldGate()['measured'] ?? null)->toBe('PASS');
});
