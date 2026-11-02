<?php

declare(strict_types=1);

/*
| ACTIVATION-RME-DOCTOR-PERFORMED-TREATMENTS-1 (rule 219 §15; owner DPT-Q8) — the
| ONE-BRANCH pilot of the doctor's "Tindakan Dilakukan" record (ACT-DPT-001 …).
|
| `rme.doctor_performed_treatments` is a single global switch. The pilot exists
| only because a source-controlled, fail-closed branch allowlist decides WHERE an
| ON flag reaches — at the service (the mutation authority, under the visit row
| lock) and mirrored at the request (404), the policy, the RME page, the cashier
| projection and the rule 218 evidence. These tests flip the allowlist away from
| the fixture branch and require every one of those surfaces to go dark.
*/

use App\Modules\Branch\Models\Branch;
use App\Modules\ClinicVisit\Models\ClinicVisit;
use App\Modules\LabOrder\Models\AuditLog;
use App\Modules\PerformedTreatment\Models\RmePerformedTreatment;
use App\Modules\PerformedTreatment\Models\RmeTreatmentDeclaration;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentFeatureGuard;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentPilotScope;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentRefusal;
use App\Modules\PerformedTreatment\Support\RmePerformedTreatmentState;
use App\Modules\RmeInvoice\Models\RmeInvoice;
use App\Modules\RmeInvoice\Models\RmePayment;
use App\Modules\RmeInvoice\Support\RmeFinancialAuditEvent;
use App\Modules\RmeInvoice\Support\RmeZeroValueClosureFeatureGuard;
use App\Modules\Treatment\Models\Treatment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/helpers.php';

uses(RefreshDatabase::class);

beforeEach(fn () => dptSetup());

/** @return array<string, mixed> */
function dptpPayload(Treatment $treatment): array
{
    return ['treatment_id' => $treatment->id, 'quantity' => 1, 'submission_token' => (string) Str::uuid()];
}

/** Another active RME branch, which the pilot does NOT name unless a test says so. */
function dptpOtherBranch(string $code = 'DPX9'): Branch
{
    return Branch::factory()->create(['code' => $code, 'is_rme_enabled' => true, 'is_active' => true]);
}

function dptpScope(): RmePerformedTreatmentPilotScope
{
    return app(RmePerformedTreatmentPilotScope::class);
}

/** Assert every write is refused with the pilot code, and that nothing was written. */
function dptpAssertWritesRefused(ClinicVisit $visit, string $code): void
{
    $rows = RmePerformedTreatment::query()->count();
    $declarations = RmeTreatmentDeclaration::query()->count();

    expect(dptRefusal(fn () => dptRecord($visit, test()->scaling)))->toContain('['.$code.']')
        ->and(dptRefusal(fn () => dptDeclare($visit, RmeTreatmentDeclaration::TYPE_NO_TREATMENT)))->toContain('['.$code.']')
        ->and(RmePerformedTreatment::query()->count())->toBe($rows)
        ->and(RmeTreatmentDeclaration::query()->count())->toBe($declarations);
}

// ─── Flag and branch scope ───────────────────────────────────────────────────

it('ACT-DPT-001 refuses authoring while the flag is OFF even on the pilot branch', function () {
    dptFlag(false);
    $visit = dptEncounter();

    dptpAssertWritesRefused($visit, RmePerformedTreatmentRefusal::NOT_ENABLED);
    expect(dptService()->workspaceFor($visit, $this->doctorUser))->toBeNull();
});

it('ACT-DPT-003 admits the approved pilot branch — the treating doctor records', function () {
    $visit = dptEncounter();

    expect(dptService()->enabledFor($visit))->toBeTrue()
        ->and(dptRecord($visit, $this->scaling)->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED)
        ->and(dptService()->workspaceFor($visit->fresh(), $this->doctorUser))->not->toBeNull();
});

