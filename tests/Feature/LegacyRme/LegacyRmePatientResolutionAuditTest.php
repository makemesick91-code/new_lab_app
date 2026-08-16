<?php

/**
 * LEGACY-RME-MASTERDATA-1 — patient identity is EXACT, and stays exact.
 *
 * These tests exist because of a real production case: a historical Landak
 * document whose Nomor RM reads `27541`, while the patient master contains
 * `DG-LDK2-2026-22541` — one digit away. Binding those two would have filed a
 * stranger's clinical history against a real patient.
 *
 * The negative cases are therefore the point of this file, not an afterthought:
 * a near miss must never become a match, a shorter number must never swallow a
 * longer one, and an ambiguous master must refuse rather than pick a row.
 */

use App\Modules\Branch\Models\Branch;
use App\Modules\LegacyRme\Services\LegacyRmePatientResolutionAuditService;
use App\Modules\LegacyRme\Support\LegacyRmeImportStatus;
use App\Modules\LegacyRme\Support\LegacyRmePatientResolution;
use App\Modules\Patient\Models\Patient;
use Illuminate\Database\UniqueConstraintViolationException;

function mdAudit(): LegacyRmePatientResolutionAuditService
{
    return app(LegacyRmePatientResolutionAuditService::class);
}

function mdBranch(string $code): Branch
{
    return Branch::factory()->create([
        'code' => $code,
        'is_active' => true,
        'is_rme_enabled' => true,
    ]);
}

function mdPatient(string $rm, ?Branch $branch = null, array $overrides = []): Patient
{
    return Patient::factory()->create($overrides + [
        'medical_record_number' => $rm,
        'branch_id' => ($branch ?? mdBranch('LDK2'))->id,
    ]);
}

/*
|--------------------------------------------------------------------------
| The production case, pinned
|--------------------------------------------------------------------------
*/

it('does not resolve RM 27541 to the one-digit-away patient 22541', function () {
    $branch = mdBranch('LDK2');
    $near = mdPatient('DG-LDK2-2026-22541', $branch);

    $result = mdAudit()->resolve('27541');

    expect($result['resolution'])->toBe(LegacyRmePatientResolution::CODE_NOT_FOUND)
        ->and($result['resolved'])->toBeFalse()
        ->and($result['bindable'])->toBeFalse()
        ->and($result['match_count'])->toBe(0)
        ->and($result['matches'])->toBe([]);

    // The near miss is surfaced, but only ever as a lead.
    $ids = array_column($result['investigative_signal'], 'patient_id');
    expect($ids)->toContain($near->id);

    foreach ($result['investigative_signal'] as $signal) {
        expect($signal['bindable'])->toBeFalse()
            ->and($signal['identity_authority'])->toBeFalse()
            ->and($signal['note'])->toBe('INVESTIGATIVE_SIGNAL_ONLY');
    }
});

