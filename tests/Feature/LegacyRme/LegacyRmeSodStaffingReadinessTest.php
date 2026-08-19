<?php

/**
 * FIX-LEGACY-RME-ROUTINE-OPS-1 — separation of duties has to be STAFFED, not
 * merely switched on.
 *
 * THE INCIDENT. Production ran with both SOD switches on, and
 * `legacy-rme:ops-readiness` reported `separation_of_duties = GO` — "Both
 * separation-of-duties requirements are enforced." At the same moment the
 * governance role's canonical `approve_legacy_rme_migration_wave` had never
 * been reconciled onto the server, so no second account could approve
 * anything. The report was literally true and operationally misleading: the
 * operator would have found out at the first approval, mid-batch.
 *
 * These tests pin the distinction the check now draws. A config boolean says
 * what the deployment INTENDS. Whether two distinct accounts can each perform
 * their half says what the deployment can actually DO. Only the second is a
 * precondition for opening a batch, and only the second is now reported as GO.
 *
 * The application still does not claim to verify that two different HUMANS are
 * behind those accounts — that disclaimer is preserved verbatim, because it
 * remains true.
 */

use App\Models\User;
use App\Modules\LegacyRme\Models\LegacyRmeMigrationWave;
use App\Modules\LegacyRme\Services\LegacyRmeSteadyStateOpsService;
use App\Modules\LegacyRme\Support\LegacyRmeSodStaffing;
use App\Modules\LegacyRme\Support\LegacyRmeWaveStatus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedAccessControl();
    Storage::fake('legacy_rme_private');
    Bus::fake();
    legacyRmeArchiveFlag(false);

    // Backup is infrastructure this suite does not own; neutralised so each
    // test asserts the one condition it is about.
    config()->set('legacy_rme_steady_state.backup.required_before_batch', false);

    // Both invariants ON — the production posture, and the only posture in
    // which "is it staffed?" is a meaningful question.
    config()->set('legacy_rme_operations.require_separate_publisher', true);
    config()->set('legacy_rme_operations.require_separate_approver', true);
});

/** @return array<string, mixed> */
function sodStaffingReport(): array
{
    return app(LegacyRmeSteadyStateOpsService::class)->readiness(['include_monitoring' => false]);
}

/** @return array<string, mixed> */
function sodStaffingCheck(): array
{
    foreach (sodStaffingReport()['checks'] ?? [] as $check) {
        if (($check['id'] ?? null) === 'separation_of_duties') {
            return $check;
        }
    }

    return [];
}

// ---------------------------------------------------------------------
// The staffing evaluator itself
// ---------------------------------------------------------------------

it('finds no pair on a deployment with no accounts at all', function () {
    $staffing = app(LegacyRmeSodStaffing::class)->evaluate();

    expect($staffing['wave_creator_accounts'])->toBe(0);
    expect($staffing['wave_approver_accounts'])->toBe(0);
    expect($staffing['distinct_creator_approver_pair_available'])->toBeFalse();
    expect($staffing['distinct_maker_publisher_pair_available'])->toBeFalse();
});

it('does not accept a single all-powerful account as a separated pair', function () {
    // THE TRAP. A lone Super Admin passes Gate::before for both halves, so a
    // naive "can anyone create? can anyone approve?" check says yes twice and
    // concludes the duties are separated. They are not — it is one person.
    userInRole('Super Admin');

    $staffing = app(LegacyRmeSodStaffing::class)->evaluate();

    expect($staffing['wave_creator_accounts'])->toBe(1);
    expect($staffing['wave_approver_accounts'])->toBe(1);
    expect($staffing['distinct_creator_approver_pair_available'])->toBeFalse();
});

it('counts the Super Admin bypass as real capability when a second account exists', function () {
    // Capability is decided by asking the authorization layer, so the bypass
    // is honoured rather than special-cased away: a Super Admin genuinely can
    // register a batch, and the seeded governance role genuinely can approve.
    userInRole('Super Admin');
    userInRole('Supervisor RME');

    $staffing = app(LegacyRmeSodStaffing::class)->evaluate();

    expect($staffing['distinct_creator_approver_pair_available'])->toBeTrue();
});

it('ignores deactivated accounts, which cannot sign in to staff anything', function () {
    userWith(['manage_legacy_rme_migration_operations']);
    $approver = userWith(['approve_legacy_rme_migration_wave']);
    $approver->forceFill(['is_active' => false])->save();

    $staffing = app(LegacyRmeSodStaffing::class)->evaluate();

    expect($staffing['wave_approver_accounts'])->toBe(0);
    expect($staffing['distinct_creator_approver_pair_available'])->toBeFalse();
});

