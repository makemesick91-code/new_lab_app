<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| FEATURE-LEGACY-IMPORT-HUB-1A — the hub reports the REAL operational state.
|--------------------------------------------------------------------------
|
| THE DEFECT UNDER TEST. FEATURE-LEGACY-IMPORT-HUB-1 shipped with a hard-coded
| `has_additional_gates` disclaimer for legacy RME. It read identically whether
| admission and the migration wave were wide open or completely shut, so the
| hub reported "Aktif" for a surface that refused every single upload — and
| production then ran an entire release in exactly that state: capability ON,
| no branch admitted, no wave. A permanently-true caveat is not a state.
|
| WHAT THESE TESTS PIN. That the card distinguishes the four situations the
| sprint exists to separate — capability off, capability on with the gates shut,
| gates shut for a NAMED reason, and genuinely open — and that the reported
| verdict cannot drift from the gate that actually decides an upload.
|
| WHAT THEY DO NOT PIN. Whether an upload is accepted. That is the admission and
| operations gates' own suites; this page has no authority and these tests must
| never be read as proving one.
*/

use App\Models\User;
use App\Modules\LegacyImport\Services\LegacyImportHubService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Models\LegacyRmeWaveBranch;
use App\Modules\LegacyRme\Services\LegacyRmeActivationStateService;
use App\Modules\LegacyRme\Services\LegacyRmeBranchAdmissionService;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use App\Modules\RmeOnlineContext\Middleware\EnsureRmeOnlineContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../LegacyOdontogram/helpers.php';

beforeEach(function () {
    seedAccessControl();
    legacyRmeArchiveFlag(true);
    lodoFlag(true);
});

/** The legacy RME card as an operator with the whole RME branch set would see it. */
function lihoRmeCard(?User $actor = null): array
{
    $actor ??= lihOperator(['view_legacy_rme_imports', 'review_legacy_rme_imports']);

    return collect(app(LegacyImportHubService::class)->overview($actor->refresh())['types'])
        ->firstWhere('type', LegacyImportType::LEGACY_RME);
}

/*
|--------------------------------------------------------------------------
| The four states this sprint exists to separate
|--------------------------------------------------------------------------
*/

it('reports the capability as nonaktif when the archive flag is off', function () {
    legacyRmeBranch();
    legacyRmeArchiveFlag(false);

    $card = lihoRmeCard();

    expect($card['status'])->toBe('nonaktif')
        ->and($card['additional_gates']['open'])->toBeFalse()
        ->and($card['additional_gates']['blocker'])
        ->toBe(LegacyRmeActivationStateService::BLOCKER_CAPABILITY_OFF);
});

it('does NOT report aktif when the capability is on but no branch is admitted', function () {
    // THE EXACT PRODUCTION STATE THIS SPRINT FOUND: flag on, route registered,
    // operator permitted — and every upload refused, because nothing is
    // admitted. Before 1A this card said "Aktif".
    lihBranch();
    legacyRmeAdmittedBranches([]);

    $card = lihoRmeCard();

    expect($card['status'])->toBe('belum_dibuka')
        ->and($card['capability_enabled'])->toBeTrue()
        ->and($card['additional_gates']['open'])->toBeFalse()
        ->and($card['additional_gates']['blocker'])
        ->toBe(LegacyRmeActivationStateService::BLOCKER_NO_BRANCH_ADMITTED);
});

