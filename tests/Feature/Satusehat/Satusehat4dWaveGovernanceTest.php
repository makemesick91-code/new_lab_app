<?php

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Satusehat\Models\SatusehatBranchScoreSnapshot;
use App\Modules\Satusehat\Models\SatusehatBranchTransition;
use App\Modules\Satusehat\Models\SatusehatChangeRequest;
use App\Modules\Satusehat\Models\SatusehatRolloutWave;
use App\Modules\Satusehat\Services\Pilot\SatusehatBranchPromotionService;
use App\Modules\Satusehat\Services\Pilot\SatusehatChangeControlService;
use App\Modules\Satusehat\Services\Pilot\SatusehatRolloutWaveService;
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

it('creates a wave in draft (never active by default) and enrolls only RME branches', function () {
    $svc = app(SatusehatRolloutWaveService::class);
    $actor = User::factory()->create();
    $a = ssMakeVisit(['visit_date' => now()->toDateString()]);

    $wave = $svc->createWave(['name' => 'Wave 1', 'sequence' => 1], $actor);
    expect($wave->status)->toBe(SatusehatRolloutWave::STATUS_DRAFT)
        ->and($wave->isActive())->toBeFalse();

    $svc->enrollBranch($wave, $a['branch'], $actor);
    expect($wave->activeMemberships()->count())->toBe(1);

    // Non-RME branch rejected.
    $nonRme = Branch::factory()->create(['is_active' => true, 'is_rme_enabled' => false]);
    expect(fn () => $svc->enrollBranch($wave, $nonRme, $actor))->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

it('forbids a branch belonging to two active waves', function () {
    $svc = app(SatusehatRolloutWaveService::class);
    $actor = User::factory()->create();
    $a = ssMakeVisit(['visit_date' => now()->toDateString()]);

    $w1 = $svc->createWave(['name' => 'W1'], $actor);
    $w2 = $svc->createWave(['name' => 'W2'], $actor);
    $svc->enrollBranch($w1, $a['branch'], $actor);

    expect(fn () => $svc->enrollBranch($w2, $a['branch'], $actor))->toThrow(ValidationException::class);

    // Idempotent re-enroll into the same wave returns the existing membership.
    $again = $svc->enrollBranch($w1, $a['branch'], $actor);
    expect($again->rollout_wave_id)->toBe($w1->id);
});

it('approving a wave needs an enrolled branch and enforces single active wave', function () {
    $svc = app(SatusehatRolloutWaveService::class);
    $actor = User::factory()->create();
    $a = ssMakeVisit(['visit_date' => now()->toDateString()]);

    $empty = $svc->createWave(['name' => 'Empty'], $actor);
    expect(fn () => $svc->approveWave($empty, $actor))->toThrow(ValidationException::class);

    $w1 = $svc->createWave(['name' => 'Active'], $actor);
    $svc->enrollBranch($w1, $a['branch'], $actor);
    $approved = $svc->approveWave($w1, $actor);
    expect($approved->status)->toBe(SatusehatRolloutWave::STATUS_APPROVED);

    // A second wave with a branch cannot be approved while w1 is active.
    $b = ssMakeVisit(['visit_date' => now()->toDateString()]);
    $w2 = $svc->createWave(['name' => 'Second'], $actor);
    $svc->enrollBranch($w2, $b['branch'], $actor);
    expect(fn () => $svc->approveWave($w2, $actor))->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

it('suspends, resumes, and closes a wave (close removes memberships)', function () {
    $svc = app(SatusehatRolloutWaveService::class);
    $actor = User::factory()->create();
    $a = ssMakeVisit(['visit_date' => now()->toDateString()]);

    $wave = $svc->createWave(['name' => 'Lifecycle'], $actor);
    $svc->enrollBranch($wave, $a['branch'], $actor);
    $svc->approveWave($wave, $actor);

    $svc->suspendWave($wave, 'Menunggu remediasi data cabang', $actor);
    expect($wave->refresh()->status)->toBe(SatusehatRolloutWave::STATUS_SUSPENDED);

    $svc->resumeWave($wave, $actor);
    expect($wave->refresh()->status)->toBe(SatusehatRolloutWave::STATUS_PROFILING);

    $svc->closeWave($wave, $actor);
    expect($wave->refresh()->status)->toBe(SatusehatRolloutWave::STATUS_CLOSED)
        ->and($wave->activeMemberships()->count())->toBe(0);
});

it('promotion is refused with a hard blocker and records a demotion transition + snapshot', function () {
    $promo = app(SatusehatBranchPromotionService::class);
    $actor = User::factory()->create();
    $a = ssMakeVisit(['visit_date' => now()->toDateString()]);

    // A brand-new branch with no readiness → not internal_ready → promotion refused.
    expect(fn () => $promo->promote($a['branch'], 'Promosi awal cabang', $actor))
        ->toThrow(ValidationException::class);

    // Demotion with a known trigger records an immutable transition + score snapshot.
    $promo->demote($a['branch'], 'source_drift_backlog', 'Backlog drift belum tuntas', $actor);

    expect(SatusehatBranchTransition::where('branch_id', $a['branch']->id)
        ->where('transition_type', 'demotion')->count())->toBe(1)
        ->and(SatusehatBranchScoreSnapshot::where('branch_id', $a['branch']->id)->count())->toBeGreaterThanOrEqual(1);

    // Unknown demotion trigger rejected.
    expect(fn () => $promo->demote($a['branch'], 'not_a_trigger', 'x'.str_repeat('y', 12), $actor))
        ->toThrow(ValidationException::class);

    Http::assertNothingSent();
});

it('change control blocks production/credential categories and enforces separation of duties', function () {
    $cc = app(SatusehatChangeControlService::class);
    $requester = User::factory()->create();
    $approver = User::factory()->create();

    // A blocked category can be logged but never approved.
    $blocked = $cc->create([
        'category' => 'production_guard_config',
        'reason' => 'Permintaan aktivasi produksi (harus ditolak sistem)',
        'scope' => 'production guard',
    ], $requester);
    expect(fn () => $cc->approve($blocked, $approver))->toThrow(ValidationException::class);

    // A normal category: requester cannot self-approve.
    $cr = $cc->create([
        'category' => 'readiness_threshold',
        'reason' => 'Menyesuaikan ambang adopsi diagnosis cabang pilot',
        'scope' => 'branch:threshold',
    ], $requester);
    expect(fn () => $cc->approve($cr, $requester))->toThrow(ValidationException::class);

    $approved = $cc->approve($cr, $approver);
    expect($approved->status)->toBe(SatusehatChangeRequest::STATUS_APPROVED);

    $applied = $cc->markApplied($approved, $approver);
    expect($applied->status)->toBe(SatusehatChangeRequest::STATUS_APPLIED);
});