it('ignores soft-deleted accounts', function () {
    userWith(['manage_legacy_rme_migration_operations']);
    userWith(['approve_legacy_rme_migration_wave'])->delete();

    expect(app(LegacyRmeSodStaffing::class)->evaluate()['distinct_creator_approver_pair_available'])->toBeFalse();
});

it('recognises the real production topology as properly staffed', function () {
    // Governance registers, branch intake files, a separate checker certifies
    // and approves. Three accounts, neither pair concentrated.
    legacyRmeStaffSeparationOfDuties();

    $staffing = app(LegacyRmeSodStaffing::class)->evaluate();

    expect($staffing['distinct_creator_approver_pair_available'])->toBeTrue();
    expect($staffing['distinct_maker_publisher_pair_available'])->toBeTrue();
});

// ---------------------------------------------------------------------
// What readiness now reports
// ---------------------------------------------------------------------

it('refuses to report ready when the enforced approver rule cannot be staffed', function () {
    // THE REGRESSION. Publisher pair present, approver absent — exactly the
    // production shape that used to report GO.
    userWith(['manage_legacy_rme_migration_operations']);
    userWith(['create_legacy_rme_imports']);
    userWith(['publish_legacy_rme_imports']);

    $report = sodStaffingReport();
    $check = sodStaffingCheck();

    expect($check['status'])->toBe('FAIL');
    expect($check['severity'])->toBe('INCIDENT');
    expect($check['context']['distinct_creator_approver_pair_available'])->toBeFalse();
    expect($report['decision'])->toBe('NO_GO');
    expect($report['ready_for_routine_batch'])->toBeFalse();
    expect($report['stop_the_line'])->toContain('SOD_STAFFING_UNAVAILABLE');
});

it('refuses to report ready when one account would both file and certify', function () {
    // maker == publisher: the account that uploads is the account that
    // certifies. The invariant is on; nobody can satisfy it.
    userWith(['manage_legacy_rme_migration_operations']);
    userWith(['approve_legacy_rme_migration_wave']);
    userWith(['create_legacy_rme_imports', 'review_legacy_rme_imports', 'publish_legacy_rme_imports']);

    $check = sodStaffingCheck();

    expect($check['status'])->toBe('FAIL');
    expect($check['context']['distinct_maker_publisher_pair_available'])->toBeFalse();
    expect(sodStaffingReport()['ready_for_routine_batch'])->toBeFalse();
});

it('refuses to report ready when nobody but the maker can review', function () {
    // THE CHAIN DEAD-END. SeparatePublisherGuard guards [REVIEW, PUBLISH] and
    // LegacyRmeImportStatus only allows READY_FOR_REVIEW -> REVIEWED ->
    // PUBLISHED, so an import nobody else can review can never be published by
    // anyone. A create/publish-only view of the pair would call this staffed.
    userWith(['manage_legacy_rme_migration_operations']);
    userWith(['approve_legacy_rme_migration_wave']);
    userWith(['create_legacy_rme_imports', 'review_legacy_rme_imports']);
    userWith(['publish_legacy_rme_imports']);

    $staffing = app(LegacyRmeSodStaffing::class)->evaluate();

    expect($staffing['distinct_maker_publisher_pair_available'])->toBeTrue();
    expect($staffing['distinct_maker_reviewer_pair_available'])->toBeFalse();
    expect($staffing['document_chain_staffed'])->toBeFalse();

    $check = sodStaffingCheck();

    expect($check['status'])->toBe('FAIL');
    expect($check['context']['code'])->toBe('SOD_STAFFING_UNAVAILABLE');
    expect(sodStaffingReport()['ready_for_routine_batch'])->toBeFalse();
});

it('refuses two makers who between them can review and publish but never for one document', function () {
    // THE DECOMPOSITION TRAP. Both pair tests pass with a DIFFERENT maker in
    // each, so ANDing them reports a staffed chain — while nothing is
    // publishable:
    //   A files -> B reviews -> publish needs A, who uploaded it. Blocked.
    //   B files -> review needs B, who uploaded it. Blocked.
    // The chain must be satisfiable by ONE maker, not by two taking turns.
    userWith(['manage_legacy_rme_migration_operations']);
    userWith(['approve_legacy_rme_migration_wave']);
    userWith(['create_legacy_rme_imports', 'publish_legacy_rme_imports']);   // A
    userWith(['create_legacy_rme_imports', 'review_legacy_rme_imports']);    // B

    $staffing = app(LegacyRmeSodStaffing::class)->evaluate();

    // Both pair views look satisfied in isolation — that is the trap.
    expect($staffing['distinct_maker_reviewer_pair_available'])->toBeTrue();
    expect($staffing['distinct_maker_publisher_pair_available'])->toBeTrue();

    // The chain itself is not staffed.
    expect($staffing['document_chain_staffed'])->toBeFalse();
    expect(sodStaffingCheck()['status'])->toBe('FAIL');
    expect(sodStaffingReport()['ready_for_routine_batch'])->toBeFalse();
});

