<?php

declare(strict_types=1);

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\BranchCodeAlias;
use App\Modules\LegacyOdontogram\Interfaces\LegacyOdontogramPatientRepositoryInterface;
use App\Modules\LegacyRme\Services\LegacyRmeBranchAdmissionService;
use App\Modules\LegacyRme\Services\LegacyRmeBranchResolver;
use App\Modules\LegacyRme\Services\LegacyRmePatientResolutionAuditService;
use App\Modules\LegacyRme\Services\LegacyRmeSourcePatientBindingService;
use App\Modules\LegacyRme\Support\LegacyRmeBranchResolution;
use App\Modules\Patient\Interfaces\PatientRepositoryInterface;
use App\Modules\Patient\Models\Patient;
use App\Modules\Patient\Services\CrossBranchPatientLookupService;
use App\Modules\Patient\Services\PatientMedicalRecordNumberService;
use Database\Seeders\BranchSeeder;
use Database\Seeders\RmeBranchSeeder;

/**
 * REVISION-TELKOMAS-BRANCH-CODE-TKM1-TO-TLK1-1
 *
 * Cabang Telkomas' canonical branch code is TLK1. TKM1 is the DEPRECATED
 * historical code: still recognised, never emitted.
 *
 * These tests pin the contract from both directions — that the canonical code
 * works, and that the historical one keeps resolving to the SAME branch so the
 * card in a patient's wallet and the number printed on an archived document do
 * not stop working the day the code was revised.
 */

/*
|--------------------------------------------------------------------------
| The alias policy itself
|--------------------------------------------------------------------------
*/

it('canonicalizes the deprecated Telkomas code and leaves the canonical one alone', function () {
    expect(BranchCodeAlias::canonicalize('TKM1'))->toBe('TLK1')
        ->and(BranchCodeAlias::canonicalize('TLK1'))->toBe('TLK1')
        ->and(BranchCodeAlias::isHistoricalAlias('TKM1'))->toBeTrue()
        ->and(BranchCodeAlias::isHistoricalAlias('TLK1'))->toBeFalse();
});

it('normalizes case and surrounding whitespace before matching', function () {
    expect(BranchCodeAlias::canonicalize('  tkm1 '))->toBe('TLK1')
        ->and(BranchCodeAlias::canonicalize('tlk1'))->toBe('TLK1');
});

it('is idempotent — canonicalizing twice is canonicalizing once', function () {
    $once = BranchCodeAlias::canonicalize('TKM1');

    expect(BranchCodeAlias::canonicalize($once))->toBe($once)->toBe('TLK1');
});

it('never re-emits a deprecated code for a canonical one', function () {
    // The map is one-way by construction: no canonical code may be a KEY.
    foreach (BranchCodeAlias::all() as $deprecated => $canonical) {
        expect(BranchCodeAlias::canonicalize($canonical))->toBe($canonical)
            ->and(array_key_exists($canonical, BranchCodeAlias::all()))->toBeFalse()
            ->and($deprecated)->not->toBe($canonical);
    }
});

it('FAILS CLOSED — an unknown or partial code is never mapped onto Telkomas', function () {
    // Substring and prefix must never widen a match.
    expect(BranchCodeAlias::canonicalize('TKM'))->toBe('TKM')
        ->and(BranchCodeAlias::canonicalize('TKM1-EXTRA'))->toBe('TKM1-EXTRA')
        ->and(BranchCodeAlias::canonicalize('XXXX'))->toBe('XXXX')
        ->and(BranchCodeAlias::canonicalize('LDK2'))->toBe('LDK2')
        ->and(BranchCodeAlias::canonicalize(''))->toBeNull()
        ->and(BranchCodeAlias::canonicalize(null))->toBeNull();
});

it('reports every deprecated spelling that names the same branch', function () {
    expect(BranchCodeAlias::historicalAliasesFor('TLK1'))->toBe(['TKM1'])
        ->and(BranchCodeAlias::equivalentCodes('TKM1'))->toBe(['TLK1', 'TKM1'])
        ->and(BranchCodeAlias::equivalentCodes('TLK1'))->toBe(['TLK1', 'TKM1'])
        ->and(BranchCodeAlias::equivalentCodes('LDK2'))->toBe(['LDK2']);
});

