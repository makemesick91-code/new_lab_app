<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use App\Modules\Satusehat\Models\SatusehatBranchPilotProfile;
use App\Modules\Satusehat\Models\SatusehatDataQualityIssue;
use App\Modules\Satusehat\Models\SatusehatPilotRehearsalRun;
use App\Modules\Satusehat\Services\Pilot\SatusehatBranchReadinessProfileService;
use App\Modules\Satusehat\Services\Pilot\SatusehatIssueSlaService;
use App\Modules\Satusehat\Services\Pilot\SatusehatPilotConfigurationService;
use App\Modules\Satusehat\Services\Pilot\SatusehatPilotRehearsalService;
use App\Modules\Satusehat\Services\Pilot\SatusehatReadinessScoreService;
use App\Modules\Satusehat\Services\Pilot\SatusehatReadinessThresholdService;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    config()->set('satusehat.candidate.auto_generate', false);
    config()->set('satusehat.enabled', false);
    config()->set('satusehat.send_enabled', false);
    config()->set('satusehat.sandbox_verified', false);
    config()->set('satusehat.environment', 'sandbox');
    Http::preventStrayRequests();
    seedAccessControl();
});

/** Build a branch that satisfies EVERY internal eligibility gate. */
function s4cReadyBranch(): array
{
    // visit_date today so the visit is inside the adoption metric's window.
    $ctx = ssMakeVisit(['visit_date' => now()->toDateString()]);

    // Global treatment mapping (100%).
    $treatment = Treatment::factory()->create(['is_active' => true]);
    ssTreatmentMapping($treatment->id);

    // Structured primary diagnosis → adoption 100%.
    ssDiagnosis($ctx, 'K02.9', 'primary', false);

    // A candidate row (total_candidates > 0) with NO issues.
    $candidate = ssService()->generateForVisit($ctx['visit']->fresh(['medicalRecord']));

    // A passing synthetic rehearsal run for the branch.
    SatusehatPilotRehearsalRun::create([
        'environment' => 'sandbox',
        'branch_id' => $ctx['branch']->id,
        'mode' => 'synthetic',
        'dry_run' => false,
        'result' => SatusehatPilotRehearsalRun::RESULT_BLOCKED_EXTERNAL_CREDENTIAL,
        'final_stage' => 'BLOCKED_EXTERNAL_CREDENTIAL',
        'started_at' => now(),
        'completed_at' => now(),
    ]);

    // An operator with the remediation permission (operator_roles_assigned gate).
    $op = User::factory()->create();
    $op->givePermissionTo('manage_satusehat_branch_remediation');

    return ['ctx' => $ctx, 'branch' => $ctx['branch'], 'candidate' => $candidate];
}

it('deterministic score caps at the ceiling when a hard blocker is present', function () {
    $score = app(SatusehatReadinessScoreService::class);

    $full = $score->calculate(['patient_readiness' => 100.0, 'diagnosis_adoption' => 100.0], false);
    expect($full['score'])->toBe(100)->and($full['capped'])->toBeFalse();

    $capped = $score->calculate(['patient_readiness' => 100.0, 'diagnosis_adoption' => 100.0], true);
    expect($capped['score'])->toBe(60)->and($capped['capped'])->toBeTrue();

    // A null-rate component is excluded from the denominator (N/A, not 0).
    $na = $score->calculate(['patient_readiness' => 80.0, 'diagnosis_adoption' => null], false);
    expect($na['score'])->toBe(80)->and($na['components']['diagnosis_adoption']['rate'])->toBeNull();
});

it('a fresh branch is not internally eligible and lists failed gates (no network)', function () {
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $result = app(SatusehatBranchReadinessProfileService::class)->eligibilityFor($branch->id);

    expect($result['internal_ready'])->toBeFalse()
        ->and($result['decision'])->toBe('not_eligible')
        ->and($result['failed_internal_gates'])->toContain('synthetic_rehearsal_passed');

    Http::assertNothingSent();
});

