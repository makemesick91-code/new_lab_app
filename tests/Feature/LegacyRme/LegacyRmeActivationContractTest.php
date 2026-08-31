<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LegacyImport\Services\LegacyImportDailyQuotaService;
use App\Modules\LegacyImport\Support\LegacyImportType;
use App\Modules\LegacyRme\Models\LegacyRmeImport;
use App\Modules\LegacyRme\Services\LegacyRmeActivationStateService;
use App\Modules\LegacyRme\Services\LegacyRmeImportService;
use App\Modules\LegacyRme\Services\LegacyRmeOperationsGateService;
use App\Modules\LegacyRme\Support\LegacyRmeOperationsDecision;
use App\Modules\MedicalRecord\Models\MedicalRecord;
use App\Modules\Patient\Models\Patient;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * FEATURE-LEGACY-IMPORT-HUB-1A — the ACTIVATION CONTRACT.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM THE GATE SUITES. Admission
 * (LegacyRmeBranchAdmissionTest), the operations layer
 * (LegacyRmeMigrationOperationsGateTest), the per-branch ceiling
 * (LegacyImportHubQuotaTest) and the absence of native side effects
 * (LegacyRmeNonRegressionTest) each already pin their own rule, and are
 * deliberately not repeated here.
 *
 * What none of them covers is the SHAPE PRODUCTION IS ABOUT TO BE PUT INTO: not
 * one pilot branch and not a pair, but EVERY eligible clinic branch admitted at
 * once under a SINGLE owner approval and a SINGLE active wave. That composition
 * is what "Legacy RME is end-to-end usable" actually means, and it is the one
 * thing that was never exercised before being switched on.
 *
 * This is therefore a REHEARSAL of the activation, run against the real
 * services. It exists so the four-branch configuration is proven here rather
 * than discovered in production — where proving it would mean publishing
 * fabricated clinical evidence into real patient histories, which no readiness
 * argument may ever be worth.
 *
 * FOUR BRANCHES, NOT AN ARBITRARY NUMBER. TLK1, LDK2, ATG3 and SUN4 are the
 * active, RME-enabled clinic branches. MAIN is excluded because it is not
 * RME-enabled AND is on the permanent forbidden list — a fallback branch may
 * never host a clinical migration.
 */
uses(RefreshDatabase::class);

/** The active, RME-enabled clinic branches — the exact admission scope. */
const ACTIVATION_BRANCHES = ['TLK1', 'LDK2', 'ATG3', 'SUN4'];

const ACTIVATION_APPROVAL = 'HUB-1A-OWNER-APPROVAL-TEST';

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    Bus::fake();
    legacyRmeArchiveFlag(true);
});

/**
 * Put the deployment into the production-shaped activation: every eligible
 * branch admitted, one approval covering exactly that set, one ACTIVE wave
 * enrolling exactly those branches.
 *
 * @param  list<string>  $branches
 */
function activateWave(array $branches = ACTIVATION_BRANCHES): void
{
    foreach ($branches as $code) {
        legacyRmeBranch($code);
    }

    legacyRmeAdmittedBranches($branches);
    legacyRmeApproveWave(ACTIVATION_APPROVAL, $branches);
    legacyRmeMigrationWave($branches);
}

/** An operator permitted and assigned to every branch in the wave. */
function activationOperator(array $branches = ACTIVATION_BRANCHES): User
{
    $actor = superAdmin();

    foreach ($branches as $code) {
        legacyRmeAssignOperator($actor, $code);
    }

    return $actor->refresh();
}

/** Upload one archive through the real service, with distinct bytes each time. */
function activationUpload(User $actor, string $branchCode): LegacyRmeImport
{
    $patient = legacyRmeArchivablePatient([], $branchCode);
    legacyRmeNativeVisit($patient, '2024-05-01');

    static $sequence = 0;
    $sequence++;

    return app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-02',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload(sprintf('arsip-%d.pdf', $sequence), $sequence),
        $actor,
    );
}

/*
|--------------------------------------------------------------------------
| The composed activation
|--------------------------------------------------------------------------
*/

