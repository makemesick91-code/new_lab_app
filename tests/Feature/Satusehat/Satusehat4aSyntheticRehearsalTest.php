<?php

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\Patient\Models\Patient;
use App\Modules\Satusehat\Models\SatusehatAuditLog;
use App\Modules\Satusehat\Services\DataQuality\SatusehatRehearsalService;
use App\Modules\Satusehat\Services\DataQuality\SatusehatSyntheticPilotService;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    Http::preventStrayRequests();
    seedAccessControl();
});

it('seeds an idempotent, isolated synthetic pack and verifies it', function () {
    $service = app(SatusehatSyntheticPilotService::class);
    $actor = superAdmin();

    $first = $service->seed($actor);
    $second = $service->seed($actor);

    expect($second['clinic_visit_id'])->toBe($first['clinic_visit_id'])
        ->and(ClinicVisit::query()->where('visit_number', 'SYN4A-0001')->count())->toBe(1)
        ->and(Branch::query()->where('code', 'SYN4A')->count())->toBe(1);

    $verify = $service->verify();
    expect(collect($verify)->every(fn ($v) => $v === true))->toBeTrue();

    Http::assertNothingSent();
});

it('the synthetic patient uses the synthetic KTP marker, never real data', function () {
    $service = app(SatusehatSyntheticPilotService::class);
    $service->seed(superAdmin());

    $patient = Patient::query()->where('medical_record_number', 'SYN4A-0001')->firstOrFail();

    expect($patient->ktp_number)->toBe((string) config('satusehat_data_quality.synthetic.patient_ktp'))
        ->and($patient->name)->toContain('[SYNTHETIC-SATUSEHAT-4A]');
});

it('rehearsal ends honestly at BLOCKED_EXTERNAL_CREDENTIAL with a clean internal pipeline', function () {
    app(SatusehatSyntheticPilotService::class)->seed(superAdmin());

    $result = app(SatusehatRehearsalService::class)->rehearse();

    $stageStatuses = collect($result['stages'])->pluck('status', 'stage');

    expect($result['final_state'])->toBe('BLOCKED_EXTERNAL_CREDENTIAL')
        ->and($result['internal_pipeline_clean'])->toBeTrue()
        ->and($stageStatuses['outbound_gate'])->toBe('pass')     // gateway disabled = pass
        ->and($stageStatuses['production_guard'])->toBe('pass')  // production blocked = pass
        ->and($stageStatuses['source_revalidation'])->toBe('pass')
        ->and(json_encode($result))->not->toContain('submitted')
        ->and(json_encode($result))->not->toContain('succeeded');

    Http::assertNothingSent();

    // Rehearsal evidence lands in the append-only audit log.
    expect(SatusehatAuditLog::query()->where('event', SatusehatAuditLog::EVENT_REHEARSAL_RUN)->exists())->toBeTrue();
});

it('rehearsal without the synthetic pack fails gracefully', function () {
    $result = app(SatusehatRehearsalService::class)->rehearse();

    expect($result['final_state'])->toBe('MISSING_SYNTHETIC_PACK');
});

it('reset removes ONLY campaign records and never touches real data', function () {
    // Real clinical data that must survive.
    $real = ssMakeVisit();

    $service = app(SatusehatSyntheticPilotService::class);
    $service->seed(superAdmin());
    $counts = $service->reset(superAdmin());

    expect(ClinicVisit::query()->where('visit_number', 'SYN4A-0001')->exists())->toBeFalse()
        ->and(Patient::query()->where('medical_record_number', 'SYN4A-0001')->exists())->toBeFalse()
        ->and(Branch::query()->where('code', 'SYN4A')->exists())->toBeFalse()
        ->and($counts['visits'])->toBeGreaterThanOrEqual(1)
        // Real data untouched:
        ->and(ClinicVisit::query()->whereKey($real['visit']->id)->exists())->toBeTrue()
        ->and(Patient::query()->whereKey($real['patient']->id)->exists())->toBeTrue();
});

it('the synthetic-pilot command requires --confirm for writes and the rehearse command requires --synthetic', function () {
    superAdmin(); // at least one user (created_by/cashier_id fallback)

    $this->artisan('satusehat:synthetic-pilot', ['action' => 'seed'])->assertExitCode(1);
    $this->artisan('satusehat:synthetic-pilot', ['action' => 'seed', '--confirm' => true])->assertExitCode(0);
    $this->artisan('satusehat:synthetic-pilot', ['action' => 'verify'])->assertExitCode(0);

    $this->artisan('satusehat:rehearse')->assertExitCode(1);
    $this->artisan('satusehat:rehearse', ['--synthetic' => true, '--dry-run' => true])->assertExitCode(0);

    $this->artisan('satusehat:synthetic-pilot', ['action' => 'reset'])->assertExitCode(1);
    $this->artisan('satusehat:synthetic-pilot', ['action' => 'reset', '--confirm' => true])->assertExitCode(0);
});

it('rehearsal with prepare-batch stays blocked_external without identifiers (no batch leaks out)', function () {
    app(SatusehatSyntheticPilotService::class)->seed(superAdmin());
    $actor = superAdmin();

    $result = app(SatusehatRehearsalService::class)->rehearse($actor, prepareBatch: true, dryRun: false);
    $stage = collect($result['stages'])->firstWhere('stage', 'batch_preparation');

    expect($stage['status'])->toBe('blocked_external')
        ->and($result['final_state'])->toBe('BLOCKED_EXTERNAL_CREDENTIAL');

    Http::assertNothingSent();
});