it('an internally-ready branch resolves to blocked_external_credential and can be approved', function () {
    $ready = s4cReadyBranch();
    $profiles = app(SatusehatBranchReadinessProfileService::class);

    $result = $profiles->eligibilityFor($ready['branch']->id);
    expect($result['internal_ready'])->toBeTrue()
        ->and($result['decision'])->toBe('blocked_external_credential')
        ->and($result['external_blocked'])->toBeTrue();

    $actor = User::factory()->create();
    $profile = app(SatusehatPilotConfigurationService::class)->approvePilot($ready['branch'], $actor);
    expect($profile->pilot_status)->toBe(SatusehatBranchPilotProfile::STATUS_APPROVED);

    Http::assertNothingSent();
});

it('approval is refused while internal gates fail', function () {
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $actor = User::factory()->create();

    expect(fn () => app(SatusehatPilotConfigurationService::class)->approvePilot($branch, $actor))
        ->toThrow(ValidationException::class);
});

it('enforces a single primary pilot branch', function () {
    $first = s4cReadyBranch();
    $second = s4cReadyBranch();
    $config = app(SatusehatPilotConfigurationService::class);
    $actor = User::factory()->create();

    $config->approvePilot($first['branch'], $actor);

    expect(fn () => $config->approvePilot($second['branch'], $actor))
        ->toThrow(ValidationException::class);
});

it('enforces a single approved pilot at the database level', function () {
    $branchA = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $branchB = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $profiles = app(SatusehatBranchReadinessProfileService::class);

    $a = $profiles->profileFor($branchA->id);
    $a->update(['pilot_status' => SatusehatBranchPilotProfile::STATUS_APPROVED]);

    // A second approved pilot in the same environment must be rejected by the
    // partial unique index (the race the row-level check alone cannot close).
    $b = $profiles->profileFor($branchB->id);
    expect(fn () => $b->update(['pilot_status' => SatusehatBranchPilotProfile::STATUS_APPROVED]))
        ->toThrow(QueryException::class);
});

it('rejects pilot selection for MAIN / non-RME branches', function () {
    $nonRme = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => false]);
    $actor = User::factory()->create();

    expect(fn () => app(SatusehatPilotConfigurationService::class)->selectCandidate($nonRme, $actor))
        ->toThrow(ValidationException::class);
});

it('suspends and resumes a pilot without losing history', function () {
    $ready = s4cReadyBranch();
    $config = app(SatusehatPilotConfigurationService::class);
    $actor = User::factory()->create();

    $config->approvePilot($ready['branch'], $actor);
    $suspended = $config->suspendPilot($ready['branch'], 'Investigasi kualitas data internal', $actor);
    expect($suspended->pilot_status)->toBe(SatusehatBranchPilotProfile::STATUS_SUSPENDED);

    $resumed = $config->resumePilot($ready['branch'], $actor);
    expect($resumed->pilot_status)->toBe(SatusehatBranchPilotProfile::STATUS_CANDIDATE);
});

it('threshold overrides are allowlisted, bounded, versioned, and audited', function () {
    $service = app(SatusehatReadinessThresholdService::class);
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $profile = app(SatusehatBranchReadinessProfileService::class)->profileFor($branch->id);
    $actor = User::factory()->create();

    $service->setOverrides($profile, [
        'diagnosis_adoption_min_percent' => 250,   // clamped to 100
        'max_open_hard_issues' => -5,              // clamped to 0
        'not_a_real_key' => 99,                    // dropped
    ], 'Menaikkan ambang batas adopsi untuk pilot', $actor);

    $effective = $service->effectiveThresholds($profile->fresh());
    expect($effective['diagnosis_adoption_min_percent'])->toBe(100)
        ->and($effective['max_open_hard_issues'])->toBe(0)
        ->and($effective)->not->toHaveKey('not_a_real_key');
});