/*
|--------------------------------------------------------------------------
| Nomor RM composition and parsing
|--------------------------------------------------------------------------
*/

it('issues NEW Telkomas medical record numbers under the canonical code', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    $composed = $numbers->compose('TLK1', 2026, '0001');

    expect($composed)->toBe('DG-TLK1-2026-0001')
        ->and($composed)->not->toContain('TKM1');
});

it('reports the CANONICAL branch for a medical record number issued under the deprecated code', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    expect($numbers->branchCodeFrom('DG-TKM1-2024-9985'))->toBe('TLK1')
        ->and($numbers->branchCodeFrom('DG-TLK1-2024-9985'))->toBe('TLK1')
        // The literal spelling is still available where the historical fact matters.
        ->and($numbers->literalBranchCodeFrom('DG-TKM1-2024-9985'))->toBe('TKM1');
});

it('rewrites ONLY the branch segment — year and manual sequence survive verbatim', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    expect($numbers->canonicalizeBranchCode('DG-TKM1-2024-9985'))->toBe('DG-TLK1-2024-9985')
        // Leading zeros and hyphenated sequences are preserved, not renumbered.
        ->and($numbers->canonicalizeBranchCode('DG-TKM1-2019-0001'))->toBe('DG-TLK1-2019-0001')
        ->and($numbers->canonicalizeBranchCode('DG-TKM1-2024-12-B'))->toBe('DG-TLK1-2024-12-B')
        // Already canonical is a no-op, so the transform is idempotent.
        ->and($numbers->canonicalizeBranchCode('DG-TLK1-2024-9985'))->toBe('DG-TLK1-2024-9985');
});

it('refuses to transform anything that is not a canonical Nomor RM', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    // A blind string replacement would happily mangle every one of these.
    expect($numbers->canonicalizeBranchCode('DG-TKM1'))->toBeNull()
        ->and($numbers->canonicalizeBranchCode('TKM1-123'))->toBeNull()
        ->and($numbers->canonicalizeBranchCode('MRN-12345678'))->toBeNull()
        ->and($numbers->canonicalizeBranchCode(null))->toBeNull();
});

it('matches the branch code as a WHOLE token, never as a substring', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    // `XXTKM1YY` is a structurally valid branch code that merely CONTAINS the
    // deprecated token. It belongs to no known branch, so it is returned
    // untouched and then fails closed at branch lookup. A `str_replace` would
    // have silently rewritten it to `XXTLK1YY` — inventing an identifier.
    expect($numbers->canonicalizeBranchCode('DG-XXTKM1YY-2024-1'))->toBe('DG-XXTKM1YY-2024-1')
        ->and($numbers->branchCodeFrom('DG-XXTKM1YY-2024-1'))->toBe('XXTKM1YY')
        ->and($numbers->canonicalizeBranchCode('DG-TKM-2024-1'))->toBe('DG-TKM-2024-1');
});

it('never rewrites a TKM1 appearing inside the manual sequence', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    // The branch is LDK2; the manual segment merely contains the letters.
    expect($numbers->canonicalizeBranchCode('DG-LDK2-2024-TKM1'))->toBe('DG-LDK2-2024-TKM1');
});

/*
|--------------------------------------------------------------------------
| RM-derived branch resolution (Legacy RME AND Legacy Odontogram)
|--------------------------------------------------------------------------
*/

it('resolves a deprecated-code Nomor RM to the SAME Telkomas branch identity', function () {
    $branch = legacyRmeBranch('TLK1');

    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-TKM1-2024-9985',
        'date_of_birth' => '1990-01-01',
    ]);

    $resolution = app(LegacyRmeBranchResolver::class)->resolveForPatient($patient);

    expect($resolution->resolved)->toBeTrue()
        ->and($resolution->branchId)->toBe((int) $branch->id)
        // The RESOLVED code is canonical, so allowlists compare one spelling.
        ->and($resolution->branchCode)->toBe('TLK1');
});