it('reports aktif only once admission and a running wave both admit', function () {
    // legacyRmeBranch() admits the branch AND enrols it in a running wave —
    // the same two steps a real activation performs.
    legacyRmeBranch();

    $card = lihoRmeCard();

    expect($card['status'])->toBe('aktif')
        ->and($card['additional_gates']['open'])->toBeTrue()
        ->and($card['additional_gates']['blocker'])->toBeNull()
        ->and($card['additional_gates']['wave_ingesting'])->toBeTrue()
        ->and($card['additional_gates']['binding_matches'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Each blocker is named, so an operator is sent to the right control
|--------------------------------------------------------------------------
*/

it('names a missing approval rather than reporting a generic closure', function () {
    legacyRmeBranch();
    config()->set('legacy_rme_rollout.admission.approval_reference', '');

    expect(lihoRmeCard()['additional_gates']['blocker'])
        ->toBe(LegacyRmeActivationStateService::BLOCKER_APPROVAL_MISSING);
});

it('names an allowlist widened beyond the approval that covers it', function () {
    legacyRmeBranch('TKM1');
    // The allowlist grows; the approval does not. Failing closed here is the
    // ROLL-3 lesson, and the report has to say WHICH branch is uncovered.
    legacyRmeAdmittedBranches(['TKM1', 'LDK2']);

    $gates = lihoRmeCard()['additional_gates'];

    expect($gates['blocker'])->toBe(LegacyRmeActivationStateService::BLOCKER_APPROVAL_INCOMPLETE)
        ->and($gates['unapproved_admitted_branch_codes'])->toBe(['LDK2']);
});

it('names an undeclared wave when branches are admitted without one', function () {
    legacyRmeBranch();
    config()->set('legacy_rme_rollout.admission.wave', '');

    expect(lihoRmeCard()['additional_gates']['blocker'])
        ->toBe(LegacyRmeActivationStateService::BLOCKER_WAVE_NOT_DECLARED);
});

it('names an unregistered wave when config declares one with no operational record', function () {
    legacyRmeBranch();
    LegacyRmeWaveBranch::query()->delete();
    LegacyRmeMigrationWave::query()->delete();

    expect(lihoRmeCard()['additional_gates']['blocker'])
        ->toBe(LegacyRmeActivationStateService::BLOCKER_WAVE_NOT_REGISTERED);
});

it('names a wave that is registered but not ingesting', function () {
    legacyRmeBranch();
    LegacyRmeMigrationWave::query()->update(['status' => LegacyRmeWaveStatus::PAUSED]);

    $gates = lihoRmeCard()['additional_gates'];

    expect($gates['blocker'])->toBe(LegacyRmeActivationStateService::BLOCKER_WAVE_NOT_ACTIVE)
        ->and($gates['wave_status'])->toBe(LegacyRmeWaveStatus::PAUSED)
        ->and($gates['wave_ingesting'])->toBeFalse();
});

it('names a wave record that disagrees with the approval on this deployment', function () {
    legacyRmeBranch();
    // The deployment's approval moved on; the governance record did not.
    legacyRmeApproveWave('ROLL-4-NEW-APPROVAL', ['TKM1']);

    expect(lihoRmeCard()['additional_gates']['blocker'])
        ->toBe(LegacyRmeActivationStateService::BLOCKER_WAVE_BINDING_MISMATCH);
});

/*
|--------------------------------------------------------------------------
| The report may never disagree with the gate that actually decides
|--------------------------------------------------------------------------
*/

it('takes each per-branch verdict from the real admission gate', function () {
    legacyRmeBranch('TKM1');
    legacyRmeBranch('LDK2');
    // Only TKM1 stays admitted and approved. LDK2 exists, is RME-enabled and is
    // visible on the page — and must still be reported as NOT admitted.
    legacyRmeAdmittedBranches(['TKM1']);
    legacyRmeApproveWave('ROLL-4-TEST-APPROVAL', ['TKM1']);

    $rows = collect(lihoRmeCard()['additional_gates']['branches'])->keyBy('branch_code');
    $gate = app(LegacyRmeBranchAdmissionService::class);

    foreach (['TKM1', 'LDK2'] as $code) {
        // The assertion is deliberately a COMPARISON against the live gate, not
        // a hard-coded expectation: it fails the moment the report grows a
        // second, divergent implementation of the allowlist.
        expect($rows[$code]['admitted'])->toBe($gate->decideForBranchCode($code)->admitted);
    }

    expect($rows['TKM1']['admitted'])->toBeTrue()
        ->and($rows['LDK2']['admitted'])->toBeFalse();
});

it('never reports MAIN as admitted, whatever the allowlist says', function () {
    legacyRmeBranch('TKM1');
    // MAIN is not RME-enabled so it never reaches the page, but the state
    // service must refuse it even when asked directly — a forbidden branch can
    // never host a clinical migration.
    legacyRmeAdmittedBranches(['TKM1', 'MAIN']);
    legacyRmeApproveWave('ROLL-4-TEST-APPROVAL', ['TKM1', 'MAIN']);

    $rows = collect(app(LegacyRmeActivationStateService::class)->state(['TKM1', 'MAIN']))
        ->get('branches');

    expect(collect($rows)->firstWhere('branch_code', 'MAIN')['admitted'])->toBeFalse();
});

it('withholds gate state from an actor who may not view the capability', function () {
    legacyRmeBranch();

    // A legacy PATIENT operator can reach the hub, but holds nothing on legacy
    // RME. They are told the card exists and is out of their reach — not which
    // branches its wave admitted, nor what that wave is currently doing.
    $card = lihoRmeCard(lihOperator(['manage patients']));

    expect($card['status'])->toBe('tanpa_akses')
        ->and($card['may_view'])->toBeFalse()
        ->and($card['has_additional_gates'])->toBeTrue()
        ->and($card['additional_gates'])->toBeNull();
});

it('reports no gate state at all for a type that has none', function () {
    lihBranch();

    $overview = app(LegacyImportHubService::class)->overview(lihOperator(['manage patients'])->refresh());

    foreach ($overview['types'] as $card) {
        if ($card['type'] === LegacyImportType::LEGACY_RME) {
            continue;
        }

        expect($card['has_additional_gates'])->toBeFalse()
            ->and($card['additional_gates'])->toBeNull();
    }
});

/*
|--------------------------------------------------------------------------
| The page stays a report, and stays PII-free
|--------------------------------------------------------------------------
*/

it('renders the closed state to an operator without leaking anything but branch codes', function () {
    lihBranch();
    legacyRmeAdmittedBranches([]);

    $actor = lihOperator(['view_legacy_rme_imports', 'create_legacy_rme_imports']);

    $response = $this->actingAs($actor->refresh())
        ->withoutMiddleware(EnsureRmeOnlineContext::class)
        ->get(route('settings.legacy-imports.index'));

    $response->assertOk();
    $response->assertSee('Belum Dibuka');
    // The operator is told WHY, in words they can act on, rather than being
    // shown a green badge and a permanent footnote.
    $response->assertSee('belum ada cabang yang diizinkan memulai migrasi', false);
});

it('reports gate state without a patient identifier anywhere in it', function () {
    legacyRmeBranch();

    $encoded = (string) json_encode(lihoRmeCard()['additional_gates']);

    // Branch codes and a wave label are operational labels and are expected.
    // Anything resembling a Nomor RM, a KTP/NIK or a name is not.
    expect($encoded)->toContain('TKM1')
        ->and($encoded)->not->toMatch('/\d{6,}/');
});