it('accepts one checker who both reviews and publishes for a distinct maker', function () {
    // The real seeded topology: Supervisor RME holds review AND publish, and
    // SeparatePublisherGuard only ever excludes the uploader — so one checker
    // covering both later steps is legitimate, not a concentration.
    userWith(['manage_legacy_rme_migration_operations']);
    userWith(['approve_legacy_rme_migration_wave']);
    userWith(['create_legacy_rme_imports']);
    userWith(['review_legacy_rme_imports', 'publish_legacy_rme_imports']);

    expect(app(LegacyRmeSodStaffing::class)->evaluate()['document_chain_staffed'])->toBeTrue();
});

it('refuses to report ready when nobody can review at all', function () {
    userWith(['manage_legacy_rme_migration_operations']);
    userWith(['approve_legacy_rme_migration_wave']);
    userWith(['create_legacy_rme_imports']);
    userWith(['publish_legacy_rme_imports']);

    $staffing = app(LegacyRmeSodStaffing::class)->evaluate();

    expect($staffing['import_reviewer_accounts'])->toBe(0);
    expect($staffing['document_chain_staffed'])->toBeFalse();
    expect(sodStaffingCheck()['status'])->toBe('FAIL');
});

it('reports GO once both pairs are staffed by distinct accounts', function () {
    legacyRmeStaffSeparationOfDuties();

    $check = sodStaffingCheck();

    expect($check['status'])->toBe('GO');
    expect($check['context']['distinct_creator_approver_pair_available'])->toBeTrue();
    expect($check['context']['distinct_maker_publisher_pair_available'])->toBeTrue();
});

it('still refuses first when the publisher invariant is switched off entirely', function () {
    // Precedence unchanged: a disabled invariant is reported as the disabled
    // invariant, not as a staffing gap, so the remediation stays correct.
    legacyRmeStaffSeparationOfDuties();
    config()->set('legacy_rme_operations.require_separate_publisher', false);

    $check = sodStaffingCheck();

    expect($check['status'])->toBe('FAIL');
    expect($check['context']['code'])->toBe('SEPARATE_PUBLISHER_DISABLED');
});

it('keeps the documented accepted risk a warning when the approver rule is off', function () {
    // With the approver switch deliberately off, an unstaffed approver is not
    // a contradiction — nothing is claiming to enforce it.
    legacyRmeStaffSeparationOfDuties();
    config()->set('legacy_rme_operations.require_separate_approver', false);

    $check = sodStaffingCheck();

    expect($check['status'])->toBe('WATCH');
    expect($check['severity'])->toBe('WARNING');
});

// ---------------------------------------------------------------------
// Honesty and privacy of the report
// ---------------------------------------------------------------------

it('never claims to have verified that two different humans are involved', function () {
    legacyRmeStaffSeparationOfDuties();

    $context = sodStaffingCheck()['context'];

    // Accounts are observable; humans are not. Both claims are reported, and
    // they are never conflated.
    expect($context['human_separation_verifiable_by_application'])->toBeFalse();
    expect($context['account_separation_verifiable_by_application'])->toBeTrue();
});

it('reports staffing as counts and never as identities', function () {
    // A readiness report gets pasted into tickets and chat. It needs to say an
    // approver exists, not who it is.
    $checker = User::factory()->create([
        'name' => 'Jene Monika',
        'email' => 'jenemonika@example.test',
    ]);
    $checker->givePermissionTo(['approve_legacy_rme_migration_wave', 'publish_legacy_rme_imports']);
    userWith(['manage_legacy_rme_migration_operations']);
    userWith(['create_legacy_rme_imports']);

    $encoded = json_encode(sodStaffingReport());

    expect($encoded)->not->toContain('Jene Monika');
    expect($encoded)->not->toContain('jenemonika@example.test');
    expect($encoded)->toContain('wave_approver_accounts');
});

it('does not decide staffing from whatever batch happens to exist', function () {
    // The wave policy also requires a non-terminal wave, so probing it would
    // report "nobody can approve" whenever the last batch was cancelled —
    // which is precisely the state production was left in after
    // ROUTINE-20260819-TKM1-01 was cancelled. Staffing is a property of the
    // deployment, not of a row.
    legacyRmeStaffSeparationOfDuties();

    LegacyRmeMigrationWave::factory()->create([
        'code' => 'ROUTINE-CANCELLED-01',
        'status' => LegacyRmeWaveStatus::CANCELLED,
    ]);

    expect(sodStaffingCheck()['status'])->toBe('GO');
});
