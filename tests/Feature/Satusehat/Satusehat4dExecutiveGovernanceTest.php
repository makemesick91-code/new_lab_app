<?php

use App\Models\User;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Services\Pilot\SatusehatBranchReadinessProfileService;
use App\Modules\Satusehat\Services\Pilot\SatusehatCrossBranchIssueService;
use App\Modules\Satusehat\Services\Pilot\SatusehatExecutiveReadinessService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    config()->set('satusehat.enabled', false);
    config()->set('satusehat.send_enabled', false);
    config()->set('satusehat.environment', 'sandbox');
    Http::preventStrayRequests();
    seedAccessControl();
});

it('bulk-assigns only issues within the authorized branch scope (IDOR-safe)', function () {
    $a = ssMakeVisit();
    $b = ssMakeVisit();
    ssService()->generateForVisit($a['visit']->fresh(['medicalRecord']));
    ssService()->generateForVisit($b['visit']->fresh(['medicalRecord']));
    ssSyncIssues($a);
    ssSyncIssues($b);

    $aIssueIds = SatusehatDataQualityIssue::where('branch_id', $a['branch']->id)->pluck('id')->all();
    $bIssueIds = SatusehatDataQualityIssue::where('branch_id', $b['branch']->id)->pluck('id')->all();
    expect($aIssueIds)->not->toBeEmpty()->and($bIssueIds)->not->toBeEmpty();

    $operator = User::factory()->create();
    $actor = User::factory()->create();

    // Authorized only for branch A; submit A + B ids → B ids dropped.
    $result = app(SatusehatCrossBranchIssueService::class)->bulkAssign(
        array_merge($aIssueIds, $bIssueIds),
        $operator->id,
        [$a['branch']->id],
        $actor,
    );

    expect($result['assigned'])->toBe(count($aIssueIds))
        ->and($result['skipped'])->toBe(count($bIssueIds));

    // The B issues were never touched.
    expect(SatusehatDataQualityIssue::whereIn('id', $bIssueIds)->whereNotNull('assigned_to')->count())->toBe(0);

    Http::assertNothingSent();
});

it('rejects an over-cap bulk selection to force paginated selection', function () {
    $actor = User::factory()->create();
    $operator = User::factory()->create();
    $ids = range(1, SatusehatCrossBranchIssueService::MAX_BULK + 1);

    expect(fn () => app(SatusehatCrossBranchIssueService::class)
        ->bulkAssign($ids, $operator->id, [1], $actor))
        ->toThrow(ValidationException::class);
});

it('executive overview is aggregate, external-blocked, and PII-free', function () {
    $a = ssMakeVisit(['visit_date' => now()->toDateString()]);
    app(SatusehatBranchReadinessProfileService::class)->recalculate($a['branch']->id);

    $overview = app(SatusehatExecutiveReadinessService::class)->overview([$a['branch']->id]);

    expect($overview['summary']['branches_total'])->toBe(1)
        ->and($overview['summary']['branches_blocked_external_credential'])->toBe(1)
        ->and($overview['external_submission_enabled'])->toBeFalse()
        ->and($overview['production_blocked'])->toBeTrue()
        ->and($overview['satusehat_2_status'])->toBe('WATCH')
        ->and($overview['uat_completion']['completion_rate'])->toBeNull(); // no runs → null, not 0

    // Aggregate payload carries no obvious PII keys.
    $json = json_encode($overview);
    expect($json)->not->toContain('ktp')->and($json)->not->toContain('nik');

    Http::assertNothingSent();
});

it('computes bounded daily/weekly/monthly governance windows', function () {
    $a = ssMakeVisit();
    ssService()->generateForVisit($a['visit']->fresh(['medicalRecord']));
    ssSyncIssues($a);

    $windows = app(SatusehatExecutiveReadinessService::class)->governanceWindows([$a['branch']->id]);

    expect($windows)->toHaveKeys(['daily', 'weekly', 'monthly'])
        ->and($windows['monthly'])->toHaveKeys(['new_hard_issues', 'source_drift_issues', 'overdue_open_issues', 'demotions']);

    // Empty authorized set → all-zero windows (fail-closed).
    $empty = app(SatusehatExecutiveReadinessService::class)->governanceWindows([]);
    expect($empty['daily']['new_hard_issues'])->toBe(0);

    Http::assertNothingSent();
});