it('ACT-DPT-004 denies every non-pilot branch with the flag ON — service, page, cashier and rule 218 all go dark', function () {
    dptPilot([dptpOtherBranch()->code]);
    $visit = dptEncounter();

    expect(dptService()->enabledFor($visit))->toBeFalse();
    dptpAssertWritesRefused($visit, RmePerformedTreatmentRefusal::BRANCH_NOT_IN_PILOT);
    expect(dptService()->workspaceFor($visit, $this->doctorUser))->toBeNull()
        ->and(dptService()->evidenceFor($visit))->toBeNull()
        ->and(dptService()->operationalCategoryFor($visit))->toBeNull()
        ->and(dptService()->cashierProjectionFor($visit, null))->toBeNull();
});

it('ACT-DPT-004b answers 404 on every write route of a non-pilot branch (pilot invisible, never a different error)', function () {
    $visit = dptEncounter();
    $row = dptRecord($visit, $this->scaling);
    dptPilot([dptpOtherBranch()->code]);

    $this->actingAs($this->doctorUser)->post(route('rme.visits.performed-treatments.store', $visit), dptpPayload($this->filling))->assertNotFound();
    $this->actingAs($this->doctorUser)->post(route('rme.visits.performed-treatments.declaration', $visit), ['declaration_type' => 'has_treatment'])->assertNotFound();
    $this->actingAs($this->doctorUser)->post(route('rme.visits.performed-treatments.void', [$visit, $row]), ['void_reason' => 'Uji pilot.'])->assertNotFound();

    expect($row->fresh()->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED);
});

it('ACT-DPT-005 denies every branch while the allowlist is empty', function () {
    dptPilot([]);
    $visit = dptEncounter();

    expect(dptpScope()->admittedBranchIds())->toBe([])
        ->and(dptpScope()->configurationStatus())->toBe(RmePerformedTreatmentPilotScope::STATUS_EMPTY);
    dptpAssertWritesRefused($visit, RmePerformedTreatmentRefusal::BRANCH_NOT_IN_PILOT);
});

it('ACT-DPT-006 denies every branch for an unknown branch code', function () {
    dptPilot(['ZZZ9']);
    $visit = dptEncounter();

    expect(dptpScope()->admittedBranchIds())->toBe([])
        ->and(dptpScope()->configurationStatus())->toBe(RmePerformedTreatmentPilotScope::STATUS_UNRESOLVED);
    dptpAssertWritesRefused($visit, RmePerformedTreatmentRefusal::BRANCH_NOT_IN_PILOT);
});

it('ACT-DPT-006b denies every branch for a malformed allowlist (not a list of strings)', function (mixed $raw) {
    dptPilot($raw);
    $visit = dptEncounter();

    expect(dptpScope()->admittedBranchIds())->toBe([])
        ->and(dptpScope()->configurationStatus())->toBe(RmePerformedTreatmentPilotScope::STATUS_MALFORMED);
    dptpAssertWritesRefused($visit, RmePerformedTreatmentRefusal::BRANCH_NOT_IN_PILOT);
})->with([
    'a string' => fn () => (string) test()->branch->code,
    'a non-string entry' => fn () => [(string) test()->branch->code, 5],
    'null' => [null],
    'a nested array' => fn () => [[(string) test()->branch->code]],
]);

it('ACT-DPT-006c denies every branch when MAIN is listed, even beside the pilot branch', function () {
    dptPilot([(string) $this->branch->code, 'MAIN']);
    $visit = dptEncounter();

    expect(dptpScope()->configurationStatus())->toBe(RmePerformedTreatmentPilotScope::STATUS_MAIN_LISTED);
    dptpAssertWritesRefused($visit, RmePerformedTreatmentRefusal::BRANCH_NOT_IN_PILOT);
});

it('ACT-DPT-006d treats a second branch as an INVALID configuration and denies BOTH (DPT-Q8: one branch)', function () {
    $other = dptpOtherBranch();
    dptPilot([(string) $this->branch->code, (string) $other->code]);
    $visit = dptEncounter();

    expect(dptpScope()->configurationStatus())->toBe(RmePerformedTreatmentPilotScope::STATUS_TOO_MANY_BRANCHES)
        ->and(dptpScope()->admittedBranchIds())->toBe([]);
    dptpAssertWritesRefused($visit, RmePerformedTreatmentRefusal::BRANCH_NOT_IN_PILOT);
});