it('keeps 27541 unresolved even when the whole LDK2 neighbourhood exists', function () {
    $branch = mdBranch('LDK2');

    foreach (['22541', '22623', '22676', '22681', '12020', '8445', '7505'] as $manual) {
        mdPatient('DG-LDK2-2026-'.$manual, $branch);
    }

    $result = mdAudit()->resolve('27541');

    expect($result['resolution'])->toBe(LegacyRmePatientResolution::CODE_NOT_FOUND)
        ->and($result['bindable'])->toBeFalse()
        ->and($result['matches'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Significant digits are never dropped
|--------------------------------------------------------------------------
*/

it('refuses to match a shorter number against a longer manual segment', function () {
    $branch = mdBranch('LDK2');
    $longer = mdPatient('DG-LDK2-2026-22541', $branch);

    // `LIKE '%2541'` WOULD hit 22541. Matching on the whole manual segment must not.
    $result = mdAudit()->resolve('2541');

    expect($result['resolution'])->toBe(LegacyRmePatientResolution::CODE_NOT_FOUND)
        ->and($result['bindable'])->toBeFalse()
        ->and($result['matches'])->toBe([]);

    // It is still reported, so the operator can see WHY it was not used.
    expect(array_column($result['suffix_crossed_manual_segment'], 'patient_id'))
        ->toContain($longer->id);
});

it('resolves a raw manual number that equals the whole manual segment', function () {
    $branch = mdBranch('LDK2');
    $patient = mdPatient('DG-LDK2-2026-27541', $branch);

    $result = mdAudit()->resolve('27541');

    expect($result['resolution'])->toBe(LegacyRmePatientResolution::CODE_SEGMENT_UNIQUE)
        ->and($result['resolved'])->toBeTrue()
        ->and($result['bindable'])->toBeTrue()
        ->and($result['match_count'])->toBe(1)
        ->and($result['matches'][0]['patient_id'])->toBe($patient->id)
        ->and($result['matches'][0]['manual_segment'])->toBe('27541')
        ->and($result['matches'][0]['branch_code'])->toBe('LDK2');
});

it('preserves leading zeros as significant digits', function () {
    $branch = mdBranch('LDK2');
    mdPatient('DG-LDK2-2026-0541', $branch);

    expect(mdAudit()->resolve('541')['resolution'])
        ->toBe(LegacyRmePatientResolution::CODE_TOO_SHORT);

    expect(mdAudit()->resolve('0541')['resolution'])
        ->toBe(LegacyRmePatientResolution::CODE_SEGMENT_UNIQUE);
});

it('refuses an input shorter than the minimum safe length', function () {
    mdPatient('DG-LDK2-2026-541');

    $result = mdAudit()->resolve('541');

    expect($result['resolution'])->toBe(LegacyRmePatientResolution::CODE_TOO_SHORT)
        ->and($result['bindable'])->toBeFalse()
        ->and($result['matches'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Exact identity, ambiguity and soft-deleted rows
|--------------------------------------------------------------------------
*/

it('resolves a full canonical Nomor RM exactly', function () {
    $patient = mdPatient('DG-LDK2-2026-22676');

    $result = mdAudit()->resolve('DG-LDK2-2026-22676');

    expect($result['resolution'])->toBe(LegacyRmePatientResolution::CODE_EXACT_UNIQUE)
        ->and($result['bindable'])->toBeTrue()
        ->and($result['matches'][0]['patient_id'])->toBe($patient->id);
});

it('cannot produce an exactly duplicated Nomor RM, because the schema forbids it', function () {
    $branch = mdBranch('LDK2');
    mdPatient('DG-LDK2-2026-22541', $branch);

    // This is WHY the EXACT_AMBIGUOUS branch is unreachable in practice: a
    // non-partial UNIQUE index owns exact uniqueness, soft-deleted rows
    // included. The branch is kept anyway — the alternative, if the constraint
    // were ever relaxed, would be to silently pick a row.
    expect(fn () => mdPatient('DG-LDK2-2026-22541', $branch))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(LegacyRmePatientResolution::isResolved(LegacyRmePatientResolution::CODE_EXACT_AMBIGUOUS))->toBeTrue()
        ->and(LegacyRmePatientResolution::isBindable(LegacyRmePatientResolution::CODE_EXACT_AMBIGUOUS))->toBeFalse();
});

it('refuses to pick a row when a raw manual number is ambiguous across branches', function () {
    mdPatient('DG-LDK2-2026-27541', mdBranch('LDK2'));
    mdPatient('DG-TKM1-2026-27541', mdBranch('TKM1'));

    $result = mdAudit()->resolve('27541');

    expect($result['resolution'])->toBe(LegacyRmePatientResolution::CODE_SEGMENT_AMBIGUOUS)
        ->and($result['bindable'])->toBeFalse()
        ->and($result['match_count'])->toBe(2);
});

it('still finds a soft-deleted patient, because it still owns its Nomor RM', function () {
    $patient = mdPatient('DG-LDK2-2026-27541');
    $patient->delete();

    $result = mdAudit()->resolve('27541');

    expect($result['resolved'])->toBeTrue()
        ->and($result['matches'][0]['is_soft_deleted'])->toBeTrue();
});

it('never returns a near miss when the number resolved exactly', function () {
    $branch = mdBranch('LDK2');
    mdPatient('DG-LDK2-2026-22541', $branch);
    $exact = mdPatient('DG-LDK2-2026-27541', $branch);

    $result = mdAudit()->resolve('27541');

    expect($result['matches'])->toHaveCount(1)
        ->and($result['matches'][0]['patient_id'])->toBe($exact->id)
        ->and(array_column($result['investigative_signal'], 'patient_id'))
        ->not->toContain($exact->id);
});

it('treats an empty Nomor RM as no question asked', function () {
    $result = mdAudit()->resolve('   ');

    expect($result['resolution'])->toBe(LegacyRmePatientResolution::CODE_EMPTY_INPUT)
        ->and($result['bindable'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Master-data integrity
|--------------------------------------------------------------------------
*/

it('flags a live patient whose Nomor RM the canonical parser cannot read', function () {
    $branch = mdBranch('LDK2');
    mdPatient('DG-LDK2-2026-22541', $branch);
    $broken = mdPatient('77727222', $branch);

    $integrity = mdAudit()->integrity();

    expect($integrity['unparseable_count'])->toBe(1);

    $flagged = $integrity['unparseable_medical_record_numbers'][0];

    expect($flagged['patient_id'])->toBe($broken->id)
        ->and($flagged['consequence'])->toBe('ARCHIVE_BRANCH_UNRESOLVABLE');
});

it('reports no duplicated Nomor RM while the unique index holds', function () {
    $branch = mdBranch('LDK2');
    mdPatient('DG-LDK2-2026-22541', $branch);
    mdPatient('DG-LDK2-2026-22676', $branch);

    // The duplicate check is defence in depth against the index being relaxed,
    // so on a sound schema it must report exactly nothing — a check that cries
    // wolf is a check operators learn to ignore.
    expect(mdAudit()->integrity()['duplicate_count'])->toBe(0);
});

it('reports a clean master as clean', function () {
    $branch = mdBranch('LDK2');
    mdPatient('DG-LDK2-2026-22541', $branch);
    mdPatient('DG-LDK2-2026-22676', $branch);

    $integrity = mdAudit()->integrity();

    expect($integrity['unparseable_count'])->toBe(0)
        ->and($integrity['duplicate_count'])->toBe(0)
        ->and($integrity['archive_bindings']['published'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The command
|--------------------------------------------------------------------------
*/

it('exits zero for a Nomor RM that simply does not exist', function () {
    mdPatient('DG-LDK2-2026-22541');

    // "Not found" is a truthful answer about the master, not a defect in it.
    $this->artisan('legacy-rme:patient-resolution-audit', ['--rm' => '27541', '--strict' => true])
        ->assertExitCode(0);
});

it('fails strict on a real master-data defect', function () {
    mdPatient('77727222');

    $this->artisan('legacy-rme:patient-resolution-audit', ['--strict' => true])
        ->assertExitCode(1);

    // Without --strict it still reports, but never blocks a deploy gate.
    $this->artisan('legacy-rme:patient-resolution-audit')->assertExitCode(0);
});

it('emits PII-free JSON that never carries a name, KTP or birth date', function () {
    $branch = mdBranch('LDK2');
    mdPatient('DG-LDK2-2026-22541', $branch, [
        'name' => 'Sensitive Person',
        'ktp_number' => '7371010101010001',
    ]);

    $this->artisan('legacy-rme:patient-resolution-audit', ['--rm' => '27541', '--json' => true])
        ->doesntExpectOutputToContain('Sensitive Person')
        ->doesntExpectOutputToContain('7371010101010001')
        ->assertExitCode(0);
});

it('changes nothing it looks at', function () {
    $branch = mdBranch('LDK2');
    $patient = mdPatient('DG-LDK2-2026-22541', $branch);
    $before = Patient::withTrashed()->get(['id', 'medical_record_number', 'branch_id', 'deleted_at'])->toArray();

    mdAudit()->resolve('27541');
    mdAudit()->resolve('2541');
    mdAudit()->integrity();

    expect(Patient::withTrashed()->get(['id', 'medical_record_number', 'branch_id', 'deleted_at'])->toArray())
        ->toBe($before)
        ->and(Patient::withTrashed()->count())->toBe(1)
        ->and($patient->fresh()->medical_record_number)->toBe('DG-LDK2-2026-22541');
});

it('keeps the resolution vocabulary stable', function () {
    // Callers branch on these codes; renaming one silently breaks every consumer.
    expect(LegacyRmePatientResolution::BINDABLE)->toBe(['EXACT_UNIQUE', 'SEGMENT_UNIQUE'])
        ->and(LegacyRmePatientResolution::isBindable(LegacyRmePatientResolution::CODE_EXACT_AMBIGUOUS))->toBeFalse()
        ->and(LegacyRmePatientResolution::isBindable(LegacyRmePatientResolution::CODE_SEGMENT_AMBIGUOUS))->toBeFalse()
        ->and(LegacyRmePatientResolution::isBindable(LegacyRmePatientResolution::CODE_NOT_FOUND))->toBeFalse()
        ->and(LegacyRmePatientResolution::isResolved(LegacyRmePatientResolution::CODE_NOT_FOUND))->toBeFalse();
});

it('does not treat a published import status as a withdrawn one', function () {
    // Guards the binding report's published/withdrawn split against a status rename.
    expect(LegacyRmeImportStatus::PUBLISHED)->toBe('PUBLISHED')
        ->and(LegacyRmeImportStatus::CANCELLED)->toBe('CANCELLED');
});