it('resolves a canonical Nomor RM to the same branch as the deprecated one', function () {
    $branch = legacyRmeBranch('TLK1');

    $historical = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-TKM1-2024-1111',
        'date_of_birth' => '1990-01-01',
    ]);
    $canonical = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-TLK1-2024-2222',
        'date_of_birth' => '1990-01-01',
    ]);

    $resolver = app(LegacyRmeBranchResolver::class);

    expect($resolver->resolveForPatient($historical)->branchId)
        ->toBe($resolver->resolveForPatient($canonical)->branchId)
        ->toBe((int) $branch->id);
});

it('FAILS CLOSED for an unknown branch code rather than falling back to Telkomas', function () {
    legacyRmeBranch('TLK1');

    $patient = Patient::factory()->create([
        'medical_record_number' => 'DG-ZZZ9-2024-0001',
        'date_of_birth' => '1990-01-01',
    ]);

    $resolution = app(LegacyRmeBranchResolver::class)->resolveForPatient($patient);

    expect($resolution->resolved)->toBeFalse()
        ->and($resolution->code)->toBe(LegacyRmeBranchResolution::CODE_BRANCH_NOT_FOUND);
});

/*
|--------------------------------------------------------------------------
| Historical Nomor RM search compatibility (the patient's old card)
|--------------------------------------------------------------------------
*/

it('finds a migrated patient when staff type the Nomor RM printed on their OLD card', function () {
    $branch = legacyRmeBranch('TLK1');

    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        // Already migrated to the canonical spelling.
        'medical_record_number' => 'DG-TLK1-2024-9985',
        'date_of_birth' => '1990-01-01',
    ]);

    // The operator types what the card says — the deprecated spelling.
    $payload = app(CrossBranchPatientLookupService::class)
        ->lookupByMedicalRecordNumberAcrossBranches('DG-TKM1-2024-9985');

    expect($payload['searched'])->toBeTrue()
        ->and($payload['match_type'])->toBe('exact')
        ->and($payload['results'])->toHaveCount(1)
        ->and($payload['results'][0]['medical_record_number'])->toBe('DG-TLK1-2024-9985');

    // ...and it is the same patient, not a look-alike.
    expect(Patient::query()->where('medical_record_number', 'DG-TLK1-2024-9985')->value('id'))
        ->toBe($patient->id);
});

it('finds a migrated patient from the OLD number on every RM lookup surface', function () {
    $branch = legacyRmeBranch('TLK1');

    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-TLK1-2024-9985',
        'date_of_birth' => '1990-01-01',
        'is_active' => true,
    ]);

    $old = 'DG-TKM1-2024-9985';

    // 1. Legacy odontogram intake — the operator is holding the paper chart.
    $odontogram = app(LegacyOdontogramPatientRepositoryInterface::class)
        ->searchByMedicalRecordNumber(null, $old, 10);

    // 2. Legacy RME identity resolution — this one BINDS a document to a patient.
    $identity = app(LegacyRmePatientResolutionAuditService::class)
        ->resolveIdentity($old);

    // 3. The paginated patient directory, and 4. the selectable combobox.
    $repository = app(PatientRepositoryInterface::class);
    $directory = $repository->paginate(['search' => $old], 15);
    $combobox = $repository->searchSelectable([(int) $branch->id], $old, 15);

    expect($odontogram->pluck('id')->all())->toBe([$patient->id])
        ->and($identity['bindable'])->toBeTrue()
        ->and($identity['matches'])->toHaveCount(1)
        ->and((int) $identity['matches'][0]['patient_id'])->toBe($patient->id)
        ->and($directory->pluck('id')->all())->toBe([$patient->id])
        ->and($combobox->pluck('id')->all())->toBe([$patient->id]);
});

it('does not widen an unrelated search term into someone else', function () {
    $branch = legacyRmeBranch('TLK1');

    Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-TLK1-2024-9985',
        'date_of_birth' => '1990-01-01',
    ]);

    // A different manual sequence must not match, deprecated spelling or not.
    $miss = app(LegacyOdontogramPatientRepositoryInterface::class)
        ->searchByMedicalRecordNumber(null, 'DG-TKM1-2024-9986', 10);

    // A different branch entirely must not match either.
    $otherBranch = app(LegacyOdontogramPatientRepositoryInterface::class)
        ->searchByMedicalRecordNumber(null, 'DG-LDK2-2024-9985', 10);

    expect($miss)->toHaveCount(0)
        ->and($otherBranch)->toHaveCount(0);
});