it('ACT-DPT-006e canonicalizes a deprecated alias (SUN4 → SPN4) but never admits an alias-coded branch', function () {
    $sunu = Branch::factory()->create(['code' => 'SPN4', 'is_rme_enabled' => true, 'is_active' => true]);
    dptPilot(['sun4']);

    expect(dptpScope()->declaredCodes())->toBe(['SPN4'])
        ->and(dptpScope()->admittedBranchIds())->toBe([(int) $sunu->id])
        ->and(dptpScope()->admits((int) $this->branch->id))->toBeFalse();

    $sunu->update(['code' => 'SUN4']);
    expect(dptpScope()->admittedBranchIds())->toBe([]);
});

it('ACT-DPT-006f fails closed when two live branches normalize to the listed code (seeded duplicate)', function () {
    Branch::factory()->create(['code' => 'SPN4', 'is_rme_enabled' => true, 'is_active' => true]);
    Branch::factory()->create(['code' => 'spn4', 'is_rme_enabled' => true, 'is_active' => true]);
    dptPilot(['SPN4']);

    expect(dptpScope()->configurationStatus())->toBe(RmePerformedTreatmentPilotScope::STATUS_UNRESOLVED)
        ->and(dptpScope()->admittedBranchIds())->toBe([]);
});

it('ACT-DPT-007 admits nothing when the listed branch is inactive or not RME-enabled', function (array $state) {
    $this->branch->update($state);
    $visit = dptEncounter();

    expect(dptpScope()->admittedBranchIds())->toBe([])
        ->and(dptService()->enabledFor($visit))->toBeFalse();
})->with([
    'inactive' => [['is_active' => false]],
    'RME disabled' => [['is_rme_enabled' => false]],
]);

it('ACT-DPT-008 does not let Super Admin bypass the pilot scope — the branch refusal comes first', function () {
    dptPilot([dptpOtherBranch()->code]);
    $visit = dptEncounter();
    $admin = superAdmin();

    expect(dptRefusal(fn () => dptRecord($visit, $this->scaling, [], $admin)))
        ->toContain('['.RmePerformedTreatmentRefusal::BRANCH_NOT_IN_PILOT.']');
});

it('ACT-DPT-009 ignores a forged branch value in the request — the branch comes from the visit', function () {
    $other = dptpOtherBranch();
    dptPilot([(string) $other->code]);
    $visit = dptEncounter();

    $this->actingAs($this->doctorUser)
        ->post(route('rme.visits.performed-treatments.store', $visit), dptpPayload($this->scaling) + [
            'branch_id' => $other->id, 'branch_code' => $other->code,
        ])
        ->assertNotFound();

    expect(RmePerformedTreatment::query()->count())->toBe(0);
});

it('ACT-DPT-010 never grants authoring from a pilot-branch history — only the visit\'s own branch counts', function () {
    $pilotVisit = dptEncounter();
    dptRecord($pilotVisit, $this->scaling);
    dptCompleteExamination($pilotVisit);

    $other = dptpOtherBranch();
    $otherVisit = dptEncounter(['branch_id' => $other->id]);

    expect(dptService()->enabledFor($otherVisit))->toBeFalse()
        ->and(dptRefusal(fn () => dptRecord($otherVisit, $this->filling)))
        ->toContain('['.RmePerformedTreatmentRefusal::BRANCH_NOT_IN_PILOT.']');
});

it('ACT-DPT-011 enforces the pilot at the service boundary, re-read under the visit row lock', function () {
    $visit = dptEncounter();
    $stale = $visit->fresh(); // still carries the pilot branch_id
    $other = dptpOtherBranch();
    DB::table('trx_clinic_visits')->where('id', $visit->id)->update(['branch_id' => $other->id]);
    $this->actingAs($this->doctorUser);

    $refusal = dptRefusal(fn () => dptService()->record($stale, dptpPayload($this->scaling) + ['tooth_numbers' => []], $this->doctorUser));

    // The CODE matters: another layer (the policy's working-branch scope) would also
    // refuse, with a different code, and would hide a missing under-lock re-read.
    expect($refusal)->toContain('['.RmePerformedTreatmentRefusal::BRANCH_NOT_IN_PILOT.']')
        ->and(RmePerformedTreatment::query()->count())->toBe(0);
});

