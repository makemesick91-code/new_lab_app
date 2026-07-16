<?php

use App\Modules\Satusehat\Models\SatusehatCandidate;

require_once __DIR__.'/helpers.php';

beforeEach(fn () => config()->set('satusehat.candidate.auto_generate', false));

it('dry-run counts eligible visits but creates nothing', function () {
    ssMakeVisit();

    $this->artisan('satusehat:backfill-candidates', ['--dry-run' => true])
        ->assertSuccessful();

    expect(SatusehatCandidate::count())->toBe(0);
});

it('backfills candidates and is idempotent', function () {
    ssMakeVisit();

    $this->artisan('satusehat:backfill-candidates')->assertSuccessful();
    expect(SatusehatCandidate::count())->toBe(1);

    // Re-running never duplicates.
    $this->artisan('satusehat:backfill-candidates')->assertSuccessful();
    expect(SatusehatCandidate::count())->toBe(1);
});

it('respects the per-run limit (never an unbounded scan)', function () {
    ssMakeVisit();
    ssMakeVisit();
    ssMakeVisit();

    $this->artisan('satusehat:backfill-candidates', ['--limit' => 1])->assertSuccessful();

    expect(SatusehatCandidate::count())->toBe(1);
});