it('binds a legacy document whose printed Nomor RM predates the branch-code revision', function () {
    $branch = legacyRmeBranch('TLK1');

    // The patient's stored number has been migrated to the canonical spelling.
    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-TLK1-2024-9985',
        'date_of_birth' => '1990-01-01',
    ]);

    // The operator transcribes what is PRINTED on the paper document, which was
    // issued under the deprecated code. This is the gate that decides whether a
    // document belongs to the selected patient, and it also runs again at
    // publish time against whatever the master data says by then — so a rename
    // must not turn an already-staged document into a "wrong patient" refusal.
    $binding = app(LegacyRmeSourcePatientBindingService::class)
        ->bind('DG-TKM1-2024-9985', $patient);

    expect($binding->bound)->toBeTrue()
        ->and($binding->patientId)->toBe($patient->id)
        // The evidence keeps the document's own spelling; only the MATCH widened.
        ->and($binding->normalizedSourceRm)->toBe('DG-TKM1-2024-9985')
        ->and($binding->branchCode)->toBe('TLK1');
});

it('still refuses a legacy document that names a different patient', function () {
    $branch = legacyRmeBranch('TLK1');

    $selected = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-TLK1-2024-9985',
        'date_of_birth' => '1990-01-01',
    ]);
    Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-TLK1-2024-7777',
        'date_of_birth' => '1990-01-01',
    ]);

    // Aliasing widens spellings of ONE number, never the set of patients.
    $binding = app(LegacyRmeSourcePatientBindingService::class)
        ->bind('DG-TKM1-2024-7777', $selected);

    expect($binding->bound)->toBeFalse();
});

it('does not invent variants for an unrelated branch or a non-canonical value', function () {
    $numbers = app(PatientMedicalRecordNumberService::class);

    expect($numbers->equivalentNumbers('DG-LDK2-2024-0007'))->toBe(['DG-LDK2-2024-0007'])
        ->and($numbers->equivalentNumbers('9985'))->toBe(['9985'])
        ->and($numbers->equivalentNumbers(''))->toBe([]);
});