it('ACT-DPT-012 stops new authoring when the flag goes OFF after activation, and keeps every recorded row', function () {
    $visit = dptEncounter();
    $row = dptRecord($visit, $this->scaling);
    $audits = AuditLog::query()->count();

    dptFlag(false);

    dptpAssertWritesRefused($visit, RmePerformedTreatmentRefusal::NOT_ENABLED);
    expect($row->fresh()->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED)
        ->and(RmePerformedTreatment::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe($audits);
});

it('ACT-DPT-012b keeps recorded rows when the allowlist is narrowed after activation (rollback by scope)', function () {
    $visit = dptEncounter();
    $row = dptRecord($visit, $this->scaling);

    dptPilot([]);

    // The row stays as evidence, but no PUBLIC read surfaces it while the capability
    // is not live for this visit (security review LOW-1).
    expect($row->fresh()->status)->toBe(RmePerformedTreatment::STATUS_PERFORMED)
        ->and(dptService()->workspaceFor($visit->fresh(), $this->doctorUser))->toBeNull()
        ->and(dptService()->stateFor($visit->fresh()))->toBe(RmePerformedTreatmentState::NOT_RECORDED)
        ->and(dptService()->historyFor($visit->fresh()))->toHaveCount(0);

    dptPilot([(string) $this->branch->code]);
    expect(dptService()->stateFor($visit->fresh()))->toBe(RmePerformedTreatmentState::HAS_PERFORMED_TREATMENTS)
        ->and(dptService()->historyFor($visit->fresh()))->toHaveCount(1);
});

// ─── Policy and initial-pilot behaviour ──────────────────────────────────────

it('ACT-DPT-024b denies the policy ability on a non-pilot branch for the treating doctor', function () {
    $visit = dptEncounter();
    expect($this->doctorUser->can('recordPerformedTreatments', $visit))->toBeTrue();

    dptPilot([dptpOtherBranch()->code]);
    expect($this->doctorUser->can('recordPerformedTreatments', $visit->fresh()))->toBeFalse();
});

it('ACT-DPT-029 keeps the declaration OPTIONAL in the pilot — completion needs no declaration (DPT-Q3 staged)', function () {
    $visit = dptEncounter();

    $completed = dptCompleteExamination($visit);

    expect($completed->status)->toBe(ClinicVisit::STATUS_CASHIER_PENDING)
        ->and(RmeTreatmentDeclaration::query()->where('clinic_visit_id', $visit->id)->exists())->toBeFalse();
});

it('ACT-DPT-031 gives rule 218 doctor evidence only on the pilot branch — a non-pilot closure keeps snapshot v1', function () {
    dptPilot([dptpOtherBranch()->code]);
    $visit = dptEncounter();
    dptCompleteExamination($visit);

    $closed = rzvClose(rzvConsultationInvoice($visit));
    $snapshot = (array) (AuditLog::query()
        ->where('action', RmeFinancialAuditEvent::ACTION_INVOICE_ZERO_VALUE_CLOSED)
        ->where('entity_id', $closed->id)
        ->value('new_values')['clinical_billing_consistency'] ?? []);

    expect($snapshot['version'] ?? null)->toBe(1)
        ->and($snapshot)->not->toHaveKey('doctor_evidence');
});

it('ACT-DPT-033/049 writes nothing financial while authoring in the pilot', function () {
    $visit = dptEncounter();
    $invoices = RmeInvoice::query()->count();
    $payments = RmePayment::query()->count();

    dptRecord($visit, $this->scaling);
    dptDeclare($visit->fresh(), RmeTreatmentDeclaration::TYPE_HAS_TREATMENT);

    expect(RmeInvoice::query()->count())->toBe($invoices)
        ->and(RmePayment::query()->count())->toBe($payments)
        ->and($visit->fresh()->status)->toBe(ClinicVisit::STATUS_IN_PROGRESS);
});

// ─── The source-controlled configuration ─────────────────────────────────────

it('ships the pilot as exactly SPN4, source-controlled, with no environment read', function () {
    $raw = (string) file_get_contents(config_path('rme_performed_treatment.php'));
    $config = require config_path('rme_performed_treatment.php');

    expect($config['pilot']['branch_codes'])->toBe(['SPN4'])
        ->and($config['pilot']['approval']['decision'])->toBe('DPT-Q8')
        ->and(preg_match('/\benv\s*\(/', $raw))->toBe(0)
        ->and(RmePerformedTreatmentPilotScope::MAX_PILOT_BRANCHES)->toBe(1);
});

it('ACT-DPT-034 keeps both flags committed OFF — the deploy is inert, NO_CHARGE stays a separate decision', function () {
    $flags = require config_path('feature_flags.php');

    expect($flags['flags'][RmePerformedTreatmentFeatureGuard::FLAG]['default'])->toBeFalse()
        ->and($flags['flags'][RmeZeroValueClosureFeatureGuard::FLAG]['default'])->toBeFalse();
});

// ─── The read-only readiness probe ───────────────────────────────────────────

it('reports READY_NOT_ARMED with the flag OFF and a valid single-branch allowlist, and writes nothing', function () {
    dptFlag(false);
    $before = [RmePerformedTreatment::query()->count(), AuditLog::query()->count()];

    $exit = Artisan::call('rme:performed-treatment-readiness', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($report['verdict'])->toBe('READY_NOT_ARMED')
        ->and($report['pilot']['configuration_status'])->toBe(RmePerformedTreatmentPilotScope::STATUS_VALID)
        ->and($report['pilot']['admitted_branch_codes'])->toBe([(string) $this->branch->code])
        ->and([RmePerformedTreatment::query()->count(), AuditLog::query()->count()])->toBe($before);

    // Asserting "on" against an OFF flag must fail — the activation ceremony relies on it.
    expect(Artisan::call('rme:performed-treatment-readiness', ['--json' => true, '--expect' => 'on']))->toBe(1)
        ->and(Artisan::call('rme:performed-treatment-readiness', ['--json' => true, '--expect' => 'off']))->toBe(0);
});

it('reports ARMED with the flag ON, every other RME branch DENIED, and enforces --expect', function () {
    $other = dptpOtherBranch();

    $exit = Artisan::call('rme:performed-treatment-readiness', ['--json' => true, '--expect' => 'on']);
    $report = json_decode(Artisan::output(), true);

    expect($exit)->toBe(0)
        ->and($report['verdict'])->toBe('ARMED')
        ->and($report['branches'][(string) $this->branch->code])->toBe('ADMITTED')
        ->and($report['branches'][(string) $other->code])->toBe('DENIED');

    expect(Artisan::call('rme:performed-treatment-readiness', ['--json' => true, '--expect' => 'off']))->toBe(1);
});

it('fails the probe on an invalid allowlist (two branches) — never a silent DENY_ALL', function () {
    dptPilot([(string) $this->branch->code, (string) dptpOtherBranch()->code]);

    $exit = Artisan::call('rme:performed-treatment-readiness', ['--json' => true]);
    $report = json_decode(Artisan::output(), true);

    expect($exit)->toBe(1)
        ->and($report['verdict'])->toBe('ARMED_INVALID_CONFIGURATION')
        ->and($report['pilot']['admitted_branch_codes'])->toBe([]);
});

it('keeps the probe free of patient identity', function () {
    $visit = dptEncounter();
    dptRecord($visit, $this->scaling);

    Artisan::call('rme:performed-treatment-readiness', ['--json' => true]);
    $out = Artisan::output();

    expect($out)->not->toContain((string) $this->patient->name)
        ->and($out)->not->toContain((string) ($this->patient->ktp_number ?? 'no-ktp-on-fixture'))
        ->and($out)->not->toContain((string) $this->patient->medical_record_number);
});