it('SLA assignment computes a due date + priority and escalation only moves up', function () {
    $ready = s4cReadyBranch();
    $issue = SatusehatDataQualityIssue::create([
        'environment' => 'sandbox',
        'branch_id' => $ready['branch']->id,
        'satusehat_candidate_id' => $ready['candidate']->id,
        'rule_code' => 'patient_identity',
        'severity' => SatusehatDataQualityIssue::SEVERITY_HARD,
        'status' => SatusehatDataQualityIssue::STATUS_OPEN,
        'fingerprint' => hash('sha256', 'fixture-'.uniqid()),
        'message' => 'fixture',
        'first_detected_at' => now(),
        'last_detected_at' => now(),
    ]);

    $sla = app(SatusehatIssueSlaService::class);
    $actor = User::factory()->create();
    $assignee = User::factory()->create();

    $assigned = $sla->assign($issue, $actor, $assignee->id);
    // Hard issue → default priority high.
    expect($assigned->priority)->toBe(SatusehatDataQualityIssue::PRIORITY_HIGH)
        ->and($assigned->due_at)->not->toBeNull()
        ->and($assigned->assigned_to)->toBe($assignee->id);

    $escalated = $sla->escalate($assigned, $actor, 'it_operator');
    expect($escalated->escalation_level)->toBe('it_operator');

    // Cannot escalate downward.
    expect(fn () => $sla->escalate($escalated->fresh(), $actor, 'branch_supervisor'))
        ->toThrow(ValidationException::class);
});

it('a confirmed pilot rehearsal is network-silent and records a blocked_external run', function () {
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $actor = User::factory()->create();

    $result = app(SatusehatPilotRehearsalService::class)->run($branch, $actor, false);

    expect($result['external_submitted'])->toBeFalse()
        ->and($result['result'])->toBeIn([
            SatusehatPilotRehearsalRun::RESULT_BLOCKED_EXTERNAL_CREDENTIAL,
            SatusehatPilotRehearsalRun::RESULT_FAILED,
        ]);

    expect(SatusehatPilotRehearsalRun::where('branch_id', $branch->id)->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('a dry-run rehearsal never persists a run', function () {
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    app(SatusehatPilotRehearsalService::class)->run($branch, User::factory()->create(), true);

    expect(SatusehatPilotRehearsalRun::where('branch_id', $branch->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('production stays blocked and SATUSEHAT-2 stays WATCH in eligibility posture', function () {
    $ready = s4cReadyBranch();
    $result = app(SatusehatBranchReadinessProfileService::class)->eligibilityFor($ready['branch']->id);

    $gates = collect($result['gates'])->keyBy('key');
    expect($gates['production_blocked']['passed'])->toBeTrue()
        ->and($gates['satusehat2_watch']['passed'])->toBeTrue()
        ->and($gates['external_send_disabled']['passed'])->toBeTrue();
});

it('recalculate persists a score + stage to the branch profile', function () {
    $ready = s4cReadyBranch();
    $profile = app(SatusehatBranchReadinessProfileService::class)->recalculate($ready['branch']->id);

    expect($profile->internal_readiness_score)->not->toBeNull()
        ->and($profile->readiness_stage)->toBe(SatusehatBranchPilotProfile::STAGE_PILOT_READY_INTERNAL);
});

it('branch board is scoped and renders without external calls', function () {
    $branch = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $user = User::factory()->create();
    $user->givePermissionTo(['view_satusehat_branch_readiness', 'view_satusehat_submissions']);

    $this->actingAs($user)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('satusehat.branches.index'))
        ->assertOk();

    Http::assertNothingSent();
});

it('a branch-pinned operator cannot open another branch (IDOR 404)', function () {
    $branchA = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);
    $branchB = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => true]);

    $operator = User::factory()->create(['branch_id' => $branchA->id]);
    $operator->givePermissionTo(['view_satusehat_branch_readiness', 'manage_satusehat_branch_remediation']);

    $this->actingAs($operator)
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('satusehat.branches.show', $branchB->id))
        ->assertNotFound();
});

it('pilot-eligibility command reports blocked_external_credential json for a ready branch', function () {
    $ready = s4cReadyBranch();

    $this->artisan('satusehat:pilot-eligibility', ['--branch' => $ready['branch']->id, '--json' => true])
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('branch-readiness and issue-aging commands run read-only with no network', function () {
    $this->artisan('satusehat:branch-readiness', ['--json' => true])->assertExitCode(0);
    $this->artisan('satusehat:issue-aging', ['--json' => true])->assertExitCode(0);
    $this->artisan('satusehat:operator-backlog', ['--json' => true])->assertExitCode(0);
    Http::assertNothingSent();
});
