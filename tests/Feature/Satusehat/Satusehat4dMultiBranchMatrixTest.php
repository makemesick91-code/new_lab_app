<?php

use App\Modules\Satusehat\Models\SatusehatRolloutWave;
use App\Modules\Satusehat\Models\SatusehatWaveBranchMembership;
use App\Modules\Satusehat\Services\Pilot\SatusehatBranchReadinessProfileService;
use App\Modules\Satusehat\Services\Pilot\SatusehatMultiBranchReadinessService;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    config()->set('satusehat.enabled', false);
    config()->set('satusehat.send_enabled', false);
    config()->set('satusehat.environment', 'sandbox');
    Http::preventStrayRequests();
    seedAccessControl();
});

it('builds a comparative matrix row per authorized branch and enforces scope', function () {
    $a = ssMakeVisit(['visit_date' => now()->toDateString()]);
    $b = ssMakeVisit(['visit_date' => now()->toDateString()]);

    $profiles = app(SatusehatBranchReadinessProfileService::class);
    $profiles->recalculate($a['branch']->id);
    $profiles->recalculate($b['branch']->id);

    $matrix = app(SatusehatMultiBranchReadinessService::class);

    // Only branch A is authorized → branch B must not appear.
    $rows = $matrix->matrix([$a['branch']->id]);
    $ids = array_column($rows, 'branch_id');

    expect($ids)->toContain($a['branch']->id)
        ->and($ids)->not->toContain($b['branch']->id);

    Http::assertNothingSent();
});

it('always surfaces the external credential blocker on every row and in the summary', function () {
    $a = ssMakeVisit(['visit_date' => now()->toDateString()]);
    app(SatusehatBranchReadinessProfileService::class)->recalculate($a['branch']->id);

    $matrix = app(SatusehatMultiBranchReadinessService::class);
    $rows = $matrix->matrix([$a['branch']->id]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['external_blocker'])->toBe('blocked_external_credential');

    $summary = $matrix->summary($rows);
    expect($summary['branches_total'])->toBe(1)
        ->and($summary['branches_blocked_external_credential'])->toBe(1);

    Http::assertNothingSent();
});

it('attributes an enrolled wave to the branch row', function () {
    $a = ssMakeVisit(['visit_date' => now()->toDateString()]);
    app(SatusehatBranchReadinessProfileService::class)->recalculate($a['branch']->id);

    $wave = SatusehatRolloutWave::create([
        'environment' => 'sandbox',
        'name' => 'Wave 1 — Pilot',
        'sequence' => 1,
        'status' => SatusehatRolloutWave::STATUS_PROFILING,
    ]);
    SatusehatWaveBranchMembership::create([
        'environment' => 'sandbox',
        'rollout_wave_id' => $wave->id,
        'branch_id' => $a['branch']->id,
        'status' => SatusehatWaveBranchMembership::STATUS_ENROLLED,
        'enrolled_at' => now(),
    ]);

    $rows = app(SatusehatMultiBranchReadinessService::class)->matrix([$a['branch']->id]);

    expect($rows[0]['wave_id'])->toBe($wave->id)
        ->and($rows[0]['wave_name'])->toBe('Wave 1 — Pilot');

    Http::assertNothingSent();
});

it('filters the matrix by wave and by search without network', function () {
    $a = ssMakeVisit(['visit_date' => now()->toDateString()]);
    $b = ssMakeVisit(['visit_date' => now()->toDateString()]);
    $profiles = app(SatusehatBranchReadinessProfileService::class);
    $profiles->recalculate($a['branch']->id);
    $profiles->recalculate($b['branch']->id);

    $wave = SatusehatRolloutWave::create([
        'environment' => 'sandbox', 'name' => 'W', 'sequence' => 1,
        'status' => SatusehatRolloutWave::STATUS_PROFILING,
    ]);
    SatusehatWaveBranchMembership::create([
        'environment' => 'sandbox', 'rollout_wave_id' => $wave->id,
        'branch_id' => $a['branch']->id, 'status' => 'enrolled', 'enrolled_at' => now(),
    ]);

    $matrix = app(SatusehatMultiBranchReadinessService::class);
    $authorized = [$a['branch']->id, $b['branch']->id];

    $byWave = $matrix->matrix($authorized, ['wave_id' => $wave->id]);
    expect(array_column($byWave, 'branch_id'))->toBe([$a['branch']->id]);

    $bySearch = $matrix->matrix($authorized, ['search' => $a['branch']->name]);
    expect(array_column($bySearch, 'branch_id'))->toContain($a['branch']->id);

    Http::assertNothingSent();
});

it('returns an empty matrix for an empty authorized set (fail-closed)', function () {
    expect(app(SatusehatMultiBranchReadinessService::class)->matrix([]))->toBe([]);
});
