<?php

use App\Services\Foundation\FiveBranchRolloutReadinessService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('Foundation', 'RolloutReadiness', 'RollFive');

beforeEach(function () {
    seedAccessControl();
});

function rolloutSanitizeJson(): string
{
    $output = new BufferedOutput;
    Artisan::call('rollout:five-branch-readiness', ['--include-audits' => true, '--capacity-smoke' => true, '--json' => true], $output);

    return $output->fetch();
}

it('never leaks the app key or DB password in the JSON report', function () {
    $raw = rolloutSanitizeJson();

    expect($raw)->not->toContain((string) config('app.key'));

    $dbPassword = (string) config('database.connections.'.config('database.default').'.password');
    if ($dbPassword !== '') {
        expect($raw)->not->toContain($dbPassword);
    }
});

it('never renders a full 16-digit KTP/NIK-shaped number in the report', function () {
    $raw = rolloutSanitizeJson();

    // No unmasked 15-16 digit identity-shaped run should ever appear.
    expect(preg_match('/\b\d{15,16}\b/', $raw))->toBe(0);
});

it('renders the UI without exposing secrets, env values, or raw stack traces', function () {
    $html = $this->actingAs(superAdmin())
        ->get(route('foundation.rollout.five-branch-readiness'))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain((string) config('app.key'))
        ->and($html)->not->toContain((string) config('database.connections.'.config('database.default').'.password') ?: '___never___')
        ->and($html)->not->toContain('APP_KEY')
        ->and($html)->not->toContain('DB_PASSWORD')
        ->and(preg_match('/\b\d{15,16}\b/', $html))->toBe(0);
});

it('registers no expensive command as an auto-run signal on the web page', function () {
    // The controller collects without include_audits/capacity_smoke, so no
    // audit command or capacity probe executes on a web request.
    $report = app(FiveBranchRolloutReadinessService::class)->collect();

    expect($report['include_audits'])->toBeFalse()
        ->and($report['capacity_smoke'])->toBeFalse();

    $capacity = collect($report['signals'])->firstWhere('key', 'capacity_smoke');
    expect($capacity['details']['executed'])->toBeFalse();
});