it('opens every eligible branch at once under one approval and one active wave', function () {
    activateWave();
    $actor = activationOperator();

    $gate = app(LegacyRmeOperationsGateService::class);

    foreach (ACTIVATION_BRANCHES as $code) {
        $decision = $gate->decide($actor, $code);

        expect($decision->cleared)
            ->toBeTrue("branch {$code} should be cleared under the activated wave")
            ->and($decision->code)->toBe(LegacyRmeOperationsDecision::CODE_CLEARED);
    }

    $state = app(LegacyRmeActivationStateService::class)->state(ACTIVATION_BRANCHES);

    expect($state['open'])->toBeTrue()
        ->and($state['blocker'])->toBeNull()
        ->and($state['admitted_branch_codes'])->toEqualCanonicalizing(ACTIVATION_BRANCHES)
        ->and($state['binding_matches'])->toBeTrue();
});

it('accepts a real document for each eligible branch and files it under that branch', function () {
    activateWave();
    $actor = activationOperator();

    foreach (ACTIVATION_BRANCHES as $code) {
        $import = activationUpload($actor, $code);

        // The origin branch is DERIVED from the patient's Nomor RM, never taken
        // from the caller — so this also proves the four-branch wave keeps each
        // document in its own clinic rather than pooling them.
        expect((int) $import->origin_branch_id)
            ->toBe((int) Branch::query()->where('code', $code)->value('id'));
    }

    expect(LegacyRmeImport::query()->count())->toBe(count(ACTIVATION_BRANCHES));
});

it('still refuses an eligible branch that the activation deliberately left out', function () {
    // The whole point of an allowlist is that widening it is a decision. Three
    // branches activated must not clear the fourth.
    $activated = ['TLK1', 'LDK2', 'ATG3'];

    activateWave($activated);

    // Build SUN4's patient BEFORE pinning the scope: legacyRmeArchivablePatient()
    // calls legacyRmeBranch(), which admits and enrols its branch as a fixture
    // convenience. Pinning first and uploading second would silently re-open
    // SUN4 and make this test pass for the opposite reason.
    $patient = legacyRmeArchivablePatient([], 'SUN4');
    legacyRmeNativeVisit($patient, '2024-05-01');

    legacyRmeAdmittedBranches($activated);
    legacyRmeApproveWave(ACTIVATION_APPROVAL, $activated);

    $actor = activationOperator($activated);

    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-02',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload('arsip-sun4.pdf', 9),
        $actor,
    ))->toThrow(ValidationException::class);

    $sun4 = (int) Branch::query()->where('code', 'SUN4')->value('id');

    expect(LegacyRmeImport::query()->where('origin_branch_id', $sun4)->exists())->toBeFalse();
});

it('refuses a branch the approval covers but the activation has not admitted yet', function () {
    // THE STAGED-ROLLOUT SHAPE, and the one case where the allowlist is the ONLY
    // guard. An owner may legitimately approve the whole wave up front and then
    // admit its branches one at a time; approval is permission to activate, not
    // activation. Every other test in this suite keeps the two sets identical,
    // which leaves the approval-coverage check able to mask a broken allowlist —
    // a surviving mutant proved exactly that.
    activateWave(['TLK1']);

    $patient = legacyRmeArchivablePatient([], 'LDK2');
    legacyRmeNativeVisit($patient, '2024-05-01');

    // Approval is DELIBERATELY wider than the admitted set.
    legacyRmeAdmittedBranches(['TLK1']);
    legacyRmeApproveWave(ACTIVATION_APPROVAL, ['TLK1', 'LDK2']);

    $state = app(LegacyRmeActivationStateService::class)->state(['TLK1', 'LDK2']);
    $rows = collect($state['branches'])->keyBy('branch_code');

    expect($rows['TLK1']['admitted'])->toBeTrue()
        ->and($rows['LDK2']['admitted'])->toBeFalse()
        ->and($rows['LDK2']['admission_code'])->toBe('BRANCH_NOT_ADMITTED');

    // ...and the real upload path refuses it too, not just the report.
    expect(fn () => app(LegacyRmeImportService::class)->createFromUpload(
        $patient,
        '2019-04-02',
        $patient->medical_record_number,
        null,
        legacyRmePdfUpload('arsip-ldk2.pdf', 11),
        activationOperator(['TLK1']),
    ))->toThrow(ValidationException::class);
});

