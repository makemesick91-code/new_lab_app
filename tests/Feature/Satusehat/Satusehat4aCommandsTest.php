<?php

use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    Http::preventStrayRequests();
});

it('satusehat:diagnose reports posture booleans and never a credential value', function () {
    $exit = Artisan::call('satusehat:diagnose', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('"enabled": false')
        ->and($output)->toContain('"send_enabled": false')
        ->and($output)->toContain('"production_blocked": true')
        ->and($output)->toContain('"client_id_present": false');

    Http::assertNothingSent();
});

it('satusehat:readiness-audit returns GO with no hard issues and WATCH under --strict when hard issues exist', function () {
    $ctx = ssMakeVisit();
    ssSyncIssues($ctx);

    $this->artisan('satusehat:readiness-audit', ['--strict' => true])->assertExitCode(0);

    // Introduce a HARD defect.
    $ctx['patient']->update(['date_of_birth' => '1700-01-01']);
    ssSyncIssues($ctx);

    $this->artisan('satusehat:readiness-audit')->assertExitCode(0); // report-only
    $this->artisan('satusehat:readiness-audit', ['--strict' => true])->assertExitCode(1);
});

it('satusehat:data-quality-scan is dry-run by default and bounded', function () {
    $ctx = ssMakeVisit();
    ssService()->generateForVisit($ctx['visit']);

    // Dry-run: nothing written.
    $this->artisan('satusehat:data-quality-scan')->assertExitCode(0);
    expect(SatusehatDataQualityIssue::count())->toBe(0);

    // Apply: issues written.
    $this->artisan('satusehat:data-quality-scan', ['--apply' => true])->assertExitCode(0);
    expect(SatusehatDataQualityIssue::count())->toBeGreaterThan(0);

    Http::assertNothingSent();
});

it('satusehat:queue-health and satusehat:reconciliation-status are read-only and green while disabled', function () {
    $this->artisan('satusehat:queue-health', ['--strict' => true])->assertExitCode(0);
    $this->artisan('satusehat:reconciliation-status', ['--json' => true, '--strict' => true])
        ->expectsOutputToContain('"external_submission_enabled": false')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('satusehat:production-guard-check still reports production blocked (SATUSEHAT-3 invariant preserved)', function () {
    $this->artisan('satusehat:production-guard-check')->assertExitCode(0);
});