it('never creates a second patient row for the historical spelling', function () {
    $branch = legacyRmeBranch('TLK1');

    Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-TLK1-2024-9985',
        'date_of_birth' => '1990-01-01',
    ]);

    app(CrossBranchPatientLookupService::class)
        ->lookupByMedicalRecordNumberAcrossBranches('DG-TKM1-2024-9985');

    expect(Patient::withTrashed()->where('medical_record_number', 'like', 'DG-T%-2024-9985')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Master data registry
|--------------------------------------------------------------------------
*/

it('seeds Cabang Telkomas under the canonical code', function () {
    test()->seed(BranchSeeder::class);
    test()->seed(RmeBranchSeeder::class);

    $telkomas = Branch::query()->where('code', 'TLK1')->get();

    expect($telkomas)->toHaveCount(1)
        ->and($telkomas->first()->name)->toBe('Cabang Telkomas')
        ->and(Branch::withTrashed()->where('code', 'TKM1')->count())->toBe(0)
        ->and(RmeBranchSeeder::CANONICAL_RME_BRANCHES)->not->toHaveKey('TKM1')
        ->and(RmeBranchSeeder::CANONICAL_RME_BRANCHES)->toHaveKey('TLK1');
});

it('NEVER duplicates Telkomas when the branch still holds its deprecated code', function () {
    test()->seed(BranchSeeder::class);

    // A deployment that has not been migrated yet — exactly the state that made
    // this a latent production hazard.
    $existing = Branch::query()->create([
        'code' => 'TKM1',
        'name' => 'Cabang Telkomas',
        'is_active' => true,
        'is_rme_enabled' => true,
        'is_inventory_enabled' => false,
    ]);

    test()->seed(RmeBranchSeeder::class);
    test()->seed(RmeBranchSeeder::class);

    $telkomasRows = Branch::withTrashed()
        ->whereIn('code', ['TKM1', 'TLK1'])
        ->get();

    expect($telkomasRows)->toHaveCount(1)
        ->and((int) $telkomasRows->first()->id)->toBe((int) $existing->id);
});

/*
|--------------------------------------------------------------------------
| Rollout admission — a stale environment must not lock the branch out
|--------------------------------------------------------------------------
*/

it('canonicalizes rollout allowlist tokens declared with the deprecated code', function () {
    // The REAL config file is re-evaluated against a deployment whose
    // environment still names the deprecated code — the production state this
    // revision found.
    $build = static function (string $admitted, string $approved, string $pilot): array {
        putenv("LEGACY_RME_ADMITTED_BRANCH_CODES={$admitted}");
        putenv("LEGACY_RME_ADMISSION_APPROVED_BRANCH_CODES={$approved}");
        putenv("LEGACY_RME_PILOT_BRANCH_CODE={$pilot}");

        try {
            return require base_path('config/legacy_rme_rollout.php');
        } finally {
            putenv('LEGACY_RME_ADMITTED_BRANCH_CODES');
            putenv('LEGACY_RME_ADMISSION_APPROVED_BRANCH_CODES');
            putenv('LEGACY_RME_PILOT_BRANCH_CODE');
        }
    };

    $stale = $build('TKM1,LDK2,ATG3,SUN4', 'TKM1,LDK2,ATG3,SUN4', 'TKM1');

    expect($stale['admission']['admitted_branch_codes'])->toBe(['TLK1', 'LDK2', 'ATG3', 'SUN4'])
        ->and($stale['admission']['approved_branch_codes'])->toBe(['TLK1', 'LDK2', 'ATG3', 'SUN4'])
        ->and($stale['pilot_scope']['branch_code'])->toBe('TLK1')
        // Admitted must stay covered by the approval — canonicalizing BOTH sides
        // is what keeps that invariant true.
        ->and(array_diff(
            $stale['admission']['admitted_branch_codes'],
            $stale['admission']['approved_branch_codes'],
        ))->toBe([]);

    // A deployment already declaring the canonical code is unchanged, and a
    // deployment declaring both spellings collapses to one token rather than
    // admitting the same branch twice.
    $canonical = $build('TLK1,LDK2', 'TLK1,LDK2', 'TLK1');
    $mixed = $build('TLK1,TKM1', 'TLK1,TKM1', 'TLK1');

    expect($canonical['admission']['admitted_branch_codes'])->toBe(['TLK1', 'LDK2'])
        ->and($mixed['admission']['admitted_branch_codes'])->toBe(['TLK1']);

    // An unknown token is carried through untouched and still matches nothing.
    $unknown = $build('ZZZ9', 'ZZZ9', '');

    expect($unknown['admission']['admitted_branch_codes'])->toBe(['ZZZ9'])
        ->and($unknown['pilot_scope']['branch_code'])->toBe('');
});

it('ADMITS a Telkomas patient whose Nomor RM still carries the deprecated code', function () {
    $branch = legacyRmeBranch('TLK1');
    legacyRmeArchiveFlag(true);
    legacyRmeAdmittedBranches(['TLK1']);

    $patient = Patient::factory()->create([
        'branch_id' => $branch->id,
        'medical_record_number' => 'DG-TKM1-2024-9985',
        'date_of_birth' => '1990-01-01',
    ]);

    $resolution = app(LegacyRmeBranchResolver::class)->resolveForPatient($patient);
    $decision = app(LegacyRmeBranchAdmissionService::class)->decide($resolution);

    expect($resolution->resolved)->toBeTrue()
        ->and($decision->admitted)->toBeTrue()
        ->and($decision->branchCode)->toBe('TLK1');
});

it('still DENIES a branch that is not admitted, deprecated code or not', function () {
    legacyRmeBranch('TLK1');
    legacyRmeArchiveFlag(true);
    legacyRmeAdmittedBranches(['LDK2']);

    $patient = Patient::factory()->create([
        'branch_id' => Branch::query()->where('code', 'TLK1')->value('id'),
        'medical_record_number' => 'DG-TKM1-2024-9985',
        'date_of_birth' => '1990-01-01',
    ]);

    $resolution = app(LegacyRmeBranchResolver::class)->resolveForPatient($patient);
    $decision = app(LegacyRmeBranchAdmissionService::class)->decide($resolution);

    expect($decision->admitted)->toBeFalse();
});