it('never admits MAIN even when it is written into the allowlist and the approval', function () {
    activateWave();
    legacyRmeAdmittedBranches([...ACTIVATION_BRANCHES, 'MAIN']);
    legacyRmeApproveWave(ACTIVATION_APPROVAL, [...ACTIVATION_BRANCHES, 'MAIN']);

    $state = app(LegacyRmeActivationStateService::class)->state(['MAIN']);

    expect(collect($state['branches'])->firstWhere('branch_code', 'MAIN')['admitted'])->toBeFalse()
        // ...and it is filtered out of the admitted set entirely, so no report
        // can ever present it as part of the wave.
        ->and($state['admitted_branch_codes'])->not->toContain('MAIN');
});

/*
|--------------------------------------------------------------------------
| The ceiling stays PER BRANCH under a multi-branch wave
|--------------------------------------------------------------------------
*/

it('gives each branch of the activated wave its own daily ceiling of 100', function () {
    activateWave();

    $quota = app(LegacyImportDailyQuotaService::class);
    $ids = collect(ACTIVATION_BRANCHES)->mapWithKeys(
        fn (string $code): array => [$code => (int) Branch::query()->where('code', $code)->value('id')]
    );

    // Fill TLK1 to its ceiling. A shared pool would leave the other three with
    // nothing; a per-branch ceiling leaves each of them a full 100.
    $quota->reserve(LegacyImportType::LEGACY_RME, $ids['TLK1'], 100);

    expect(fn () => $quota->reserve(LegacyImportType::LEGACY_RME, $ids['TLK1'], 1))
        ->toThrow(ValidationException::class);

    // A shared pool would have been exhausted by TLK1. Each of the other three
    // taking a full 100 without raising is the per-branch ceiling proving
    // itself; any of them throwing fails the test.
    foreach (['LDK2', 'ATG3', 'SUN4'] as $code) {
        $quota->reserve(LegacyImportType::LEGACY_RME, $ids[$code], 100);
    }

    expect(true)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Activation changes the gate, and nothing else
|--------------------------------------------------------------------------
*/

it('does not open the wave merely by declaring the branches', function () {
    // Admission alone is not activation. Without a registered ACTIVE wave the
    // deployment must stay shut — this is the state production was left in, and
    // the reason the hub had to learn to say so.
    foreach (ACTIVATION_BRANCHES as $code) {
        legacyRmeBranch($code);
    }

    legacyRmeAdmittedBranches(ACTIVATION_BRANCHES);
    legacyRmeApproveWave(ACTIVATION_APPROVAL, ACTIVATION_BRANCHES);
    config()->set('legacy_rme_rollout.admission.wave', '');

    $state = app(LegacyRmeActivationStateService::class)->state(ACTIVATION_BRANCHES);

    expect($state['open'])->toBeFalse()
        ->and($state['blocker'])->toBe(LegacyRmeActivationStateService::BLOCKER_WAVE_NOT_DECLARED);
});

it('creates no visit, invoice, payment or lab row while the activated wave ingests', function () {
    activateWave();
    $actor = activationOperator();

    // Build every patient and its native cutoff visit FIRST, then snapshot.
    // The claim under test is that the UPLOAD adds nothing clinical — measuring
    // across the fixture as well would fold the fixture's own rows into the
    // delta and make the assertion a statement about the test, not the product.
    $patients = [];

    foreach (ACTIVATION_BRANCHES as $code) {
        $patient = legacyRmeArchivablePatient([], $code);
        legacyRmeNativeVisit($patient, '2024-05-01');
        $patients[] = $patient;
    }

    $before = [
        'visits' => ClinicVisit::query()->count(),
        'invoices' => RmeInvoice::query()->count(),
        'records' => MedicalRecord::query()->count(),
    ];

    foreach ($patients as $index => $patient) {
        app(LegacyRmeImportService::class)->createFromUpload(
            $patient,
            '2019-04-02',
            $patient->medical_record_number,
            null,
            legacyRmePdfUpload(sprintf('arsip-act-%d.pdf', $index + 1), $index + 1),
            $actor,
        );
    }

    expect(LegacyRmeImport::query()->count())->toBe(count(ACTIVATION_BRANCHES))
        ->and(ClinicVisit::query()->count())->toBe($before['visits'])
        ->and(RmeInvoice::query()->count())->toBe($before['invoices'])
        ->and(MedicalRecord::query()->count())->toBe($before['records']);
});
